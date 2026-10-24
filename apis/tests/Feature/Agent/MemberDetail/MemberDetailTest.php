<?php

declare(strict_types=1);

namespace Tests\Feature\Agent\MemberDetail;

use App\Enums\RollingChipLogTypeEnum;
use App\Enums\Transaction\TransactionEnum;
use App\Enums\WalletEnum;
use App\Http\Middleware\Frontend\DecryptRequest;
use App\Http\Middleware\RequestLogger;
use App\Models\Agent;
use App\Models\AgentAccessToken;
use App\Models\AgentMenu;
use App\Models\AgentPermission;
use App\Models\Member;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * 會員詳情抽屜（4274）：view／statistics／charts 三接口。
 * 口徑依 4274 口徑提案定案版（16 項、真金＋禮碼共計、發放總額含未領取）。
 *
 * php artisan test tests/Feature/Agent/MemberDetail/MemberDetailTest.php
 */
class MemberDetailTest extends TestCase
{
    private Agent $agent;

    private Member $member;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([
            DecryptRequest::class,
            RequestLogger::class,
        ]);

        $this->agent = $this->createAgentAndDomain();
        $this->member = $this->createMember($this->agent, [
            'username' => 'md' . bin2hex(random_bytes(6)),
            'balance' => '100.5',
            'first_name' => 'DrawerTester',
            'mobile' => '60123456789',
        ]);

