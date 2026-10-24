<?php

declare(strict_types=1);

namespace Tests\Feature\Frontend\EventV2;

use App\Enums\Agent\AgentCustomizedSettingKeyEnum;
use App\Enums\EventV2\EventV2UserJoinStatusEnum;
use App\Enums\WalletEnum;
use App\Http\Middleware\Frontend\DecryptRequest;
use App\Models\Agent;
use App\Models\AgentCustomizedSetting;
use App\Models\EventV2Detail;
use App\Models\EventV2DetailLang;
use App\Models\EventV2UserJoin;
use App\Models\Member;
use App\Models\MemberWallet;
use App\Models\TotalBetRecord;
use Database\Seeders\LanguageSeeder;
use Database\Seeders\SyncAgentSettingDefinesSeeder;
use Tests\TestCase;

/**
 * php artisan test tests/Feature/Frontend/EventV2/WithdrawTest.php
 */
class WithdrawTest extends TestCase
{
    private Agent $agent;

    private ?Member $member = null;

    private string $apiPath = 'frontend/api/event-v2/withdraw';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(LanguageSeeder::class);
        $this->seed(SyncAgentSettingDefinesSeeder::class);

        $this->agent = $this->createAgentAndDomain();

        $this->member = $this->createMemberAndToken($this->agent);

        $this->creatEventDetailWithJoin($this->agent);

