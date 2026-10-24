<?php

declare(strict_types=1);

namespace Tests\Feature\Agent\Report;

use App\Enums\OrderStatusEnum;
use App\Enums\Transaction\TransactionEnum;
use App\Enums\Transaction\TransactionStatusEnum;
use App\Http\Middleware\Frontend\DecryptRequest;
use App\Http\Middleware\RequestLogger;
use App\Models\Agent;
use App\Models\AgentAccessToken;
use App\Models\AgentMenu;
use App\Models\AgentPermission;
use App\Models\Member;
use App\Models\MemberHourlyStatistic;
use App\Models\Order;
use App\Models\SummaryBetRecord;
use App\Models\SummaryEventRecord;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 代理數據看板明細下鑽（target/tickets#4180 第二階段）。
 *
 * php artisan test tests/Feature/Agent/Report/AgentDashboardDetailTest.php
 */
class AgentDashboardDetailTest extends TestCase
{
    private const string API = 'agent/api/report/agent-dashboard/detail';

    private const string EXPORT_API = 'agent/api/report/agent-dashboard/detail-export';

    private Agent $agent;

    /** 期間內註冊 + 期間內首充 + 複充的會員 */
    private Member $funnelMember;

    /** 期間內註冊但沒有任何充值的會員 */
    private Member $registerOnlyMember;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([DecryptRequest::class, RequestLogger::class]);

        $this->agent = $this->createAgentAndDomain();
        $this->grantMenu($this->agent);
        $this->loginAs($this->agent);