        $token = [REDACTED];
        AgentAccessToken:[REDACTED]
            'agent_id' => $this->agent->id,
            'token' => $token,
            'expire_date' => now()->addHours(2)->toDateTimeString(),
            'admin' => 1,
        ]);
        $this->addHeader('Authorization', 'Bearer ' . $token);
    }

    private function api(string $action, array $payload): TestResponse
    {
        return $this->postJson('agent/api/member/detail/' . $action, $payload, $this->headers);
    }

    /** 建立另一棵代理樹的會員（跨線探針用） */
    private function outsideMember(): Member
    {
        // createAgentAndDomain 共用 $this->domain，第二棵樹需換新域名避免撞唯一鍵
        $this->domain = Str::random(12) . '.test.local';
        $otherAgent = $this->createAgentAndDomain([
            'code' => substr(bin2hex(random_bytes(8)), 0, 10),
            'username' => 'x' . substr(bin2hex(random_bytes(8)), 0, 15),
            'email' => bin2hex(random_bytes(6)) . '@t.ag',
        ]);

        return $this->createMember($otherAgent, ['username' => 'out' . bin2hex(random_bytes(5))]);
    }

    // ── view ──

    public function test_view_returns_profile_with_created_at_and_wallets(): void
    {
        DB::table('member_wallets')->insert([
            'member_id' => $this->member->id,
            'wallet_type_id' => WalletEnum::CODE_ROLLING_CHIP->value,
            'currency_id' => 1,
            'balance' => '12.3',
            'status' => 1,
        ]);
        DB::table('member_codes')->insert([
            'member_id' => $this->member->id,
            'agent_code' => 'RC123456',
        ]);
        // 稽核流水：兩筆未通過（100−30.5 ＋ 50−0）、一筆已通過（不計）、一筆取消（不計）
        DB::table('flow_limits')->insert([
            ['member_id' => $this->member->id, 'type' => 1, 'reference_id' => 1, 'flow_limit' => '100', 'reach' => '30.5', 'status' => 0, 'admin' => 0],
            ['member_id' => $this->member->id, 'type' => 1, 'reference_id' => 2, 'flow_limit' => '50', 'reach' => '0', 'status' => 0, 'admin' => 0],
            ['member_id' => $this->member->id, 'type' => 1, 'reference_id' => 3, 'flow_limit' => '999', 'reach' => '999', 'status' => 1, 'admin' => 0],
            ['member_id' => $this->member->id, 'type' => 1, 'reference_id' => 4, 'flow_limit' => '777', 'reach' => '0', 'status' => 9, 'admin' => 0],
        ]);

        $response = $this->api('view', ['member_id' => $this->member->id]);

        $response->assertStatus(200)->assertJson(['code' => 200]);
        $data = $response->json('data');

        $this->assertSame($this->member->username, $data['username']);
        $this->assertSame($this->agent->username, $data['agent_username']);
        $this->assertSame('100.5000', $data['balance']);
        $this->assertSame('12.3000', $data['rolling_chip'], '獎勵錢包（禮碼）');
        $this->assertSame('RC123456', $data['referral_code']);
        $this->assertSame('150.0000', $data['flow_limit_total'], '只計未通過的流水限制');
        $this->assertSame('30.5000', $data['flow_limit_reach']);
        $this->assertSame('119.5000', $data['flow_limit_gap'], '稽核流水差額＝限制−達成');
        $this->assertSame('DrawerTester', $data['realname']);
        $this->assertNotSame('', $data['created_at'], '註冊日期必回（舊 member/view 缺漏的欄位）');
        $this->assertNull($data['realname_request_id'], '從未申請實名驗證');
        $this->assertNull($data['realname_request_status']);
    }

    public function test_view_returns_latest_realname_request_state(): void
    {
        // 兩筆申請：舊的已拒絕、新的待審核 → 回最新一筆（待審核）供抽屜顯示與跳轉審核
        DB::table('realname_validation_requests')->insert([
            'member_id' => $this->member->id,
            'username' => $this->member->username,
            'realname' => 'Old Name',
            'status' => 2,
            'remark' => 'blurry',
        ]);
        $pendingId = DB::table('realname_validation_requests')->insertGetId([
            'member_id' => $this->member->id,
            'username' => $this->member->username,
            'realname' => 'DrawerTester',
            'status' => 0,
        ]);

        $response = $this->api('view', ['member_id' => $this->member->id]);

        $response->assertStatus(200)->assertJson(['code' => 200]);
        $this->assertSame($pendingId, $response->json('data.realname_request_id'));
        $this->assertSame(0, $response->json('data.realname_request_status'), '0=待審核');
    }

    public function test_view_blocks_member_outside_scope(): void
    {
        $outside = $this->outsideMember();

        $response = $this->api('view', ['member_id' => $outside->id]);

        $this->assertNotSame(200, $response->json('code'), '跨線會員必須被後端擋下（C-T7）');
    }

    public function test_view_member_not_found(): void
    {
        $response = $this->api('view', ['member_id' => 999999999]);

        $this->assertNotSame(200, $response->json('code'));
    }

    public function test_view_by_username_resolves_member_id(): void
    {
        // 只有帳號字串的入口（聊天表頭、部分報表列）：以 username 查並回 member_id
        $response = $this->api('view', ['username' => $this->member->username]);

        $response->assertStatus(200)->assertJson(['code' => 200]);
        $this->assertSame($this->member->id, $response->json('data.member_id'));
    }

    public function test_view_by_username_blocks_outside_scope(): void
    {
        $outside = $this->outsideMember();

        $response = $this->api('view', ['username' => $outside->username]);

        $this->assertNotSame(200, $response->json('code'));
    }

    public function test_view_masks_mobile_without_reveal_permission(): void
    {
        // 測試庫 agent_menus 為空（fail-open 放行），需先建碼讓權限判定生效
        $menu = AgentMenu::create([
            'code' => 'reveal_phone_number',
            'name' => '顯示電話',
            'parent' => 0,
            'url' => '',
            'symbol' => '',
            'action' => '',
            'sort' => 0,
            'status' => 1,
            'admin' => 0,
        ]);

        $masked = $this->api('view', ['member_id' => $this->member->id]);
        $this->assertSame('-', $masked->json('data.mobile'), '無權限：服務端遮蔽（C-T4）');

        AgentPermission::create([
            'agent_id' => $this->agent->id,
            'agent_menu_id' => $menu->id,
            'action' => 'view',
            'admin' => 0,
        ]);

        $visible = $this->api('view', ['member_id' => $this->member->id]);
        $this->assertSame('60123456789', $visible->json('data.mobile'), '有權限：原文顯示');
    }

    public function test_view_masks_email_without_reveal_email_permission(): void
    {
        // 會員信箱為空時服務端直接回空字串，先給值才驗得到遮罩
        $this->member->update(['email' => bin2hex(random_bytes(6)) . '@t.ag']);

        // 測試庫 agent_menus 為空（fail-open 放行），需先建碼讓權限判定生效
        $menu = AgentMenu::create([
            'code' => 'reveal_email',
            'name' => '顯示電子郵件',
            'parent' => 0,
            'url' => '',
            'symbol' => '',
            'action' => '',
            'sort' => 0,
            'status' => 1,
            'admin' => 0,
        ]);

        $masked = $this->api('view', ['member_id' => $this->member->id]);
        $this->assertSame('-', $masked->json('data.email'), '無權限：服務端遮蔽（4495）');

        AgentPermission::create([
            'agent_id' => $this->agent->id,
            'agent_menu_id' => $menu->id,
            'action' => 'view',
            'admin' => 0,
        ]);

        $visible = $this->api('view', ['member_id' => $this->member->id]);
        $this->assertSame($this->member->email, $visible->json('data.email'), '有權限：原文顯示');
    }

    // ── statistics ──

    public function test_statistics_full_period_all_sources(): void
    {
        $mid = $this->member->id;

        // 投注：兩日匯總（bet 100+200、win_lose -30+10）
        DB::table('summary_bet_records')->insert([
            ['summary_time' => '2026-08-01 00:00:00', 'data_time' => '2026-08-01 00:00:00', 'member_id' => $mid, 'vendor_id' => 1, 'game_id' => 1, 'bet' => 100, 'win_lose' => -30],
            ['summary_time' => '2026-08-02 00:00:00', 'data_time' => '2026-08-02 00:00:00', 'member_id' => $mid, 'vendor_id' => 1, 'game_id' => 1, 'bet' => 200, 'win_lose' => 10],
        ]);

        // VIP：每日返水（vip_reward_id=7）50、生日獎金（≠7）30；含未領取（status 0 也計）
        DB::table('vip_rewards')->insertOrIgnore([
            ['id' => 7, 'name' => '每日返水', 'status' => 1, 'admin' => 0],
            ['id' => 2, 'name' => '生日獎金', 'status' => 1, 'admin' => 0],
        ]);
        $rebateRewardId = DB::table('vip_content_rewards')->insertGetId([
            'vip_content_id' => 1, 'vip_reward_id' => 7, 'type' => 1, 'max_point' => 0,
            'point_type' => 1, 'condition' => 0, 'point' => 0, 'point_percentage' => 0,
            'expired_hour' => 0, 'flow_limit_multiple' => 0, 'status' => 1, 'admin' => 0,
        ]);
        $birthdayRewardId = DB::table('vip_content_rewards')->insertGetId([
            'vip_content_id' => 1, 'vip_reward_id' => 2, 'type' => 0, 'max_point' => 0,
            'point_type' => 2, 'condition' => 0, 'point' => 30, 'point_percentage' => 0,
            'expired_hour' => 0, 'flow_limit_multiple' => 0, 'status' => 1, 'admin' => 0,
        ]);
        DB::table('member_vip_prizes')->insert([
            ['member_id' => $mid, 'vip_content_reward_id' => $rebateRewardId, 'point_type' => 1, 'base_amount' => 0, 'point' => 50, 'status' => 1, 'admin' => 0],
            ['member_id' => $mid, 'vip_content_reward_id' => $birthdayRewardId, 'point_type' => 2, 'base_amount' => 0, 'point' => 30, 'status' => 0, 'admin' => 0],
        ]);

        // 三層代累計桶：自身返水 40、下線抽成 25+5
        DB::table('three_hierarchy_summary_bonus')->insert([
            'member_id' => $mid, 'rebate_amount' => 40, 'down_rebate_amount' => 25,
            'down2_rebate_amount' => 5, 'bonus_amount' => 70, 'withdraw_amount' => 0, 'admin' => 0,
        ]);

        // 優惠獎金：真金（活動獎勵 13 → +80、登入回收 16 → −20）＋禮碼（登入活動 7 → +15）
        $this->insertTransaction($mid, TransactionEnum::ACTIVITY_AWARD->value, 80);
        $this->insertTransaction($mid, TransactionEnum::RECYCLE_LOGIN_ACTIVITY_REWARDS->value, 20);
        $this->insertRollingChip($mid, RollingChipLogTypeEnum::LOGIN_EVENT_REWARD->value, 15);
        // 干擾項：VIP 真金入帳（type 32）不得混入優惠獎金（已由 member_vip_prizes 涵蓋）
        $this->insertTransaction($mid, TransactionEnum::VIP_REWARD->value, 999);

        // 推薦獎金：真金 26 → 12、禮碼 13 → 8
        $this->insertTransaction($mid, TransactionEnum::INVITE_PLAYER_OFFERS->value, 12);
        $this->insertRollingChip($mid, RollingChipLogTypeEnum::INVITE_PLAYER_REWARD->value, 8);

        // 存提：皆走 hourly 匯總（存款 pay_time／提款 payment_time 分桶）＋ member_statistics 秒級最近時間
        DB::table('member_hourly_statistics')->insert([
            ['member_id' => $mid, 'summary_hour' => '2026-08-01 10:00:00', 'deposit_count' => 2, 'deposit_amount' => 300, 'withdrawal_count' => 1, 'withdrawal_amount' => 100],
            ['member_id' => $mid, 'summary_hour' => '2026-08-02 11:00:00', 'deposit_count' => 1, 'deposit_amount' => 100, 'withdrawal_count' => 0, 'withdrawal_amount' => 0],
            ['member_id' => $mid, 'summary_hour' => '2026-08-03 09:00:00', 'deposit_count' => 0, 'deposit_amount' => 0, 'withdrawal_count' => 1, 'withdrawal_amount' => 50],
        ]);
        DB::table('member_statistics')->insert([
            'member_id' => $mid,
            'last_deposit_at' => '2026-08-02 11:22:33',
            'last_withdraw_at' => '2026-08-03 09:05:44',
        ]);

        // 最近存提時間要轉後台時區（target/tickets#4426）；.env.testing 未設 BACKEND_TIMEZONE，測試內釘住 +8
        config(['custom.backend.timezone' => 'Asia/Taipei']);

        $response = $this->api('statistics', ['member_id' => $mid]);

        $response->assertStatus(200)->assertJson(['code' => 200]);
        $data = $response->json('data');

        // 投注（對帳關係式：總中獎 − 投注 ＝ 會員輸贏 ＝ −公司輸贏）
        $this->assertSame('300.0000', $data['bet_amount']);
        $this->assertSame('-20.0000', $data['member_win_lose']);
        $this->assertSame('280.0000', $data['total_payout']);
        $this->assertSame('20.0000', $data['company_win_lose']);
        // 彩金與獎勵：返水＝VIP 每日返水 50＋三層代自身 40；優惠＝80−20＋15；VIP＝30（含未領取）
        $this->assertSame('90.0000', $data['rebate']);
        $this->assertSame('75.0000', $data['event_bonus'], '回收類負向扣回、VIP type 32 不混入');
        $this->assertSame('30.0000', $data['vip_bonus']);
        $this->assertSame('195.0000', $data['rewards_total']);
        // 推薦：佣金＝下線抽成 25＋5；獎金＝12＋8
        $this->assertSame('30.0000', $data['referral_commission']);
        $this->assertSame('20.0000', $data['referral_bonus']);
        // 存提（全期間：金額／筆數走 hourly 四欄合計，最近時間取 member_statistics 秒級欄位並轉 +8）
        $this->assertSame(3, $data['deposit_count']);
        $this->assertSame('400.0000', $data['deposit_amount']);
        $this->assertSame('2026-08-02 19:22:33', $data['last_deposit_at']);
        $this->assertSame(2, $data['withdrawal_count']);
        $this->assertSame('150.0000', $data['withdrawal_amount']);
        $this->assertSame('2026-08-03 17:05:44', $data['last_withdraw_at']);

        // 期間篩選：次數與金額只切到 8/1～8/2 的桶；最近存提時間仍是全期間最後一筆，不隨期間變（target/tickets#4426）。
        // 4438 第 8 項起期間改送後台時區牆鐘 start_time／end_time（UTC 7/31 16:00～8/2 15:59:59），
        // hourly 桶存 UTC：8/1 10:00、8/2 11:00 在內，8/3 09:00 在外
        $inPeriod = $this->api('statistics', [
            'member_id' => $mid,
            'start_time' => '2026-08-01 00:00:00',
            'end_time' => '2026-08-02 23:59:59',
        ])->json('data');
        $this->assertSame(3, $inPeriod['deposit_count']);
        $this->assertSame('400.0000', $inPeriod['deposit_amount']);
        $this->assertSame('2026-08-02 19:22:33', $inPeriod['last_deposit_at'], '期間不影響最近存款時間');
        $this->assertSame(1, $inPeriod['withdrawal_count'], '8/3 的提款桶不在期間內');
        $this->assertSame('100.0000', $inPeriod['withdrawal_amount']);
        $this->assertSame('2026-08-03 17:05:44', $inPeriod['last_withdraw_at'], '最後提款在期間外（8/3）也照樣顯示');
    }

    public function test_statistics_period_filter_switches_three_hierarchy_to_daily_report(): void
    {
        $mid = $this->member->id;

        // 累計桶（全期間口徑）：自身 100／下線 60
        DB::table('three_hierarchy_summary_bonus')->insert([
            'member_id' => $mid, 'rebate_amount' => 100, 'down_rebate_amount' => 60,
            'down2_rebate_amount' => 0, 'bonus_amount' => 160, 'withdraw_amount' => 0, 'admin' => 0,
        ]);
        // 日報表：期間內 8/10 一筆（自身 30／下線 20）、期間外 7/01 一筆
        DB::table('three_hierarchy_bonus_report')->insert([
            ['summary_time' => '2026-08-10 00:00:00', 'rebate_type' => 1, 'reward_type' => 1, 'agent_id' => 0, 'member_id' => $mid, 'from_member_id' => $mid, 'vendor_id' => 1, 'game_type_id' => 1, 'bet' => 0, 'down_bet' => 0, 'down2_bet' => 0, 'rebate' => 30, 'down_rebate' => 20, 'down2_rebate' => 0, 'admin' => 0],
            ['summary_time' => '2026-07-01 00:00:00', 'rebate_type' => 1, 'reward_type' => 1, 'agent_id' => 0, 'member_id' => $mid, 'from_member_id' => $mid, 'vendor_id' => 1, 'game_type_id' => 1, 'bet' => 0, 'down_bet' => 0, 'down2_bet' => 0, 'rebate' => 70, 'down_rebate' => 40, 'down2_rebate' => 0, 'admin' => 0],
        ]);

        $fullPeriod = $this->api('statistics', ['member_id' => $mid]);
        $this->assertSame('100.0000', $fullPeriod->json('data.rebate'), '全期間走累計桶');
        $this->assertSame('60.0000', $fullPeriod->json('data.referral_commission'));

        // 三層代日報以牆鐘的日期標籤對（4438 第 8 項），8/10 在內、7/01 在外
        $inPeriod = $this->api('statistics', [
            'member_id' => $mid,
            'start_time' => '2026-08-01 00:00:00',
            'end_time' => '2026-08-31 23:59:59',
        ]);
        $this->assertSame('30.0000', $inPeriod->json('data.rebate'), '期間篩選走日報表');
        $this->assertSame('20.0000', $inPeriod->json('data.referral_commission'));
    }

    public function test_statistics_empty_member_returns_zeros_not_null(): void
    {
        $response = $this->api('statistics', ['member_id' => $this->member->id]);

        $response->assertStatus(200)->assertJson(['code' => 200]);
        $data = $response->json('data');

        // 工單規則：無資料顯示 0，不是空白或 null
        $this->assertSame('0.0000', $data['bet_amount']);
        $this->assertSame('0.0000', $data['rewards_total']);
        $this->assertSame(0, $data['deposit_count']);
        $this->assertSame('', $data['last_deposit_at']);
    }

    public function test_statistics_blocks_member_outside_scope(): void
    {
        $outside = $this->outsideMember();

        $response = $this->api('statistics', ['member_id' => $outside->id]);

        $this->assertNotSame(200, $response->json('code'));
    }

    // ── charts ──

    public function test_charts_transfer_grouped_by_vendor(): void
    {
        $mid = $this->member->id;
        $this->insertTransaction($mid, TransactionEnum::TO_VENDOR->value, 100, vendorId: 61);
        $this->insertTransaction($mid, TransactionEnum::TO_VENDOR->value, 50, vendorId: 61);
        $this->insertTransaction($mid, TransactionEnum::FROM_VENDOR->value, 70, vendorId: 61);

        $response = $this->api('charts', ['member_id' => $mid]);

        $response->assertStatus(200)->assertJson(['code' => 200]);
        $data = $response->json('data');

        $this->assertSame(90, $data['window_days']);
        $this->assertSame('150.0000', $data['transfer_in']['items'][0]['total']);
        $this->assertSame('70.0000', $data['transfer_out']['items'][0]['total']);
        $this->assertSame('0.0000', $data['transfer_in']['others_total']);
        $this->assertSame([], $data['deposit_methods']);
    }

    // ── fixtures ──

    private function insertTransaction(int $memberId, int $type, float $point, int $vendorId = 0): void
    {
        DB::table('transaction_logs')->insert([
            'transaction_no' => 'T' . bin2hex(random_bytes(8)),
            'agent_id' => $this->agent->id,
            'member_id' => $memberId,
            'vendor_id' => $vendorId,
            'order_id' => 0,
            'type' => $type,
            'rate' => 1,
            'point' => $point,
            'vendor_point' => 0,
            'before_balance' => 0,
            'balance' => 0,
            'status' => 1000,
            'remark' => '4274 test',
            'operator' => 0,
            'admin' => 0,
        ]);
    }

    private function insertRollingChip(int $memberId, int $type, float $amount): void
    {
        DB::table('rolling_chip_logs')->insert([
            'agent_id' => $this->agent->id,
            'member_id' => $memberId,
            'vendor_id' => 0,
            'type' => $type,
            'amount' => $amount,
            'before_balance' => 0,
            'balance' => 0,
            'status' => 1000,
            'remark' => '4274 test',
            'admin' => 0,
        ]);
    }
}
