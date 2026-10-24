<?php

declare(strict_types=1);

namespace Tests\Feature\Agent\GgrSettlement;

use App\Enums\Agent\AgentGroupTypeEnum;
use App\Enums\Agent\GgrSettlementCycleEnum;
use App\Enums\GgrPayoutStatusEnum;
use App\Enums\QueueJobKeyEnum;
use App\Enums\QueueJobRunSourceEnum;
use App\Jobs\GgrSettleJob;
use App\Models\Agent;
use App\Models\AgentGroup;
use App\Models\Game;
use App\Models\GgrCondition;
use App\Models\GgrConditionTier;
use App\Models\GgrPlan;
use App\Models\GgrSettlement;
use App\Models\GgrVendorSetting;
use App\Models\GgrVendorSettingDetail;
use App\Models\Member;
use App\Models\QueueJobRun;
use App\Services\Agent\GgrSettlement\PeriodResolver;
use App\Services\Agent\GgrSettlement\SettlementRunner;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * GGR 結算引擎（批次六）：工單 4121/4123/4124 定案公式與邊界的落地驗證。
 *
 * 期間固定 2026-08-20（GMT+8 業務日）＝ UTC [08-19 16:00, 08-20 16:00)。
 */
class EngineTest extends TestCase
{
    private const string PERIOD = '2026-08-20';

    /** 期間內的 UTC 時點（造 TBR / transaction_logs 用） */
    private const string IN_WINDOW_UTC = '2026-08-20 04:00:00';

    private Agent $general;

    private AgentGroup $group;

    private GgrCondition $condition;

    private GgrVendorSetting $vendorSetting;

    private int $betRecordSeq = 0;

    /** @var array<int, int> vendor_id => games.id */
    private array $gameIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->general = $this->createAgentAndDomain();
        $this->group = $this->makeGroup('engine group');

        // 預設條件：單階、全 0 門檻＋user_count 0＝無條件達成、應得 50%
        $this->condition = $this->makeCondition([
            ['user_count' => 0, 'percentage' => '50.00'],
        ]);