        $this->funnelMember = $this->createMember($this->agent, [
            'username' => 'fun' . bin2hex(random_bytes(6)),
            'created_at' => '2026-08-11 10:00:00',
        ]);
        $this->registerOnlyMember = $this->createMember($this->agent, [
            'username' => 'reg' . bin2hex(random_bytes(6)),
            'created_at' => '2026-08-12 10:00:00',
        ]);
    }

    private function grantMenu(Agent $agent): void
    {
        $menu = AgentMenu::create([
            'code' => 'report_agent_dashboard',
            'name' => '代理數據看板',
            'parent' => 0,
            'url' => 'report/agent_dashboard',
            'action' => '',
            'sort' => 2,
            'status' => 1,
            'admin' => 0,
        ]);

        AgentPermission::create([
            'agent_id' => $agent->id,
            'agent_menu_id' => $menu->id,
            'action' => '',
            'admin' => 0,
        ]);
    }

    private function loginAs(Agent $agent): void
    {
        $token = [REDACTED];
        AgentAccessToken:[REDACTED]
            'agent_id' => $agent->id,
            'token' => $token,
            'expire_date' => now()->addHours(2)->toDateTimeString(),
            'admin' => 1,
        ]);
        $this->addHeader('Authorization', 'Bearer ' . $token);
    }

    /** 使用者所選時區的牆鐘；tz_offset=8 ⇒ 2026-08-11 00:00 對應 UTC 08-10 16:00 */
    private function params(array $override = []): array
    {
        return array_merge([
            'start_time' => '2026-08-11 00:00:00',
            'end_time' => '2026-08-18 23:59:59',
            'tz_offset' => 8,
        ], $override);
    }

    private function seedDeposit(Member $member, string $payTime, string $point): void
    {
        Order::factory()->create([
            'member_id' => $member->id,
            'agent_id' => $member->parent,
            'status' => OrderStatusEnum::DEPOSIT_FINISHED->value,
            'pay_time' => $payTime,
            'point' => $point,
            'amount' => $point,
            'order_type_id' => 1,
            'admin' => 0,
        ]);
    }

    public function test_註冊組列出期間註冊會員並標記是否首充(): void
    {
        $this->seedDeposit($this->funnelMember, '2026-08-11 02:00:00', '1000');

        $response = $this->requestApi(self::API, $this->params(['group' => 'registration']));

        $response->assertStatus(200);
        $data = $response->json('data');

        $this->assertSame('registration', $data['group']);
        $this->assertSame(2, $data['pagination']['total'], '名單＝期間內註冊的會員');

        $rows = collect($data['list'])->keyBy('username');
        $funnel = $rows[$this->funnelMember->username];
        $registerOnly = $rows[$this->registerOnlyMember->username];

        $this->assertTrue($funnel['is_first_deposit']);
        // 輸入 GMT+8、輸出同時區：UTC 08-11 02:00 ⇒ GMT+8 08-11 10:00
        $this->assertSame('2026-08-11 10:00:00', $funnel['first_deposit_at']);
        $this->assertSame('2026-08-11 18:00:00', $funnel['registered_at']);
        $this->assertFalse($registerOnly['is_first_deposit']);
        $this->assertNull($registerOnly['first_deposit_at'], '沒首充就是 null，不是 0');
        $this->assertSame('+08:00', $data['time_basis']);
        // 層級不計 admin 根節點（admin 不會有會員），第一層代理即 1 級
        $this->assertSame(1, $funnel['hierarchy']);
    }

    public function test_首充組與複充組是漏斗子集且加總等於卡片(): void
    {
        // 期間內註冊者：首充 1000、複充 500 + 300
        $this->seedDeposit($this->funnelMember, '2026-08-11 02:00:00', '1000');
        $this->seedDeposit($this->funnelMember, '2026-08-12 02:00:00', '500');
        $this->seedDeposit($this->funnelMember, '2026-08-13 02:00:00', '300');

        // 期間前註冊、期間內才首充的老會員：不進首充/複充名單
        $veteran = $this->createMember($this->agent, [
            'username' => 'vet' . bin2hex(random_bytes(6)),
            'created_at' => '2026-08-01 10:00:00',
        ]);
        $this->seedDeposit($veteran, '2026-08-12 03:00:00', '9000');

        $first = $this->requestApi(self::API, $this->params(['group' => 'first_deposit']));
        $first->assertStatus(200);
        $this->assertSame(1, $first->json('data.pagination.total'), '老會員不計入首充');
        $this->assertSame($this->funnelMember->username, $first->json('data.list.0.username'));
        $this->assertSame('1000.0000', $first->json('data.list.0.first_deposit_amount'));
        $this->assertTrue($first->json('data.list.0.is_repeat_deposit'));
        $this->assertSame('1000.0000', $first->json('data.total_summary.first_deposit_amount'));

        $repeat = $this->requestApi(self::API, $this->params(['group' => 'repeat_deposit']));
        $repeat->assertStatus(200);
        $this->assertSame(1, $repeat->json('data.pagination.total'));
        $this->assertSame(2, $repeat->json('data.list.0.repeat_deposit_count'), '筆數是交易次數');
        $this->assertSame('800.0000', $repeat->json('data.list.0.repeat_deposit_amount'));
        $this->assertSame('800.0000', $repeat->json('data.page_summary.repeat_deposit_amount'));
        $this->assertSame(2, $repeat->json('data.total_summary.repeat_deposit_count'));
    }

    public function test_充值組名單不受漏斗限制且時間欄為小時粒度(): void
    {
        // 期間前註冊的老會員也要出現在充值組
        $veteran = $this->createMember($this->agent, [
            'username' => 'vet' . bin2hex(random_bytes(6)),
            'created_at' => '2026-08-01 10:00:00',
        ]);

        // 來源明細欄（target/tickets#4333）：老會員 3000 全是三方充值；漏斗會員 1000 是後台開分、200 是自助洗分
        MemberHourlyStatistic::create([
            'member_id' => $veteran->id,
            'summary_hour' => '2026-08-12 02:00:00',
            'deposit_count' => 2,
            'deposit_amount' => '3000',
            'withdrawal_count' => 0,
            'withdrawal_amount' => '0',
            'third_party_deposit_count' => 2,
            'third_party_deposit_amount' => '3000',
        ]);
        MemberHourlyStatistic::create([
            'member_id' => $this->funnelMember->id,
            'summary_hour' => '2026-08-11 05:00:00',
            'deposit_count' => 1,
            'deposit_amount' => '1000',
            'withdrawal_count' => 1,
            'withdrawal_amount' => '200',
            'admin_deposit_count' => 1,
            'admin_deposit_amount' => '1000',
            'auto_withdrawal_count' => 1,
            'auto_withdrawal_amount' => '200',
        ]);

        $deposit = $this->requestApi(self::API, $this->params(['group' => 'deposit']));
        $deposit->assertStatus(200);
        $this->assertSame(2, $deposit->json('data.pagination.total'), '充值組含老會員');
        $this->assertSame('4000.0000', $deposit->json('data.total_summary.deposit_amount'));
        $this->assertSame(3, $deposit->json('data.total_summary.deposit_count'));

        $rows = collect($deposit->json('data.list'))->keyBy('username');
        // 整點桶 UTC 08-11 05:00 ⇒ GMT+8 08-11 13:00
        $this->assertSame('2026-08-11 13:00:00', $rows[$this->funnelMember->username]['first_deposit_hour']);

        // 提現組只列有提現的會員
        $withdrawal = $this->requestApi(self::API, $this->params(['group' => 'withdrawal']));
        $withdrawal->assertStatus(200);
        $this->assertSame(1, $withdrawal->json('data.pagination.total'));
        $this->assertSame('200.0000', $withdrawal->json('data.list.0.withdrawal_amount'));

        // 六類來源明細組：與卡片同欄，只列該來源有金額的會員
        $admin = $this->requestApi(self::API, $this->params(['group' => 'deposit_admin']));
        $admin->assertStatus(200);
        $this->assertSame(1, $admin->json('data.pagination.total'), '後台開分只有漏斗會員');
        $this->assertSame($this->funnelMember->username, $admin->json('data.list.0.username'));
        $this->assertSame(1, $admin->json('data.list.0.count'));
        $this->assertSame('1000.0000', $admin->json('data.list.0.amount'));
        $this->assertSame('2026-08-11 13:00:00', $admin->json('data.list.0.first_hour'));
        $this->assertSame('1000.0000', $admin->json('data.total_summary.amount'));

        $thirdParty = $this->requestApi(self::API, $this->params(['group' => 'deposit_third_party']));
        $thirdParty->assertStatus(200);
        $this->assertSame(1, $thirdParty->json('data.pagination.total'), '三方充值只有老會員');
        $this->assertSame('3000.0000', $thirdParty->json('data.total_summary.amount'));
        $this->assertSame(2, $thirdParty->json('data.total_summary.count'));

        $autoWithdrawal = $this->requestApi(self::API, $this->params(['group' => 'withdrawal_auto']));
        $autoWithdrawal->assertStatus(200);
        $this->assertSame('200.0000', $autoWithdrawal->json('data.list.0.amount'));

        $empty = $this->requestApi(self::API, $this->params(['group' => 'withdrawal_admin']));
        $empty->assertStatus(200);
        $this->assertSame(0, $empty->json('data.pagination.total'), '沒有後台洗分就是空表');
    }

    public function test_投注組的派獎與GGR口徑與一階一致(): void
    {
        SummaryBetRecord::create([
            'summary_time' => '2026-08-12 03:00:00',
            'data_time' => '2026-08-12 03:00:00',
            'member_id' => $this->funnelMember->id,
            'vendor_id' => 1,
            'game_id' => 1,
            'bet' => '10000',
            'win_lose' => '-400',
            'cost' => '20',
            'record_count' => 5,
        ]);

        $response = $this->requestApi(self::API, $this->params(['group' => 'bet']));

        $response->assertStatus(200);
        $row = $response->json('data.list.0');
        $this->assertSame('10000.0000', $row['bet_amount']);
        $this->assertSame('9600.0000', $row['payout_amount'], '派獎 = 投注 + 會員淨輸贏');
        $this->assertSame('400.0000', $row['ggr'], 'GGR = −會員淨輸贏');
        $this->assertSame(5, $row['bet_count']);
        $this->assertSame('2026-08-12 11:00:00', $row['first_bet_hour']);

        // 盤費自己一張表（名單＝期間內有盤費的會員）
        $fee = $this->requestApi(self::API, $this->params(['group' => 'cost_vendor_fee']));
        $fee->assertStatus(200);
        $this->assertSame(1, $fee->json('data.pagination.total'));
        $this->assertSame('20.0000', $fee->json('data.list.0.amount'));
    }

    public function test_六項成本各自一張表(): void
    {
        // 活動獎勵改抽交易表（對齊 newsr11）：transaction_logs 100；活動 V2 走 event_id 7 = 50
        DB::table('transaction_logs')->insert([
            'transaction_no' => 'T' . bin2hex(random_bytes(6)),
            'member_id' => $this->funnelMember->id,
            'type' => TransactionEnum::ACTIVITY_AWARD->value,
            'status' => TransactionStatusEnum::STATUS_SUCCESS->value,
            'point' => '100',
            'vendor_point' => 0,
            'rate' => 1,
            'operator' => 'test',
            'admin' => 0,
            'created_at' => '2026-08-12 03:00:00',
            'updated_at' => '2026-08-12 03:00:00',
        ]);

        // event_id 1 為 lottery SP 的舊彙總，改口徑後不得計入
        foreach ([[1, '100'], [7, '50'], [4, '8']] as [$eventId, $point]) {
            SummaryEventRecord::insert([
                'summary_time' => '2026-08-12 03:00:00',
                'member_id' => $this->funnelMember->id,
                'event_id' => $eventId,
                'point' => $point,
            ]);
        }
        SummaryEventRecord::insert([
            'summary_time' => '2026-08-12 03:00:00',
            'member_id' => $this->registerOnlyMember->id,
            'event_id' => 6,
            'point' => '30',
        ]);

        $event = $this->requestApi(self::API, $this->params(['group' => 'cost_event']));
        $event->assertStatus(200);
        $this->assertSame(1, $event->json('data.pagination.total'), '只列有活動成本的會員');
        $this->assertSame(
            '150.0000',
            $event->json('data.list.0.amount'),
            '活動成本 = 交易表 100 + 活動 V2 50；event_id 1 不計入',
        );

        $rollingChip = $this->requestApi(self::API, $this->params(['group' => 'cost_rolling_chip']));
        $rollingChip->assertStatus(200);
        $this->assertSame($this->registerOnlyMember->username, $rollingChip->json('data.list.0.username'));
        $this->assertSame('30.0000', $rollingChip->json('data.total_summary.amount'), '禮碼成本 = event_id 6（對齊 newsr11 rollingchip）');

        // 返水（會員層）：三層代 10+5 ＋ 無限代 TRUNCATE(3.509,2)=3.50 ⇒ 18.5；代理層表的分潤不列
        DB::table('three_hierarchy_bonus_report')->insert([
            'summary_time' => '2026-08-12 00:00:00',
            'reward_type' => 1,
            'agent_id' => $this->agent->id,
            'member_id' => $this->funnelMember->id,
            'rebate' => '10',
            'down_rebate' => '5',
            'down2_rebate' => '0',
            'created_at' => '2026-08-12 03:00:00',
            'updated_at' => '2026-08-12 03:00:00',
        ]);
        DB::table('unlimit_bonusreport')->insert([
            'summary_date' => '2026-08-12 00:00:00',
            'member_id' => $this->funnelMember->id,
            'bonus_direct' => '3.509',
            'created_at' => '2026-08-12 03:00:00',
            'updated_at' => '2026-08-12 03:00:00',
        ]);
        DB::table('three_hierarchy_bonus_report_agents')->insert([
            'summary_time' => '2026-08-12 00:00:00',
            'agent_id' => $this->agent->id,
            'rebate_amount' => '999',
            'created_at' => '2026-08-12 03:00:00',
            'updated_at' => '2026-08-12 03:00:00',
        ]);

        $rebate = $this->requestApi(self::API, $this->params(['group' => 'cost_rebate']));
        $rebate->assertStatus(200);
        $this->assertSame(1, $rebate->json('data.pagination.total'), '返水明細只列有分潤的會員');
        $this->assertSame($this->funnelMember->username, $rebate->json('data.list.0.username'));
        $this->assertSame('18.5000', $rebate->json('data.total_summary.amount'), '三層代 15 ＋ 無限代 3.50；代理層 999 不列');

        // 回收：經 event_v2_user_joins 歸戶
        $joinId = DB::table('event_v2_user_joins')->insertGetId([
            'event_v2_detail_id' => 1,
            'member_id' => $this->registerOnlyMember->id,
            'status' => 1,
            'created_at' => '2026-08-12 03:00:00',
            'updated_at' => '2026-08-12 03:00:00',
        ]);
        DB::table('event_v2_recycles')->insert([
            'event_v2_user_join_id' => $joinId,
            'final_balance' => '12.3456',
            'withdraw' => '0',
            'recycle_amount' => '12.3456',
            'status' => 4,
            'created_at' => '2026-08-12 03:00:00',
            'updated_at' => '2026-08-12 03:00:00',
        ]);

        $recycle = $this->requestApi(self::API, $this->params(['group' => 'cost_recycle']));
        $recycle->assertStatus(200);
        $this->assertSame(1, $recycle->json('data.pagination.total'));
        $this->assertSame($this->registerOnlyMember->username, $recycle->json('data.list.0.username'));
        $this->assertSame('12.3456', $recycle->json('data.list.0.amount'));

        $vip = $this->requestApi(self::API, $this->params(['group' => 'cost_vip']));
        $vip->assertStatus(200);
        $this->assertSame(0, $vip->json('data.pagination.total'), '沒有 VIP 成本就是空表');
    }

    public function test_留存組三個行為同表且日界依tz_offset(): void
    {
        // 會員註冊 UTC 08-11 10:00 ⇒ GMT+8 08-11 18:00、GMT+0 08-11 10:00
        // 登入 UTC 08-11 20:00 ⇒ GMT+8 08-12 04:00（D1）、GMT+0 仍是 08-11（D0）
        DB::table('member_login_logs')->insert([
            'member_id' => $this->funnelMember->id,
            'ip' => '0.0.0.0',
            'status' => 1,
            'created_at' => '2026-08-11 20:00:00',
            'updated_at' => '2026-08-11 20:00:00',
            'admin' => 0,
        ]);

        $plus8 = $this->requestApi(self::API, $this->params([
            'group' => 'retention',
            'retention_day' => 1,
        ]));
        $plus8->assertStatus(200);
        $this->assertSame(2, $plus8->json('data.pagination.total'), '名單＝期間註冊會員（分母）');

        $rows = collect($plus8->json('data.list'))->keyBy('username');
        $this->assertSame('2026-08-12 04:00:00', $rows[$this->funnelMember->username]['login_returned_at']);
        $this->assertNull($rows[$this->funnelMember->username]['bet_returned_at'], '沒投注回訪就是 null');
        $this->assertNull($rows[$this->registerOnlyMember->username]['login_returned_at']);

        $utc = $this->requestApi(self::API, $this->params([
            'group' => 'retention',
            'retention_day' => 1,
            'tz_offset' => 0,
        ]));
        $utc->assertStatus(200);
        $utcRows = collect($utc->json('data.list'))->keyBy('username');
        $this->assertNull(
            $utcRows[$this->funnelMember->username]['login_returned_at'],
            'GMT+0 下同一筆登入落在註冊日當天，不算 D1',
        );
    }

    public function test_留存組缺retention_day要驗證失敗(): void
    {
        $response = $this->requestApi(self::API, $this->params(['group' => 'retention']));

        // 本專案把 ValidationException 統一轉成 400（bootstrap/app.php）
        $response->assertStatus(400);
    }

    public function test_不支援的group要驗證失敗(): void
    {
        $response = $this->requestApi(self::API, $this->params(['group' => 'ngr']));

        $response->assertStatus(400);
    }

    public function test_畫面分頁上限是100(): void
    {
        $ok = $this->requestApi(self::API, $this->params(['group' => 'registration', 'per_page' => 100]));
        $ok->assertStatus(200);

        // 匯出走 detail-export，不再靠放寬 per_page 一次撈完
        $tooMany = $this->requestApi(self::API, $this->params(['group' => 'registration', 'per_page' => 101]));
        $tooMany->assertStatus(400);
    }

    public function test_匯出端點一次回全部且不含分頁與加總(): void
    {
        $detail = $this->requestApi(self::API, $this->params(['group' => 'registration', 'per_page' => 1]));
        $detail->assertStatus(200);
        $this->assertCount(1, $detail->json('data.list'), '畫面端點照 per_page 分頁');

        $export = $this->requestApi(self::EXPORT_API, $this->params(['group' => 'registration', 'per_page' => 1]));

        $export->assertStatus(200);
        // 匯出忽略分頁參數，一次回整份名單
        $this->assertSame(
            $detail->json('data.pagination.total'),
            count($export->json('data.list')),
            '匯出列數＝名單人數',
        );
        $this->assertSame(100_000, $export->json('data.limit'));
        $this->assertNull($export->json('data.pagination'), '匯出不算總筆數');
        $this->assertNull($export->json('data.total_summary'), '匯出不做加總');
        // 欄位與畫面同一份，前端不寫第二份表頭
        $this->assertSame($detail->json('data.columns'), $export->json('data.columns'));
        $this->assertSame($detail->json('data.list.0'), $export->json('data.list.0'));
    }

    public function test_匯出端點的group驗證與畫面一致(): void
    {
        $this->requestApi(self::EXPORT_API, $this->params(['group' => 'ngr']))->assertStatus(400);
        $this->requestApi(self::EXPORT_API, $this->params(['group' => 'retention']))->assertStatus(400);
    }
}