        // 繞過 DecryptRequest 中間件
        $this->withoutMiddleware(DecryptRequest::class);
    }

    /**
     * 建立活動詳細與預設 JOIN 記錄（balance_limit=300, withdraw_limit=10, flow_limit=null）
     */
    private function creatEventDetailWithJoin(Agent $agent): void
    {
        EventV2Detail::factory()->create([
            'id' => 2,
            'event_v2_id' => 2,
            'agent_id' => $agent->id,
            'condition_formula' => null,
            'condition_config' => null,
            'reward_formula' => '19',
            'reward_config' => null,
            'balance_formula' => '300',
            'balance_config' => null,
            'withdraw_formula' => '10',
            'withdraw_config' => null,
            'flow_formula' => null,
            'flow_config' => null,
        ]);
        EventV2DetailLang::create([
            'event_v2_detail_id' => 2,
            'language_id' => 2, // zh-TW
            'name' => '每日 免费 AUD 19',
            'image' => 'image',
            'reward_name' => '每日 免费 AUD 19',
            'content' => '每日 免费 AUD 19',
        ]);

        EventV2UserJoin::insert([
            'event_v2_detail_id' => 2,
            'member_id' => $this->member->id,
            'reward' => '19',
            'balance_limit' => '300',
            'withdraw_limit' => '10',
            'flow_limit' => null,
            'status' => EventV2UserJoinStatusEnum::JOIN->value,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    // ─── 正常情境 ───────────────────────────────────────────────────────────────

    /** 成功提領：餘額足夠、withdraw_limit=10，回傳 withdraw=10 */
    public function test_withdraw_success(): void
    {
        MemberWallet::create([
            'member_id' => $this->member->id,
            'wallet_type_id' => WalletEnum::CODE_EVENT_WALLET,
            'status' => 1,
            'balance' => 300,
        ]);

        $response = $this->requestApi($this->apiPath, []);

        $response->assertStatus(200)
            ->assertJson([
                'code' => 200,
                'message' => '成功',
                'data' => [
                    'withdraw' => 10,
                ],
            ]);
    }

    /** 無 balance_limit 時略過餘額檢查，成功提領 withdraw_limit 金額 */
    public function test_withdraw_success_without_balance_limit(): void
    {
        // 更新 join 使 balance_limit 為 null
        EventV2UserJoin::where('member_id', $this->member->id)
            ->update(['balance_limit' => null]);

        MemberWallet::create([
            'member_id' => $this->member->id,
            'wallet_type_id' => WalletEnum::CODE_EVENT_WALLET,
            'status' => 1,
            'balance' => 50,
        ]);

        $response = $this->requestApi($this->apiPath, []);

        $response->assertStatus(200)
            ->assertJson([
                'code' => 200,
                'data' => [
                    'withdraw' => 10, // withdraw_limit=10
                ],
            ]);
    }

    /** 無 withdraw_limit 時以整個錢包餘額提領 */
    public function test_withdraw_success_without_withdraw_limit(): void
    {
        EventV2UserJoin::where('member_id', $this->member->id)
            ->update([
                'balance_limit' => null,
                'withdraw_limit' => null,
            ]);

        MemberWallet::create([
            'member_id' => $this->member->id,
            'wallet_type_id' => WalletEnum::CODE_EVENT_WALLET,
            'status' => 1,
            'balance' => 50,
        ]);

        $response = $this->requestApi($this->apiPath, []);

        $response->assertStatus(200)
            ->assertJson([
                'code' => 200,
                'data' => [
                    'withdraw' => 50, // 全數領回
                ],
            ]);
    }

    /** 有 flow_limit 且流水已達標，成功提領 */
    public function test_withdraw_success_with_flow_met(): void
    {
        EventV2UserJoin::where('member_id', $this->member->id)
            ->update([
                'balance_limit' => null,
                'flow_limit' => '100',
            ]);

        MemberWallet::create([
            'member_id' => $this->member->id,
            'wallet_type_id' => WalletEnum::CODE_EVENT_WALLET,
            'status' => 1,
            'balance' => 50,
        ]);

        $userJoin = EventV2UserJoin::where('member_id', $this->member->id)->first();

        // 新增流水記錄，有效投注 >= 100
        TotalBetRecord::insert([
            'member_id' => $this->member->id,
            'vendor_id' => 1,
            'bet_record_id' => 1,
            'game_id' => 1,
            'reference_no' => 'TEST-001',
            'valid_bet' => '120',
            'bet_time' => $userJoin->created_at,
            'payout_time' => $userJoin->created_at,
            'summary_time' => now()->toDateTimeString(),
        ]);

        $response = $this->requestApi($this->apiPath, []);

        $response->assertStatus(200)
            ->assertJson([
                'code' => 200,
                'data' => ['withdraw' => 10],
            ]);
    }

    // ─── 錯誤情境 ─────────────────────────────────────────────────────────────────

    /** 無任何進行中活動（JOIN），回傳 501 */
    public function test_withdraw_no_join(): void
    {
        // 將 JOIN 改為 COMPLETE，使查詢找不到進行中記錄
        EventV2UserJoin::where('member_id', $this->member->id)
            ->update(['status' => EventV2UserJoinStatusEnum::COMPLETE->value]);

        MemberWallet::create([
            'member_id' => $this->member->id,
            'wallet_type_id' => WalletEnum::CODE_EVENT_WALLET,
            'status' => 1,
            'balance' => 300,
        ]);

        $response = $this->requestApi($this->apiPath, []);

        $response->assertStatus(501)
            ->assertJson([
                'code' => 501,
                'message' => '您尚未參加任何活動',
            ]);
    }

    /** 活動錢包餘額不足（wallet.balance < balance_limit），回傳 502 */
    public function test_withdraw_balance_insufficient(): void
    {
        MemberWallet::create([
            'member_id' => $this->member->id,
            'wallet_type_id' => WalletEnum::CODE_EVENT_WALLET,
            'status' => 1,
            'balance' => 299, // < balance_limit=300
        ]);

        $response = $this->requestApi($this->apiPath, []);

        $response->assertStatus(502)
            ->assertJson([
                'code' => 502,
                'message' => '活動錢包餘額不足',
            ]);
    }

    /** 流水未達標（flow < flow_limit），回傳 503 */
    public function test_withdraw_flow_insufficient(): void
    {
        EventV2UserJoin::where('member_id', $this->member->id)
            ->update([
                'balance_limit' => null,
                'flow_limit' => '100',
            ]);

        MemberWallet::create([
            'member_id' => $this->member->id,
            'wallet_type_id' => WalletEnum::CODE_EVENT_WALLET,
            'status' => 1,
            'balance' => 300,
        ]);

        // 無任何下注記錄，流水 = 0 < 100

        $response = $this->requestApi($this->apiPath, []);

        $response->assertStatus(503)
            ->assertJson([
                'code' => 503,
                'message' => '流水不足',
            ]);
    }

    /** 代理設定需手機驗證但會員未驗證，回傳 324（轉跳綁定手機） */
    public function test_withdraw_mobile_validate_required(): void
    {
        // 設定代理組別：需要手機驗證
        AgentCustomizedSetting::create([
            'agent_group_id' => $this->agent->customized_setting_group_id,
            'setting_key' => AgentCustomizedSettingKeyEnum::WITHDRAWAL_HAS_MOBILE_VALIDATE,
            'value' => '1',
            'sub_category' => '',
            'memo' => '',
        ]);

        // 確保會員手機未驗證
        $this->member->update(['mobile_validate' => 0, 'mobile' => '']);

        MemberWallet::create([
            'member_id' => $this->member->id,
            'wallet_type_id' => WalletEnum::CODE_EVENT_WALLET,
            'status' => 1,
            'balance' => 300,
        ]);

        $response = $this->requestApi($this->apiPath, []);

        $response->assertStatus(324)
            ->assertJson([
                'code' => 324,
                'message' => '手機號碼未認證',
            ]);
    }

    // ─── 身份驗證 ─────────────────────────────────────────────────────────────────

    /** 未帶 Authorization token，回傳 319（HTTP_REDIRECT_LOGOUT） */
    public function test_withdraw_unauthenticated(): void
    {
        $response = $this->postJson(
            uri: $this->apiPath,
            data: [],
            headers: ['Accept-Language' => 'zh-TW'],
        );

        $response->assertStatus(319);
    }
}