        // 工單案例共用遊戲商階梯：PP ≥100→50%/≥200→70%、PG ≥1→100%、Jili ≥500→80%/≥2000→90%
        $this->vendorSetting = $this->makeVendorSetting([
            901 => [['threshold' => '100', 'weight' => '50'], ['threshold' => '200', 'weight' => '70']],
            902 => [['threshold' => '1', 'weight' => '100']],
            903 => [['threshold' => '500', 'weight' => '80'], ['threshold' => '2000', 'weight' => '90']],
        ]);
    }

    /** 工單 4121/4123 案例 A（正）B（負）C（正翻負）D（剛好 0）：全數字逐一斷言 */
    public function test_formula_cases_from_ticket(): void
    {
        $plan = $this->makePlan();

        // 案例明細（GGR ＝ −win_lose，工單寫的是 GGR 值）
        $cases = [
            'A' => ['bets' => [[901, 3000], [901, 50], [902, 800], [903, -600]],
                'ggr' => '2420.00', 'final' => '73.60', 'admin_fee' => '14.40', 'handling' => '200.00', 'payable' => '73.60'],
            'B' => ['bets' => [[901, -4000], [902, 900], [903, 700]],
                'ggr' => '-1340.00', 'final' => '-276.00', 'admin_fee' => '0.00', 'handling' => '0.00', 'payable' => '0.00'],
            'C' => ['bets' => [[901, 400], [902, 20]],
                'ggr' => '300.00', 'final' => '-30.00', 'admin_fee' => '0.00', 'handling' => '0.00', 'payable' => '0.00'],
            'D' => ['bets' => [[901, 600], [902, 80]],
                'ggr' => '500.00', 'final' => '0.00', 'admin_fee' => '0.00', 'handling' => '0.00', 'payable' => '0.00'],
        ];

        $agents = [];
        foreach ($cases as $name => $case) {
            $agent = $this->makeLineAgent();
            $member = $this->makeMember($agent);
            $agents[$name] = $agent;

            foreach ($case['bets'] as [$vendorId, $ggr]) {
                $this->insertBet($member->id, $vendorId, (string) (-$ggr));
            }

            // 該期 SR15「彩金與活動」＝ 1,000 → 獎金費用 500（四案例共用）
            $this->insertTransactionLog($member->id, 13, '1000');
        }

        $stats = $this->runDaily($plan);
        $this->assertSame('settled', $stats['mode']);
        $this->assertSame(4, $stats['settled']);

        foreach ($cases as $name => $case) {
            $row = GgrSettlement::query()
                ->where('plan_id', $plan->id)
                ->where('agent_id', $agents[$name]->id)
                ->first();

            $this->assertNotNull($row, "case {$name} 應有結算列");
            $this->assertSame($case['ggr'], $row->ggr_amount, "case {$name} 加權總和");
            $this->assertSame($case['final'], $row->final_amount, "case {$name} 代理實得");
            $this->assertSame($case['payable'], $row->payable_amount, "case {$name} 實發金額");
            $this->assertSame($case['handling'], $row->handling_fee, "case {$name} 手續費");
            $this->assertSame($case['admin_fee'], $row->calc_snapshot['admin_fee'], "case {$name} 管理費");
            $this->assertSame('500.0000000000', $row->calc_snapshot['bonus']['fee'], "case {$name} 獎金費用");
            $this->assertSame('50.00', $row->condition_rate);
            $this->assertSame(GgrPayoutStatusEnum::UNPAID->value, (int) $row->payout_status);
        }
    }

    /** 4123 邊界 4-8/4-9/4-16：加權後雙門檻看絕對值、截斷保留正負、低於下限不計 */
    public function test_weighted_bounds_absolute(): void
    {
        $vendorSetting = $this->makeVendorSetting([
            901 => [
                ['threshold' => '100', 'weight' => '50', 'min_winlose' => 100, 'max_ggr' => 150],
                ['threshold' => '200', 'weight' => '70', 'min_winlose' => 100, 'max_ggr' => 150],
            ],
        ]);
        $plan = $this->makePlan(['downline_vendor_setting_id' => $vendorSetting->id]);

        $agent = $this->makeLineAgent();
        $member = $this->makeMember($agent);
        $this->insertBet($member->id, 901, '250');   // GGR −250 → 落 ≥200/70% → −175 → 截 −150
        $this->insertBet($member->id, 901, '-3000'); // GGR +3000 → +2100 → 截 +150
        $this->insertBet($member->id, 901, '120');   // GGR −120 → −60 → |60| < 100 不計

        $this->runDaily($plan);

        $row = GgrSettlement::query()->where('plan_id', $plan->id)->where('agent_id', $agent->id)->first();
        $this->assertSame('0.00', $row->ggr_amount); // −150 ＋ 150
        $this->assertSame(2, $row->calc_snapshot['vendors'][0]['counted']);
    }

    /** 決議③邊界 4-17：回收 > 彩金基數取 0；SR15 五組成（泥碼截止後不計）逐項驗 */
    public function test_bonus_composition_and_recycle_floor(): void
    {
        $plan = $this->makePlan();
        $agent = $this->makeLineAgent();
        $member = $this->makeMember($agent);

        $this->insertTransactionLog($member->id, 13, '500');       // point 500
        $this->insertWalletLog($member->id, '20');                 // wallet_point 20
        $this->insertSbr($member->id, '100', '50');                // jackpot 100（rolling_chip 50 在截止後不計）
        $this->insertLottery($agent->id, 6, '30');                 // lottery 30
        $this->insertRecycle($member->id, '800');                  // recycle 800 > 650 → base 0

        // 造一筆 GGR 讓判斷值為正，驗證獎金費用 0 時公式照走
        $this->insertBet($member->id, 902, '-1000'); // GGR +1000 × 100%

        $this->runDaily($plan);

        $row = GgrSettlement::query()->where('plan_id', $plan->id)->where('agent_id', $agent->id)->first();
        $bonus = $row->calc_snapshot['bonus'];
        $this->assertSame('500.0000', $bonus['point']);
        $this->assertSame('20.0000', $bonus['wallet_point']);
        $this->assertSame('100.0000', $bonus['jackpot']);
        $this->assertSame('30.0000', $bonus['lottery']);
        $this->assertSame('800.0000', $bonus['recycle']);
        $this->assertSame('0', $bonus['base']);
        $this->assertSame('0.0000000000', $bonus['fee']);
        // 判斷值 +1000 > 0：×50%×30%＝150 → −管理費 7.5 − 手續費 200 ＝ −57.5
        $this->assertSame('-57.50', $row->final_amount);
    }

    /** 發揚遞延：工單測試步驟 −552 → +300（發 0 餘 −252）→ +1000（發 748 餘 0）；發揚關期末歸 0 */
    public function test_carry_chain(): void
    {
        // fees/獎金全 0、百分比全 100 → final ＝ 加權總和
        $planOn = $this->makePlan([
            'carry_negative' => 1,
            'admin_fee_rate' => '0.00', 'handling_fee' => '0.00',
            'ggr_rate' => '100.00', 'bonus_rate' => '0.00',
            'downline_condition_id' => $this->makeCondition([['user_count' => 0, 'percentage' => '100.00']])->id,
        ]);
        $agent = $this->makeLineAgent();
        $member = $this->makeMember($agent);

        $expects = [
            ['2026-08-20', '552', '-552.00', '0.00', '0.00', '-552.00'],
            ['2026-08-21', '-300', '300.00', '0.00', '-552.00', '-252.00'],
            ['2026-08-22', '-1000', '1000.00', '748.00', '-252.00', '0.00'],
        ];

        foreach ($expects as [$date, $winLose, $final, $payable, $carryIn, $carryOut]) {
            $this->insertBet($member->id, 902, $winLose, $this->utcInDay($date));
            $this->runDaily($planOn, $date);

            $row = GgrSettlement::query()
                ->where('plan_id', $planOn->id)->where('agent_id', $agent->id)
                ->where('period_start', $date)->first();
            $this->assertSame($final, $row->final_amount, "{$date} final");
            $this->assertSame($payable, $row->payable_amount, "{$date} payable");
            $this->assertSame($carryIn, $row->carry_in_amount, "{$date} carry_in");
            $this->assertSame($carryOut, $row->carry_out_amount, "{$date} carry_out");
        }

        // 發揚關：期末負數歸 0（不遞延），另一組 group/plan/agent
        $groupOff = $this->makeGroup('carry off group');
        $planOff = $this->makePlan([
            'agent_group_id' => $groupOff->id,
            'carry_negative' => 0,
            'admin_fee_rate' => '0.00', 'handling_fee' => '0.00',
            'ggr_rate' => '100.00', 'bonus_rate' => '0.00',
            'downline_condition_id' => $this->makeCondition([['user_count' => 0, 'percentage' => '100.00']])->id,
        ]);
        $agentOff = $this->makeLineAgent($groupOff->id);
        $memberOff = $this->makeMember($agentOff);
        $this->insertBet($memberOff->id, 902, '552');

        $this->runDaily($planOff);

        $row = GgrSettlement::query()->where('plan_id', $planOff->id)->where('agent_id', $agentOff->id)->first();
        $this->assertSame('-552.00', $row->final_amount);
        $this->assertSame('0.00', $row->payable_amount);
        $this->assertSame('0.00', $row->carry_out_amount);
    }

    /** 4124 邊界 8-1：一階都沒達成不產生任何結算紀錄；需求 2：人數門檻算「各自達標」的直屬會員 */
    public function test_no_tier_reached_creates_no_record(): void
    {
        $condition = $this->makeCondition([
            ['user_count' => 2, 'deposit_amount' => '100.00', 'percentage' => '30.00'],
        ]);
        $plan = $this->makePlan(['downline_condition_id' => $condition->id]);

        $agent = $this->makeLineAgent();
        $m1 = $this->makeMember($agent);
        $m2 = $this->makeMember($agent);
        $this->insertDeposit($m1->id, '1000');  // 只有 1 位達標（另一位 50 未達）
        $this->insertDeposit($m2->id, '50');
        $this->insertBet($m1->id, 902, '-500');

        $stats = $this->runDaily($plan);

        $this->assertSame(0, $stats['settled']);
        $this->assertSame(0, GgrSettlement::query()->where('plan_id', $plan->id)->count());
        // 完成標記仍要寫（該期已處理過）
        $this->assertTrue(QueueJobRun::isCompleted(QueueJobKeyEnum::GGR_SETTLEMENT, $plan->id, self::PERIOD . ' 00:00:00'));
    }

    /** 4124 需求 3：OR 任一達成／AND 全部達成 */
    public function test_condition_logic_or_and(): void
    {
        // 會員：存款 100、有效流水 0
        $agent = $this->makeLineAgent();
        $member = $this->makeMember($agent);
        $this->insertDeposit($member->id, '100');
        $this->insertBet($member->id, 902, '-500'); // 有 GGR 才有數字，valid_bet 0

        $tierSpec = ['user_count' => 1, 'deposit_amount' => '100.00', 'valid_bet_amount' => '5000.00', 'percentage' => '30.00'];

        $planOr = $this->makePlan([
            'downline_condition_id' => $this->makeCondition([$tierSpec], GgrCondition::LOGIC_TYPE_OR)->id,
        ]);
        $this->assertSame(1, $this->runDaily($planOr)['settled'], 'OR：存款一項達成即合格');

        $groupAnd = $this->makeGroup('and group');
        $agentAnd = $this->makeLineAgent($groupAnd->id);
        $memberAnd = $this->makeMember($agentAnd);
        $this->insertDeposit($memberAnd->id, '100');
        $this->insertBet($memberAnd->id, 902, '-500');

        $planAnd = $this->makePlan([
            'agent_group_id' => $groupAnd->id,
            'downline_condition_id' => $this->makeCondition([$tierSpec], GgrCondition::LOGIC_TYPE_AND)->id,
        ]);
        $this->assertSame(0, $this->runDaily($planAnd)['settled'], 'AND：有效流水未達 5000 不合格');
    }

    /** 防重跑：完成標記跳過；PAID 列重跑不被覆蓋 */
    public function test_rerun_protection(): void
    {
        $plan = $this->makePlan();
        $agent = $this->makeLineAgent();
        $member = $this->makeMember($agent);
        $this->insertBet($member->id, 902, '-1000');

        $this->assertSame('settled', $this->runDaily($plan)['mode']);
        $this->assertSame('skipped', $this->runDaily($plan)['mode'], '完成標記存在即跳過');

        // 標記重置後重跑：UNPAID 覆蓋、PAID 保護
        $row = GgrSettlement::query()->where('plan_id', $plan->id)->where('agent_id', $agent->id)->first();
        $row->forceFill(['payout_status' => GgrPayoutStatusEnum::PAID->value, 'paid_at' => now()])->save();
        $originalFinal = $row->final_amount;

        $this->insertBet($member->id, 902, '-9999'); // 數字若被重算必然改變
        QueueJobRun::resetMarks(QueueJobKeyEnum::GGR_SETTLEMENT, [self::PERIOD . ' 00:00:00'], [$plan->id]);

        $this->assertSame('settled', $this->runDaily($plan)['mode']);
        $row->refresh();
        $this->assertSame($originalFinal, $row->final_amount, 'PAID 列不可被重跑洗掉');
        $this->assertSame(GgrPayoutStatusEnum::PAID->value, (int) $row->payout_status);
    }

    /** dry-run：試算不落庫、不寫完成標記 */
    public function test_dry_run_writes_nothing(): void
    {
        $plan = $this->makePlan();
        $agent = $this->makeLineAgent();
        $member = $this->makeMember($agent);
        $this->insertBet($member->id, 902, '-1000');

        $stats = app(SettlementRunner::class)->runPlanPeriod(
            plan: $plan,
            periodStart: Carbon::parse(self::PERIOD),
            periodEnd: Carbon::parse(self::PERIOD),
            cycle: GgrSettlementCycleEnum::DAILY,
            source: QueueJobRunSourceEnum::MANUAL,
            dryRun: true,
            skipIfCompleted: false,
        );

        $this->assertSame('dry-run', $stats['mode']);
        $this->assertSame(1, $stats['settled']);
        $this->assertSame(0, GgrSettlement::query()->where('plan_id', $plan->id)->count());
        $this->assertFalse(QueueJobRun::isCompleted(QueueJobKeyEnum::GGR_SETTLEMENT, $plan->id, self::PERIOD . ' 00:00:00'));
    }

    /** 週期切換：當期照原週期跑完、生效日起用新週期、結清後 promote */
    public function test_period_resolver_cycle_switch(): void
    {
        $resolver = app(PeriodResolver::class);

        $plan = $this->makePlan([
            'settlement_cycle' => GgrSettlementCycleEnum::MONTHLY->value,
            'pending_settlement_cycle' => GgrSettlementCycleEnum::DAILY->value,
            'pending_cycle_from' => '2026-10-01',
            'start_date' => '2026-08-01',
        ]);

        // 8 月已結算過
        $this->insertSettlementRow($plan, $this->makeLineAgent()->id, '2026-08-01', '2026-08-31');

        $periods = $resolver->pendingPeriods($plan, Carbon::parse('2026-10-03'));

        $this->assertCount(3, $periods);
        $this->assertSame(['2026-09-01', '2026-09-30', GgrSettlementCycleEnum::MONTHLY],
            [$periods[0]['start']->toDateString(), $periods[0]['end']->toDateString(), $periods[0]['cycle']]);
        $this->assertSame(['2026-10-01', '2026-10-01', GgrSettlementCycleEnum::DAILY],
            [$periods[1]['start']->toDateString(), $periods[1]['end']->toDateString(), $periods[1]['cycle']]);
        $this->assertSame(['2026-10-02', '2026-10-02', GgrSettlementCycleEnum::DAILY],
            [$periods[2]['start']->toDateString(), $periods[2]['end']->toDateString(), $periods[2]['cycle']]);

        // 9 月期未結清前不 promote
        $resolver->promoteIfDue($plan, Carbon::parse('2026-10-03'));
        $this->assertSame(GgrSettlementCycleEnum::MONTHLY->value, (int) $plan->fresh()->settlement_cycle);

        // 結清至生效日後 promote
        $agentId = $this->makeLineAgent()->id;
        $this->insertSettlementRow($plan, $agentId, '2026-09-01', '2026-09-30');
        $resolver->promoteIfDue($plan, Carbon::parse('2026-10-03'));

        $fresh = $plan->fresh();
        $this->assertSame(GgrSettlementCycleEnum::DAILY->value, (int) $fresh->settlement_cycle);
        $this->assertNull($fresh->pending_settlement_cycle);
        $this->assertNull($fresh->pending_cycle_from);
    }

    /** 4124 需求 4：僅計算活躍用戶（7006 充值＋7007 有效流水皆達成才算活躍） */
    public function test_active_user_only_filter(): void
    {
        DB::table('agent_setting_defines')->updateOrInsert(
            ['setting_id' => 7006],
            ['parent' => 5000, 'language_text_code' => 'active_member_deposit_amount', 'type' => 3,
                'default_value' => '0', 'data_type' => 'string', 'memo' => '', 'agent_edit' => 0, 'created_by' => 1],
        );
        DB::table('agent_setting_defines')->updateOrInsert(
            ['setting_id' => 7007],
            ['parent' => 5000, 'language_text_code' => 'active_member_valid_bet_amount', 'type' => 3,
                'default_value' => '0', 'data_type' => 'string', 'memo' => '', 'agent_edit' => 0, 'created_by' => 1],
        );
        DB::table('agent_customized_settings')->insert([
            ['agent_group_id' => $this->group->id, 'setting_key' => 7006, 'sub_category' => 0, 'value' => '100', 'memo' => ''],
            ['agent_group_id' => $this->group->id, 'setting_key' => 7007, 'sub_category' => 0, 'value' => '50', 'memo' => ''],
        ]);

        $condition = $this->makeCondition(
            [['user_count' => 2, 'percentage' => '30.00']],
            GgrCondition::LOGIC_TYPE_OR,
            activeUserOnly: 1,
        );
        $plan = $this->makePlan(['downline_condition_id' => $condition->id]);

        $agent = $this->makeLineAgent();
        // m1 充值 150＋流水 60 → 活躍；m2 充值 150 無流水 → 不活躍（7007 未達）
        $m1 = $this->makeMember($agent);
        $m2 = $this->makeMember($agent);
        $this->insertDeposit($m1->id, '150');
        $this->insertBet($m1->id, 902, '-10', validBet: '60');
        $this->insertDeposit($m2->id, '150');

        // 活躍僅 1 位 < user_count 2 → 不產生紀錄
        $this->assertSame(0, $this->runDaily($plan)['settled']);
    }

    /** 結算對象限方案總代 hierarchy 線內：同個性化組別、但掛在別條線的代理不結算 */
    public function test_agent_outside_general_line_not_settled(): void
    {
        $plan = $this->makePlan();

        $inLine = $this->makeLineAgent();
        $this->insertBet($this->makeMember($inLine)->id, 902, '-1000');

        // 同群組（個性化組別全站共用），hierarchy 不在本總代之下
        $outsider = $this->makeLineAgent();
        $outsider->hierarchy_path = '/9999999/' . $outsider->id;
        $outsider->save();
        $this->insertBet($this->makeMember($outsider)->id, 902, '-1000');

        $stats = $this->runDaily($plan);

        $this->assertSame(1, $stats['settled']);
        $this->assertSame(1, GgrSettlement::query()->where('plan_id', $plan->id)->where('agent_id', $inLine->id)->count());
        $this->assertSame(0, GgrSettlement::query()->where('plan_id', $plan->id)->where('agent_id', $outsider->id)->count());
    }

    /** 4124：user_count 0＝不限人數，金額門檻仍須至少 1 位會員達成；旗下 0 位會員不達任何階（8-7） */
    public function test_user_count_zero_still_requires_one_qualified_member(): void
    {
        $condition = $this->makeCondition([
            ['user_count' => 0, 'deposit_amount' => '100000.00', 'percentage' => '80.00'],
        ]);
        $plan = $this->makePlan(['downline_condition_id' => $condition->id]);

        // 有會員、有 GGR，但無人存款達 100000 → 不得因 user_count=0 直接命中 80%
        $agent = $this->makeLineAgent();
        $member = $this->makeMember($agent);
        $this->insertBet($member->id, 902, '-1000');
        $this->assertSame(0, $this->runDaily($plan)['settled']);

        // 存款達標後命中
        $this->insertDeposit($member->id, '100000');
        QueueJobRun::resetMarks(QueueJobKeyEnum::GGR_SETTLEMENT, [self::PERIOD . ' 00:00:00'], [$plan->id]);
        $this->assertSame(1, $this->runDaily($plan)['settled']);

        // 全 0 門檻（預設條件＝無條件達成）但旗下 0 位會員：不達任何階
        $groupEmpty = $this->makeGroup('empty group');
        $planEmpty = $this->makePlan(['agent_group_id' => $groupEmpty->id]);
        $this->makeLineAgent($groupEmpty->id);
        $this->assertSame(0, $this->runDaily($planEmpty)['settled']);
    }

    /** 游標：該期無代理達階（無結算列）也要前進，週期切換 promote 不被卡住 */
    public function test_period_without_rows_advances_cursor(): void
    {
        $resolver = app(PeriodResolver::class);
        $condition = $this->makeCondition([
            ['user_count' => 1, 'deposit_amount' => '100.00', 'percentage' => '30.00'],
        ]);
        $plan = $this->makePlan([
            'start_date' => self::PERIOD,
            'settlement_cycle' => GgrSettlementCycleEnum::MONTHLY->value,
            'downline_condition_id' => $condition->id,
            'pending_settlement_cycle' => GgrSettlementCycleEnum::DAILY->value,
            'pending_cycle_from' => '2026-08-21',
        ]);
        $this->makeMember($this->makeLineAgent()); // 無存款 → 不達階、不寫列

        $this->assertSame(0, $this->runDaily($plan)['settled']);
        $this->assertSame(0, GgrSettlement::query()->where('plan_id', $plan->id)->count());
        $this->assertSame(self::PERIOD, $plan->fresh()->settled_through);

        // 次日推導：08-20 已完成不再列入，從 08-21 起以新週期推導
        $periods = $resolver->pendingPeriods($plan, Carbon::parse('2026-08-22'));
        $this->assertCount(1, $periods);
        $this->assertSame('2026-08-21', $periods[0]['start']->toDateString());
        $this->assertSame(GgrSettlementCycleEnum::DAILY, $periods[0]['cycle']);

        // 生效日前期間（08-20）已結清（雖無列）→ promote 落地
        $resolver->promoteIfDue($plan, Carbon::parse('2026-08-22'));
        $this->assertSame(GgrSettlementCycleEnum::DAILY->value, (int) $plan->fresh()->settlement_cycle);
    }

    /** 重跑：本輪不再達階的代理其舊 UNPAID 列刪除 */
    public function test_rerun_removes_stale_unpaid_rows(): void
    {
        $condition = $this->makeCondition([
            ['user_count' => 1, 'deposit_amount' => '100.00', 'percentage' => '30.00'],
        ]);
        $plan = $this->makePlan(['downline_condition_id' => $condition->id]);
        $agent = $this->makeLineAgent();
        $member = $this->makeMember($agent);
        $this->insertDeposit($member->id, '150');
        $this->insertBet($member->id, 902, '-1000');

        $this->assertSame(1, $this->runDaily($plan)['settled']);

        // 存款資料修正後不再達階 → 重跑應刪掉舊列，批次七才不會照舊 payable 發放
        DB::table('member_hourly_statistics')->where('member_id', $member->id)->delete();
        QueueJobRun::resetMarks(QueueJobKeyEnum::GGR_SETTLEMENT, [self::PERIOD . ' 00:00:00'], [$plan->id]);

        $this->assertSame(0, $this->runDaily($plan)['settled']);
        $this->assertSame(0, GgrSettlement::query()->where('plan_id', $plan->id)->where('agent_id', $agent->id)->count());
    }

    /** Job 建構子收「結算截至日（含）」，「今天」錨在 Job 內 +1（與 --date、後台頁同語意） */
    public function test_job_anchor_is_day_after_through_date(): void
    {
        $this->assertSame('2026-09-02', GgrSettleJob::anchorFor(Carbon::parse('2026-09-01'))->toDateString());
    }

    // ---------------------------------------------------------------- helpers

    private function makeMember(Agent $agent): Member
    {
        return $this->createMember($agent, ['username' => 'm' . bin2hex(random_bytes(7))]);
    }

    /** @return array{mode: string, settled: int, skipped_agents: int, agents: array<int, array<string, mixed>>} */
    private function runDaily(GgrPlan $plan, string $date = self::PERIOD): array
    {
        return app(SettlementRunner::class)->runPlanPeriod(
            plan: $plan,
            periodStart: Carbon::parse($date),
            periodEnd: Carbon::parse($date),
            cycle: GgrSettlementCycleEnum::DAILY,
            source: QueueJobRunSourceEnum::MANUAL,
        );
    }

    private function makeGroup(string $name): AgentGroup
    {
        return AgentGroup::create([
            'agent_group_type' => AgentGroupTypeEnum::CUSTOMIZED_SETTING->value,
            'organization_id' => 1,
            'name' => $name . ' ' . bin2hex(random_bytes(3)),
            'memo' => '',
            'is_default' => 0,
        ]);
    }

    private function makeLineAgent(?int $groupId = null): Agent
    {
        $unique = bin2hex(random_bytes(8));
        $agent = Agent::factory()->create([
            'code' => substr($unique, 0, 10),
            'username' => 'g' . substr($unique, 0, 15),
            'email' => substr($unique, 0, 12) . '@t.ag',
            'status' => 1,
            'role_id' => 21,
            'customized_setting_group_id' => $groupId ?? $this->group->id,
        ]);
        $agent->hierarchy_path = $this->general->hierarchy_path . '/' . $agent->id;
        $agent->hierarchy = "[-1,{$agent->id},-2]";
        $agent->save();

        return $agent;
    }

    /** @param array<int, array{user_count: int, percentage: string, deposit_amount?: string, total_bet_amount?: string, game_revenue_amount?: string, valid_bet_amount?: string}> $tiers */
    private function makeCondition(array $tiers, int $logicType = GgrCondition::LOGIC_TYPE_OR, int $activeUserOnly = 0): GgrCondition
    {
        $condition = GgrCondition::create([
            'general_agent_id' => $this->general->id,
            'name' => 'engine cond ' . bin2hex(random_bytes(4)),
            'logic_type' => $logicType,
            'active_user_only' => $activeUserOnly,
            'status' => 1,
            'created_by' => $this->general->id,
            'admin' => $this->general->id,
        ]);

        foreach ($tiers as $index => $tier) {
            GgrConditionTier::create([
                'condition_id' => $condition->id,
                'tier' => $index + 1,
                'user_count' => $tier['user_count'],
                'deposit_amount' => $tier['deposit_amount'] ?? '0',
                'total_bet_amount' => $tier['total_bet_amount'] ?? '0',
                'game_revenue_amount' => $tier['game_revenue_amount'] ?? '0',
                'valid_bet_amount' => $tier['valid_bet_amount'] ?? '0',
                'percentage' => $tier['percentage'],
                'admin' => $this->general->id,
            ]);
        }

        return $condition;
    }

    /**
     * 建遊戲商設定＋每 vendor 一顆 game（id=vendorId*10）。
     *
     * @param  array<int, array<int, array{threshold: string, weight: string, min_winlose?: int, max_ggr?: int}>>  $vendors  vendor_id => tiers
     */
    private function makeVendorSetting(array $vendors): GgrVendorSetting
    {
        $setting = GgrVendorSetting::create([
            'general_agent_id' => $this->general->id,
            'name' => 'engine vs ' . bin2hex(random_bytes(4)),
            'status' => 1,
            'created_by' => $this->general->id,
            'admin' => $this->general->id,
        ]);

        foreach ($vendors as $vendorId => $tiers) {
            $this->gameIds[$vendorId] ??= (int) (Game::query()
                ->where('vendor_id', $vendorId)->where('game_type', 'eng-' . $vendorId)->value('id')
                ?? DB::table('games')->insertGetId([
                    'vendor_id' => $vendorId,
                    'type_id' => 1,
                    'game_type' => 'eng-' . $vendorId,
                    'name' => 'engine game ' . $vendorId,
                    'status' => 1,
                    'admin' => 1,
                ]));

            foreach ($tiers as $index => $tier) {
                GgrVendorSettingDetail::create([
                    'setting_id' => $setting->id,
                    'vendor_id' => $vendorId,
                    'vendor_info_id' => $vendorId * 100,
                    'game_type_id' => 1,
                    'tier' => $index + 1,
                    'excluded_game_ids' => null,
                    'status' => 1,
                    'threshold_amount' => $tier['threshold'],
                    'min_winlose' => $tier['min_winlose'] ?? 0,
                    'max_ggr' => $tier['max_ggr'] ?? 0,
                    'weight_rate' => $tier['weight'],
                    'admin' => $this->general->id,
                ]);
            }
        }

        return $setting;
    }

    private function makePlan(array $overrides = []): GgrPlan
    {
        return GgrPlan::create(array_merge([
            'general_agent_id' => $this->general->id,
            'name' => 'engine plan ' . bin2hex(random_bytes(4)),
            'settlement_cycle' => GgrSettlementCycleEnum::DAILY->value,
            'agent_group_id' => $this->group->id,
            'start_date' => '2026-08-01',
            'carry_negative' => 0,
            'admin_fee_rate' => '5.00',
            'handling_fee' => '200.00',
            'ggr_rate' => '30.00',
            'bonus_rate' => '50.00',
            'kyc_unique_bank_account' => 0, 'kyc_unique_contact_no' => 0, 'kyc_unique_email' => 0,
            'kyc_unique_ip' => 0, 'kyc_unique_name' => 0, 'kyc_violate_count' => 0,
            'downline_vendor_setting_id' => $this->vendorSetting->id,
            'downline_condition_id' => $this->condition->id,
            'status' => 1,
            'created_by' => $this->general->id,
            'admin' => $this->general->id,
        ], $overrides));
    }

    private function insertBet(int $memberId, int $vendorId, string $winLose, ?string $payoutTimeUtc = null, string $validBet = '0'): void
    {
        DB::table('total_bet_records')->insert([
            'summary_time' => $payoutTimeUtc ?? self::IN_WINDOW_UTC,
            'member_id' => $memberId,
            'vendor_id' => $vendorId,
            'bet_record_id' => ++$this->betRecordSeq,
            'game_id' => $this->gameIds[$vendorId],
            'reference_no' => 'ENG' . $this->betRecordSeq,
            'bet' => $validBet,
            'valid_bet' => $validBet,
            'win_lose' => $winLose,
            'bet_time' => $payoutTimeUtc ?? self::IN_WINDOW_UTC,
            'payout_time' => $payoutTimeUtc ?? self::IN_WINDOW_UTC,
        ]);
    }

    private function insertTransactionLog(int $memberId, int $type, string $point): void
    {
        DB::table('transaction_logs')->insert([
            'transaction_no' => 'ENGT' . bin2hex(random_bytes(6)),
            'member_id' => $memberId,
            'type' => $type,
            'point' => $point,
            'vendor_point' => 0,
            'rate' => 1,
            'status' => 1000,
            'operator' => 'engine-test',
            'created_at' => self::IN_WINDOW_UTC,
            'admin' => 1,
        ]);
    }

    private function insertWalletLog(int $memberId, string $point): void
    {
        DB::table('wallet_transaction_logs')->insert([
            'transaction_no' => 'ENGW' . bin2hex(random_bytes(6)),
            'member_id' => $memberId,
            'type' => 7,
            'point' => $point,
            'status' => 1000,
            'remark' => '',
            'checksum' => '',
            'result' => '0',
            'operator' => 'engine-test',
            'created_at' => self::IN_WINDOW_UTC,
        ]);
    }

    private function insertSbr(int $memberId, string $jackpot, string $rollingChip): void
    {
        DB::table('summary_bet_records')->insert([
            'summary_time' => self::IN_WINDOW_UTC,
            'data_time' => self::IN_WINDOW_UTC,
            'member_id' => $memberId,
            'vendor_id' => 902,
            'game_id' => 0,
            'jackpot' => $jackpot,
            'rolling_chip' => $rollingChip,
        ]);
    }

    private function insertLottery(int $agentId, int $eventId, string $point): void
    {
        DB::table('summary_event_record_agents')->insert([
            'summary_time' => self::IN_WINDOW_UTC,
            'agent_id' => $agentId,
            'event_id' => $eventId,
            'point' => $point,
        ]);
    }

    private function insertRecycle(int $memberId, string $amount): void
    {
        $joinId = DB::table('event_v2_user_joins')->insertGetId([
            'event_v2_detail_id' => 1,
            'member_id' => $memberId,
            'reward' => 0,
            'status' => 2,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // 回收窗照 SR15 現況：GMT+8 業務時間字串直接比 created_at（不換時區）
        DB::table('event_v2_recycles')->insert([
            'event_v2_user_join_id' => $joinId,
            'recycle_amount' => $amount,
            'status' => 2,
            'created_at' => self::PERIOD . ' 10:00:00',
            'updated_at' => now(),
        ]);
    }

    private function insertDeposit(int $memberId, string $amount): void
    {
        DB::table('member_hourly_statistics')->insert([
            'member_id' => $memberId,
            'summary_hour' => '2026-08-20 04:00:00',
            'deposit_count' => 1,
            'deposit_amount' => $amount,
            'withdrawal_count' => 0,
            'withdrawal_amount' => 0,
        ]);
    }

    private function insertSettlementRow(GgrPlan $plan, int $agentId, string $start, string $end): void
    {
        GgrSettlement::create([
            'general_agent_id' => $plan->general_agent_id,
            'plan_id' => $plan->id,
            'agent_id' => $agentId,
            'settlement_cycle' => $plan->settlement_cycle,
            'period_start' => $start,
            'period_end' => $end,
            'ggr_amount' => '0.00',
            'bonus_rate' => '0.00', 'ggr_rate' => '0.00', 'condition_rate' => '0.00', 'admin_fee_rate' => '0.00',
            'handling_fee' => '0.00',
            'final_amount' => '0.00',
            'payable_amount' => '0.00',
            'carry_in_amount' => '0.00',
            'carry_out_amount' => '0.00',
            'calc_snapshot' => [],
            'payout_status' => GgrPayoutStatusEnum::UNPAID->value,
            'admin' => null,
        ]);
    }

    /** 該 GMT+8 業務日內的一個 UTC 時點 */
    private function utcInDay(string $businessDate): string
    {
        return Carbon::parse($businessDate . ' 12:00:00', 'Asia/Taipei')->setTimezone('UTC')->format('Y-m-d H:i:s');
    }
}
