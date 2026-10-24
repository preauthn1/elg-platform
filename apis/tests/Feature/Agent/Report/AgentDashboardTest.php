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
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 代理數據看板（target/tickets#4180 第一階段）。
 *
 * php artisan test tests/Feature/Agent/Report/AgentDashboardTest.php
 */
class AgentDashboardTest extends TestCase
{
    private Agent $agent;

    private Agent $child;

    private Member $ownMember;

    private Member $childMember;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([
            DecryptRequest::class,
            RequestLogger::class,
        ]);

        // 聚合結果有快取，測試用 array driver 不會自動清——不清會讓後續測試讀到前一個測試的數字
        Cache::flush();

        $this->agent = $this->createAgentAndDomain();
        $this->child = $this->createSubAgent($this->agent);

        // MemberFactory 的 username 固定為 testuser，同測試建多位會員必須各自指定
        $this->ownMember = $this->createMember($this->agent, [
            'username' => 'own' . bin2hex(random_bytes(6)),
            'created_at' => '2026-08-11 10:00:00',
        ]);
        $this->childMember = $this->createMember($this->child, [
            'username' => 'chd' . bin2hex(random_bytes(6)),
            'created_at' => '2026-08-11 12:00:00',
        ]);

        $this->grantDashboardMenu($this->agent);
        $this->loginAs($this->agent);
    }

    /** 端點掛 `CheckAgentPermission:report_agent_dashboard`，測試需自行建選單並授權 */
    private function grantDashboardMenu(Agent $agent): AgentMenu
    {
        $menu = AgentMenu::create([
            'code' => 'report_agent_dashboard',
            'name' => '代理數據看板',
            'parent' => 0,
            'url' => 'report/agent_dashboard',
            'action' => '',
            'sort' => 240,
            'status' => 1,
            'admin' => 0,
        ]);

        AgentPermission::create([
            'agent_id' => $agent->id,
            'agent_menu_id' => $menu->id,
            'action' => '',
            'admin' => 0,
        ]);

        return $menu;
    }

    /** 下級代理：`createAgentAndDomain()` 綁的網域全庫唯一，同一測試只能用一次，故下級直接走 factory */
    private function createSubAgent(Agent $parent): Agent
    {
        $agent = Agent::factory()->create([
            'parent' => $parent->id,
            'username' => 'sub' . bin2hex(random_bytes(6)),
        ]);
        $agent->hierarchy_path = "{$parent->hierarchy_path}/{$agent->id}";
        $agent->hierarchy = "[-1,{$agent->id},{$parent->id},-2]";
        $agent->save();

        return $agent;
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

    /** 時間為「使用者所選時區的牆鐘」：tz_offset=8 時 2026-08-11 00:00 ⇒ UTC 08-10 16:00 */
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

    public function test_overview_線模式包含下級代理且成本分項正確(): void
    {
        // 首充（UTC 08-11 02:00 ⇒ 落在區間）與複充各一筆
        $this->seedDeposit($this->ownMember, '2026-08-11 02:00:00', '1000');
        $this->seedDeposit($this->ownMember, '2026-08-12 02:00:00', '500');
        $this->seedDeposit($this->childMember, '2026-08-13 02:00:00', '2000');

        // 六類來源明細（target/tickets#4333 欄位）：三方充值 1000 + 自助上分 500；三方提現 200 + 自助洗分 100；後台開分 60、後台洗分 40
        MemberHourlyStatistic::create([
            'member_id' => $this->ownMember->id,
            'summary_hour' => '2026-08-11 02:00:00',
            'deposit_count' => 2,
            'deposit_amount' => '1500',
            'withdrawal_count' => 1,
            'withdrawal_amount' => '300',
            'third_party_deposit_amount' => '1000',
            'auto_deposit_amount' => '500',
            'admin_deposit_amount' => '60',
            'third_party_withdrawal_amount' => '200',
            'auto_withdrawal_amount' => '100',
            'admin_withdrawal_amount' => '40',
        ]);
        MemberHourlyStatistic::create([
            'member_id' => $this->childMember->id,
            'summary_hour' => '2026-08-13 02:00:00',
            'deposit_count' => 1,
            'deposit_amount' => '2000',
            'withdrawal_count' => 0,
            'withdrawal_amount' => '0',
            'third_party_deposit_amount' => '2000',
        ]);

        // 投注：會員淨輸贏 −400 ⇒ GGR 400、派獎 = 10000 − 400 = 9600；盤費 20
        SummaryBetRecord::create([
            'summary_time' => '2026-08-12 03:00:00',
            'data_time' => '2026-08-12 03:00:00',
            'member_id' => $this->ownMember->id,
            'vendor_id' => 1,
            'game_id' => 1,
            'bet' => '10000',
            'win_lose' => '-400',
            'cost' => '20',
            'record_count' => 5,
        ]);

        // 有注單但有效投注為 0（例如取消／零流水）：舊報表算他一人，本看板跟著算
        SummaryBetRecord::create([
            'summary_time' => '2026-08-12 04:00:00',
            'data_time' => '2026-08-12 04:00:00',
            'member_id' => $this->childMember->id,
            'vendor_id' => 1,
            'game_id' => 1,
            'bet' => '0',
            'win_lose' => '0',
            'cost' => '0',
            'record_count' => 1,
        ]);

        // 活動成本改抽交易表（對齊 newsr11）：transaction_logs 100 + 多錢包 20 + 活動 V2（event_id 7）50
        DB::table('transaction_logs')->insert([
            'transaction_no' => 'T' . bin2hex(random_bytes(6)),
            'member_id' => $this->ownMember->id,
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
        DB::table('wallet_transaction_logs')->insert([
            'transaction_no' => 'W' . bin2hex(random_bytes(6)),
            'member_id' => $this->ownMember->id,
            'type' => 7,
            'status' => TransactionStatusEnum::STATUS_SUCCESS->value,
            'point' => '20',
            'remark' => '',
            'checksum' => '',
            'result' => '',
            'operator' => 'test',
            'created_at' => '2026-08-12 03:00:00',
            'updated_at' => '2026-08-12 03:00:00',
        ]);

        // event_id 1 是 lottery SP 的舊彙總，改口徑後不得再計入（TPower 實測該彙總有缺漏）
        foreach ([[1, '100'], [7, '50'], [6, '30'], [9, '20'], [3, '10'], [8, '5'], [4, '2']] as [$eventId, $point]) {
            SummaryEventRecord::insert([
                'summary_time' => '2026-08-12 03:00:00',
                'member_id' => $this->ownMember->id,
                'event_id' => $eventId,
                'point' => $point,
            ]);
        }

        // 返水成本對齊 newsr11 的三層代（3 layers of agents mode）＋ 無限代（全民代理）：
        // 代理層表以 agent_id 歸戶（40 + 20.00，無限代 TRUNCATE 到 2 位）、會員層表以直屬代理歸戶（10+5 + 3.5）
        DB::table('three_hierarchy_bonus_report_agents')->insert([
            'summary_time' => '2026-08-12 00:00:00',
            'agent_id' => $this->agent->id,
            'rebate_amount' => '40',
            'created_at' => '2026-08-12 03:00:00',
            'updated_at' => '2026-08-12 03:00:00',
        ]);
        DB::table('three_hierarchy_bonus_report')->insert([
            'summary_time' => '2026-08-12 00:00:00',
            'reward_type' => 1,
            'agent_id' => $this->child->id,
            'member_id' => $this->childMember->id,
            'rebate' => '10',
            'down_rebate' => '5',
            'down2_rebate' => '0',
            'created_at' => '2026-08-12 03:00:00',
            'updated_at' => '2026-08-12 03:00:00',
        ]);
        DB::table('unlimit_bonusreport_agents')->insert([
            'summary_date' => '2026-08-12 00:00:00',
            'agent_id' => $this->child->id,
            'bonus_direct' => '20.0099',
            'created_at' => '2026-08-12 03:00:00',
            'updated_at' => '2026-08-12 03:00:00',
            'admin' => 0,
        ]);
        DB::table('unlimit_bonusreport')->insert([
            'summary_date' => '2026-08-12 00:00:00',
            'member_id' => $this->ownMember->id,
            'bonus_direct' => '3.5',
            'created_at' => '2026-08-12 03:00:00',
            'updated_at' => '2026-08-12 03:00:00',
        ]);

        // 回收金額對齊 newsr11 eventCost 列：活動 V2 錢包剩餘回收
        $joinId = DB::table('event_v2_user_joins')->insertGetId([
            'event_v2_detail_id' => 1,
            'member_id' => $this->ownMember->id,
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

        $response = $this->requestApi('agent/api/report/agent-dashboard/overview', $this->params());

        $response->assertStatus(200);
        $data = $response->json('data.target');

        $this->assertSame(2, $data['registration']['count'], '線模式應含下級代理的會員');
        $this->assertSame(2, $data['first_deposit']['count']);
        $this->assertSame('3000.0000', $data['first_deposit']['amount']);
        $this->assertSame(1, $data['repeat_deposit']['count']);
        $this->assertSame('500.0000', $data['repeat_deposit']['amount']);
        $this->assertSame(2, $data['deposit']['member_count']);
        $this->assertSame('3500.0000', $data['deposit']['amount']);
        $this->assertSame(1, $data['withdrawal']['member_count']);
        $this->assertSame('300.0000', $data['withdrawal']['amount']);
        $this->assertSame(
            ['third_party' => '3000.0000', 'auto' => '500.0000', 'admin' => '60.0000'],
            $data['deposit_sources'],
            '充值來源明細：三方 1000+2000、自助 500、後台開分 60',
        );
        $this->assertSame(
            ['third_party' => '200.0000', 'auto' => '100.0000', 'admin' => '40.0000'],
            $data['withdrawal_sources'],
        );
        $this->assertSame(2, $data['real_bet_member_count'], '有注單即計入：有效投注 0 的會員也算');
        $this->assertSame('10000.0000', $data['bet']['amount']);
        $this->assertSame('9600.0000', $data['bet']['payout']);
        $this->assertSame('400.0000', $data['bet']['ggr']);
        $this->assertSame(
            '170.0000',
            $data['cost']['event'],
            '活動成本 = transaction_logs 100 + 多錢包 20 + 活動 V2 50；event_id 1 不計入',
        );
        $this->assertSame('78.5000', $data['cost']['rebate'], '返水成本 = 三層代 40 + 15 ＋ 無限代 20.00 + 3.5');
        $this->assertSame('30.0000', $data['cost']['rolling_chip'], '禮碼成本 = event_id 6，對齊 newsr11 rollingchip');
        $this->assertSame('12.3456', $data['cost']['recycle']);
        $this->assertSame('20.0000', $data['cost']['vip']);
        $this->assertSame('10.0000', $data['cost']['payment_fee'], '支付手續費 = event_id 3（代收）');
        $this->assertSame('5.0000', $data['cost']['withdrawal_fee'], '提款手續費 = event_id 8（代付）');
        $this->assertSame('2.0000', $data['cost']['sms']);
        $this->assertSame('20.0000', $data['cost']['vendor_fee']);
    }

    public function test_overview_投注排除env清單內的遊戲商_對齊SR11(): void
    {
        // 對齊 SR11 平台對帳表：env SR11_NO_PRESELECTION 內的遊戲商不計投注、輸贏、盤費與投注人數
        config(['custom.report.excluded_vendor_ids' => [181]]);

        SummaryBetRecord::create([
            'summary_time' => '2026-08-12 03:00:00',
            'data_time' => '2026-08-12 03:00:00',
            'member_id' => $this->ownMember->id,
            'vendor_id' => 1,
            'game_id' => 1,
            'bet' => '10000',
            'win_lose' => '-400',
            'cost' => '20',
            'record_count' => 5,
        ]);
        SummaryBetRecord::create([
            'summary_time' => '2026-08-12 04:00:00',
            'data_time' => '2026-08-12 04:00:00',
            'member_id' => $this->childMember->id,
            'vendor_id' => 181,
            'game_id' => 1,
            'bet' => '5000',
            'win_lose' => '300',
            'cost' => '10',
            'record_count' => 2,
        ]);

        $response = $this->requestApi('agent/api/report/agent-dashboard/overview', $this->params());

        $response->assertStatus(200);
        $data = $response->json('data.target');

        $this->assertSame(1, $data['real_bet_member_count'], '只在排除遊戲商投注的會員不計入投注人數');
        $this->assertSame('10000.0000', $data['bet']['amount']);
        $this->assertSame('400.0000', $data['bet']['ggr']);
        $this->assertSame('20.0000', $data['cost']['vendor_fee']);
    }

    public function test_overview_漏斗為子集_期間前註冊的老會員首充不計入(): void
    {
        // 期間為 2026-08-11 ~ 08-18；此會員 08-01 就註冊，期間內才首充
        $veteran = $this->createMember($this->agent, [
            'username' => 'vet' . bin2hex(random_bytes(6)),
            'created_at' => '2026-08-01 10:00:00',
        ]);
        $this->seedDeposit($veteran, '2026-08-12 02:00:00', '9000');
        $this->seedDeposit($veteran, '2026-08-13 02:00:00', '1000');

        // 期間內註冊的會員：首充 + 複充各一筆
        $this->seedDeposit($this->ownMember, '2026-08-11 02:00:00', '1000');
        $this->seedDeposit($this->ownMember, '2026-08-12 02:00:00', '500');

        // 充值組走 member_hourly_statistics（名單不做漏斗限制）
        MemberHourlyStatistic::create([
            'member_id' => $veteran->id,
            'summary_hour' => '2026-08-12 02:00:00',
            'deposit_count' => 2,
            'deposit_amount' => '10000',
            'withdrawal_count' => 0,
            'withdrawal_amount' => '0',
        ]);
        MemberHourlyStatistic::create([
            'member_id' => $this->ownMember->id,
            'summary_hour' => '2026-08-11 02:00:00',
            'deposit_count' => 2,
            'deposit_amount' => '1500',
            'withdrawal_count' => 0,
            'withdrawal_amount' => '0',
        ]);

        $response = $this->requestApi('agent/api/report/agent-dashboard/overview', $this->params());

        $response->assertStatus(200);
        $data = $response->json('data.target');

        // 註冊 ⊃ 首充 ⊃ 複充：老會員的首充與複充都不計入
        $this->assertSame(2, $data['registration']['count'], '老會員在期間外註冊，不算註冊人數');
        $this->assertSame(1, $data['first_deposit']['count'], '首充只算期間內註冊的會員');
        $this->assertSame('1000.0000', $data['first_deposit']['amount']);
        $this->assertSame(1, $data['repeat_deposit']['count']);
        $this->assertSame('500.0000', $data['repeat_deposit']['amount']);

        // 但充值組不是漏斗子集（名單＝期間內有成功充值），老會員照樣計入
        $this->assertSame(2, $data['deposit']['member_count'], '充值人數不受漏斗子集限制');
        $this->assertSame('11500.0000', $data['deposit']['amount']);
    }

    public function test_overview_self模式只計直屬會員(): void
    {
        $response = $this->requestApi(
            'agent/api/report/agent-dashboard/overview',
            $this->params(['scope' => 'self']),
        );

        $response->assertStatus(200);
        $this->assertSame(1, $response->json('data.target.registration.count'));
        // 貢獻佔比的分母為可視範疇全量（自身＋下級）
        $this->assertSame(2, $response->json('data.scope_total.registration.count'));
    }

    public function test_overview_指定範疇外的代理回錯誤(): void
    {
        $outsider = Agent::factory()->create(['username' => 'out' . bin2hex(random_bytes(6))]);
        $outsider->hierarchy_path = "/{$outsider->id}";
        $outsider->save();

        $response = $this->requestApi(
            'agent/api/report/agent-dashboard/overview',
            $this->params(['agent_username' => $outsider->username]),
        );

        $response->assertStatus(400, '範疇外代理必須擋下');
        $this->assertSame('查無此代理', $response->json('message'));
    }

    /** 代理帳號可含 - 與 _（如 T-POWER），驗證規則不得把它擋成「只能以字母及數字組成」 */
    public function test_overview_代理帳號含連字號可查詢(): void
    {
        $dashed = Agent::factory()->create([
            'parent' => $this->agent->id,
            'username' => 'T-POWER-' . bin2hex(random_bytes(3)),
        ]);
        $dashed->hierarchy_path = "{$this->agent->hierarchy_path}/{$dashed->id}";
        $dashed->hierarchy = "[-1,{$dashed->id},{$this->agent->id},-2]";
        $dashed->save();

        $response = $this->requestApi(
            'agent/api/report/agent-dashboard/overview',
            $this->params(['agent_username' => $dashed->username]),
        );

        $response->assertOk();
    }

    public function test_retention_矩陣涵蓋D1_D7_D30與三種行為(): void
    {
        // ownMember 註冊 UTC 08-11 10:00 ⇒ GMT+8 08-11 18:00，故註冊日（GMT+8）＝08-11
        // D1＝08-12、D7＝08-18、D30＝09-10（皆 GMT+8 日）
        $login = function (string $utc) {
            DB::table('member_login_logs')->insert([
                'member_id' => $this->ownMember->id,
                'ip' => '0.0.0.0',
                'status' => 1,
                'created_at' => $utc,
                'updated_at' => $utc,
                'admin' => 0,
            ]);
        };

        $login('2026-08-11 20:00:00'); // GMT+8 08-12 04:00 ⇒ D1
        $login('2026-08-18 02:00:00'); // GMT+8 08-18 10:00 ⇒ D7
        $login('2026-09-10 02:00:00'); // GMT+8 09-10 10:00 ⇒ D30
        $login('2026-08-13 02:00:00'); // GMT+8 08-13 10:00 ⇒ D2，三格都不該計入

        // 投注留存只發生在 D7
        SummaryBetRecord::create([
            'summary_time' => '2026-08-18 03:00:00',
            'data_time' => '2026-08-18 03:00:00',
            'member_id' => $this->ownMember->id,
            'vendor_id' => 1,
            'game_id' => 1,
            'bet' => '500',
            'win_lose' => '-50',
            'cost' => '0',
            'record_count' => 1,
        ]);

        // 充值留存只發生在 D30
        MemberHourlyStatistic::create([
            'member_id' => $this->ownMember->id,
            'summary_hour' => '2026-09-10 03:00:00',
            'deposit_count' => 1,
            'deposit_amount' => '300',
            'withdrawal_count' => 0,
            'withdrawal_amount' => '0',
        ]);

        $response = $this->requestApi(
            'agent/api/report/agent-dashboard/retention',
            $this->params(['scope' => 'self']),
        );

        $response->assertStatus(200);
        $this->assertSame(1, $response->json('data.denominator'));

        // 登入：D1 / D7 / D30 各命中一次；D2 那筆不該讓任何一格 +1
        $this->assertSame(
            ['d1' => 1, 'd7' => 1, 'd30' => 1],
            $response->json('data.matrix.login'),
            'D2 的登入不得落進 D1 / D7 / D30',
        );
        $this->assertSame(
            ['d1' => 0, 'd7' => 1, 'd30' => 0],
            $response->json('data.matrix.bet'),
            '投注留存只在 D7 命中',
        );
        $this->assertSame(
            ['d1' => 0, 'd7' => 0, 'd30' => 1],
            $response->json('data.matrix.deposit'),
            '充值留存只在 D30 命中',
        );
    }

    public function test_retention_未到期批次計為未留存(): void
    {
        // 期間迄日為 08-18；此會員 08-18 註冊，D7 與 D30 都還沒到 ⇒ 一律 0，且分母照算
        $late = $this->createMember($this->agent, [
            'username' => 'late' . bin2hex(random_bytes(6)),
            'created_at' => '2026-08-18 10:00:00',
        ]);
        DB::table('member_login_logs')->insert([
            'member_id' => $late->id,
            'ip' => '0.0.0.0',
            'status' => 1,
            'created_at' => '2026-08-19 02:00:00', // GMT+8 08-19 10:00 ⇒ 該會員的 D1
            'updated_at' => '2026-08-19 02:00:00',
            'admin' => 0,
        ]);

        $response = $this->requestApi(
            'agent/api/report/agent-dashboard/retention',
            $this->params(['scope' => 'self']),
        );

        $response->assertStatus(200);
        $this->assertSame(2, $response->json('data.denominator'), '未到期批次仍計入分母');
        $this->assertSame(1, $response->json('data.matrix.login.d1'), '晚註冊者的 D1 已到期並命中');
        $this->assertSame(0, $response->json('data.matrix.login.d7'), 'D7 未到期 ⇒ 0');
        $this->assertSame(0, $response->json('data.matrix.login.d30'), 'D30 未到期 ⇒ 0');
    }

    public function test_retention_日界依tz_offset平移(): void
    {
        // 會員註冊 UTC 08-11 10:00 ⇒ GMT+8 為 08-11 18:00、GMT+0 為 08-11 10:00
        // 登入 UTC 08-11 20:00 ⇒ GMT+8 為 08-12 04:00（註冊日隔天）、GMT+0 仍是 08-11（當天）
        // 同一筆資料、不同 tz_offset ⇒ 留存結果必須跟著日界移動
        // created_at 不在 fillable，直接寫表以指定登入時間
        DB::table('member_login_logs')->insert([
            'member_id' => $this->ownMember->id,
            'ip' => '0.0.0.0',
            'status' => 1,
            'created_at' => '2026-08-11 20:00:00',
            'updated_at' => '2026-08-11 20:00:00',
            'admin' => 0,
        ]);

        $plus8 = $this->requestApi(
            'agent/api/report/agent-dashboard/retention',
            $this->params(['scope' => 'self', 'tz_offset' => 8]),
        );
        $plus8->assertStatus(200);
        $this->assertSame(1, $plus8->json('data.denominator'));
        $this->assertSame(1, $plus8->json('data.matrix.login.d1'), 'GMT+8 下該筆登入落在註冊日隔天');

        $utc = $this->requestApi(
            'agent/api/report/agent-dashboard/retention',
            $this->params(['scope' => 'self', 'tz_offset' => 0]),
        );
        $utc->assertStatus(200);
        $this->assertSame(0, $utc->json('data.matrix.login.d1'), 'GMT+0 下同一筆登入仍在註冊日當天');
    }

    public function test_無選單權限的代理被擋下(): void
    {
        // 換成沒有授權的下級代理登入（覆蓋 Authorization header）
        $stranger = $this->createSubAgent($this->agent);
        $this->loginAs($stranger);

        $response = $this->requestApi('agent/api/report/agent-dashboard/overview', $this->params());

        $response->assertJsonPath('message', __('error_message.9003'));
    }

    public function test_agent_list_全零的代理不列出但保留通往資料的路徑(): void
    {
        // 期間內完全沒有數據的下級代理
        $empty = $this->createSubAgent($this->agent);
        $this->createMember($empty, [
            'username' => 'emp' . bin2hex(random_bytes(6)),
            'created_at' => '2026-01-01 00:00:00',
        ]);

        // 自身 0，但其下級（孫代理）在期間內有註冊 ⇒ 必須保留，否則展不開
        $bridge = $this->createSubAgent($this->agent);
        $grandChild = $this->createSubAgent($bridge);
        $this->createMember($grandChild, [
            'username' => 'gc' . bin2hex(random_bytes(6)),
            'created_at' => '2026-08-12 10:00:00',
        ]);

        $response = $this->requestApi(
            'agent/api/report/agent-dashboard/agent-list',
            $this->params(['scope' => 'self']),
        );

        $response->assertStatus(200);
        $ids = array_column($response->json('data.list'), 'agent_id');

        $this->assertContains($this->child->id, $ids, '有註冊的下級要列出');
        $this->assertContains($bridge->id, $ids, '自身 0 但下級有資料 ⇒ 保留展開路徑');
        $this->assertNotContains($empty->id, $ids, '整條線都沒資料的代理不列出');
        $this->assertSame(2, $response->json('data.pagination.total'), '總筆數要排除全零的代理');

        $bridgeRow = collect($response->json('data.list'))->firstWhere('agent_id', $bridge->id);
        $this->assertTrue($bridgeRow['has_children'], '有資料的下級才算 has_children');
        $this->assertSame(0, $bridgeRow['metrics']['registration']['count'], 'self 模式下自身仍為 0');
    }

    public function test_agent_list_逐層展開與加總(): void
    {
        $response = $this->requestApi('agent/api/report/agent-dashboard/agent-list', $this->params());

        $response->assertStatus(200);
        $list = $response->json('data.list');

        $this->assertCount(1, $list, '預設列出查詢對象的直屬下級');
        $this->assertSame($this->child->id, $list[0]['agent_id']);
        $this->assertSame(1, $list[0]['metrics']['registration']['count']);
        // 層級不計 admin 根節點：上層代理為 1 級、其下級為 2 級
        $this->assertSame(2, $list[0]['hierarchy']);
        $this->assertFalse($list[0]['has_children']);
        $this->assertSame(1, $response->json('data.pagination.total'));

        // 全頁加總＝當層全部頁，不是整條線總計（不含查詢對象自身的直屬會員）
        $this->assertSame(1, $response->json('data.total_summary.registration.count'));
        $this->assertSame(
            $response->json('data.page_summary'),
            $response->json('data.total_summary'),
            '只有一頁時本頁加總必須等於全頁加總',
        );
    }

    public function test_agent_list_全頁加總涵蓋分頁外的列(): void
    {
        $second = $this->createSubAgent($this->agent);
        $this->createMember($second, [
            'username' => 'sec' . bin2hex(random_bytes(6)),
            'created_at' => '2026-08-11 13:00:00',
        ]);

        $response = $this->requestApi(
            'agent/api/report/agent-dashboard/agent-list',
            $this->params(['per_page' => 1]),
        );

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data.list'));
        $this->assertSame(2, $response->json('data.pagination.total'));
        $this->assertSame(1, $response->json('data.page_summary.registration.count'), '本頁只加當頁那一列');
        $this->assertSame(2, $response->json('data.total_summary.registration.count'), '全頁加總含第二頁');
    }

    /**
     * 站台 root 代理（線下代理數超過 `SCOPE_ID_LIMIT`）查自己線。
     *
     * 巨型字面 IN 清單會讓 MySQL 放棄時間索引而逾時（TPower 正式站 44,663 個代理實測 44 秒回 500）；
     * 超過門檻改走 `hierarchy_path` 子查詢。本測試鎖兩件事：查得動，且範疇不外溢到線外代理。
     */
    public function test_overview_線下代理超過門檻時改走階層子查詢且範疇不外溢(): void
    {
        // 501 筆（門檻 500）直屬下級：用批次 insert，逐筆 factory 建太慢
        $rows = [];
        for ($i = 0; $i < 501; $i++) {
            $rows[] = Agent::factory()->make([
                'parent' => $this->agent->id,
                'username' => 'blk' . $i . bin2hex(random_bytes(3)),
                'hierarchy_path' => '',
            ])->getAttributes();
        }
        DB::table('agents')->insert($rows);
        DB::table('agents')->where('parent', $this->agent->id)->where('hierarchy_path', '')
            ->update(['hierarchy_path' => DB::raw("CONCAT('{$this->agent->hierarchy_path}/', id)")]);

        $bulkAgentId = (int) DB::table('agents')->where('parent', $this->agent->id)
            ->where('username', 'like', 'blk%')->value('id');
        $this->createMember(Agent::find($bulkAgentId), [
            'username' => 'blkm' . bin2hex(random_bytes(6)),
            'created_at' => '2026-08-11 09:00:00',
        ]);

        // 線外代理（另一棵樹）的會員不得被算進來
        $outsider = Agent::factory()->create([
            'parent' => 0,
            'username' => 'oth' . bin2hex(random_bytes(6)),
        ]);
        $outsider->hierarchy_path = "/1/{$outsider->id}";
        $outsider->save();
        $this->createMember($outsider, [
            'username' => 'out' . bin2hex(random_bytes(6)),
            'created_at' => '2026-08-11 09:30:00',
        ]);

        // 鎖住「不得展開成巨型 IN 清單」：舊實作會把整條線的代理 id 全塞進 bindings
        $maxBindings = 0;
        DB::listen(function ($query) use (&$maxBindings) {
            $maxBindings = max($maxBindings, count($query->bindings));
        });

        $response = $this->requestApi('agent/api/report/agent-dashboard/overview', $this->params());

        $response->assertStatus(200);
        // 自身 1 + 原下級 1 + 批次代理底下 1 = 3；線外那筆不算
        $this->assertSame(3, $response->json('data.target.registration.count'));
        $this->assertLessThan(
            500,
            $maxBindings,
            '超過門檻的代理線必須改走 hierarchy 子查詢，不得展開成巨型 IN 清單（會讓 MySQL 放棄時間索引）',
        );
    }

    /**
     * 指標聚合結果快取：同一組「範疇＋區間＋時區」只掃一次大表。
     *
     * overview / agent-list / 貢獻佔比分母跑的是同一組聚合（TPower 全站整月實測單支
     * summary_bet_records 就要 6～11 秒），前端進頁面會同時打三支端點、翻頁又會重打。
     */
    public function test_相同條件的聚合只查一次並跨端點共用(): void
    {
        $queries = 0;
        DB::listen(function () use (&$queries) {
            $queries++;
        });

        $this->requestApi('agent/api/report/agent-dashboard/overview', $this->params())->assertStatus(200);
        $first = $queries;

        // 同條件重打（翻頁 / 切換成本開關都會走到）
        $queries = 0;
        $this->requestApi('agent/api/report/agent-dashboard/overview', $this->params())->assertStatus(200);
        $cached = $queries;

        // agent-list 與 overview 的 scope=line 範疇相同，聚合結果同一份
        $queries = 0;
        $this->requestApi('agent/api/report/agent-dashboard/agent-list', $this->params())->assertStatus(200);
        $crossEndpoint = $queries;

        $this->assertGreaterThanOrEqual(7, $first, '首次應實際跑完七支聚合');
        $this->assertLessThan($first - 6, $cached, '同條件重打不得重跑聚合');
        $this->assertLessThan($first - 6, $crossEndpoint, 'agent-list 應共用 overview 已算好的聚合');
    }

    /** 已結束的區間資料不再變動，快取放長；涵蓋今日者仍在累積，只放短 TTL */
    public function test_歷史區間與今日區間套用不同的快取存活時間(): void
    {
        // 釘在 UTC 2026-09-07 22:47＝台北 2026-09-08 06:47：兩個時區的「今日」不同日。
        // aggregateTtl() 必須以使用者時區（params() 預設 tz_offset=8）判今日；
        // 若誤用 app 時區（UTC），下方 $utcToday 的斷言在任何時刻跑都會紅，不再只有 UTC 16:00～24:00 才炸
        Carbon::setTestNow(Carbon::parse('2026-09-07 22:47:00', 'UTC'));

        $service = app(\App\Services\Agent\AgentDashboardService::class);
        $ttl = new \ReflectionMethod($service, 'aggregateTtl');

        // 時間字串一律以台北時間解讀
        $history = $ttl->invoke($service, $this->params()); // 08-11～08-18
        $utcToday = $ttl->invoke($service, $this->params([ // 09-07＝UTC 的今天、台北的昨天
            'start_time' => '2026-09-07 00:00:00',
            'end_time' => '2026-09-07 23:59:59',
        ]));
        $today = $ttl->invoke($service, $this->params([ // 09-08＝台北的今天
            'start_time' => '2026-09-08 00:00:00',
            'end_time' => '2026-09-08 23:59:59',
        ]));

        $this->assertSame(\App\Enums\General\CacheTtl::ONE_HOUR->value, $history);
        $this->assertSame(\App\Enums\General\CacheTtl::ONE_HOUR->value, $utcToday, 'UTC 的今天對 +8 使用者是昨天，仍屬歷史');
        $this->assertSame(\App\Enums\General\CacheTtl::DEFAULT->value, $today);
    }
}
