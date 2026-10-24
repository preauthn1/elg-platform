<?php

declare(strict_types=1);

namespace Tests\Feature\Frontend\ThirdParty;

use App\Enums\Agent\AgentCustomizedSettingKeyEnum;
use App\Enums\General\HttpCode;
use App\Enums\Transaction\TransactionEnum;
use App\Http\Middleware\Frontend\ThirdParty\ThirdPartyDecryptRequest;
use App\Models\Agent;
use App\Models\FlowLimit;
use App\Models\Member;
use App\Models\MemberBindingLog;
use Database\Seeders\SyncAgentSettingDefinesSeeder;
use Tests\TestCase;

class ThirdPartyInfoTest extends TestCase
{
    private Agent $agent;

    private Member $member;

    const API_INFO = '/frontend/api/third-party/info';

    protected function setUp(): void
    {
        parent::setUp();

        // 同 ThirdPartyResponseCodeContractTest：本檔多支測試以 createAgentSetting 分岔，
        // 而 getAgentSettings() 以 agent_setting_defines 為 join 主表，缺 defines 就全部取不到設定
        $this->seed(SyncAgentSettingDefinesSeeder::class);

        $this->agent = $this->createAgentAndDomain();
        $this->withoutMiddleware(ThirdPartyDecryptRequest::class);

        $this->member = $this->createMemberAndToken($this->agent, [
            'username' => 'infomember',
            'balance' => 500,
            'realname_validate' => 0,
        ]);
    }

    public function test_info_success_basic_fields()
    {
        $response = $this->requestApi(self::API_INFO, [
            'username' => $this->member->username,
        ]);

        $response->assertStatus(HttpCode::HTTP_OK->value)
            ->assertJson([
                'data' => [
                    'id' => $this->member->id,
                    'username' => $this->member->username,
                    'balance' => 500,
                    'flow_limit_visible' => 0,
                    'has_login_event_reward' => 0,
                ],
            ]);
    }

    public function test_info_failed_member_not_found()
    {
        // 會員不存在時，middleware（ThirdPartyAuthMember）已先擋下，不會進到 controller
        $response = $this->requestApi(self::API_INFO, [
            'username' => 'no-such-member',
        ]);

        $response->assertStatus(HttpCode::HTTP_NOT_ACCEPTABLE->value)
            ->assertJson(['message' => __('error_message.1005')]);
    }

    public function test_info_computes_flow_limit_when_visible()
    {
        $this->createAgentSetting($this->agent, AgentCustomizedSettingKeyEnum::FLOW_LIMIT_VISIBLE, 1);

        FlowLimit::create([
            'member_id' => $this->member->id,
            'type' => TransactionEnum::DEPOSIT->value,
            'event_id' => 0,
            'reference_id' => 0,
            'flow_limit' => 300,
            'reach' => 100,
            'status' => 0,
            'admin' => 0,
        ]);

        $response = $this->requestApi(self::API_INFO, [
            'username' => $this->member->username,
        ]);

        $response->assertStatus(HttpCode::HTTP_OK->value)
            ->assertJson([
                'data' => [
                    'flow_limit_visible' => 1,
                    'flow_limit' => 200,
                ],
            ]);
    }

    public function test_info_realname_validate_passed_forced_when_required()
    {
        $this->createAgentSetting($this->agent, AgentCustomizedSettingKeyEnum::TRANSFER_WITHDRAW_REALNAME_REQUIRED, 1);

        $response = $this->requestApi(self::API_INFO, [
            'username' => $this->member->username,
        ]);

        $response->assertStatus(HttpCode::HTTP_OK->value)
            ->assertJson([
                'data' => [
                    'realname_validate_passed' => 0,
                ],
            ]);
    }

    public function test_info_realname_validate_passed_defaults_to_true_when_not_required()
    {
        $response = $this->requestApi(self::API_INFO, [
            'username' => $this->member->username,
        ]);

        $response->assertStatus(HttpCode::HTTP_OK->value)
            ->assertJson([
                'data' => [
                    'realname_validate_passed' => 1,
                ],
            ]);
    }

    public function test_info_modify_binding_true_when_recently_changed_and_setting_enabled()
    {
        $this->createAgentSetting($this->agent, AgentCustomizedSettingKeyEnum::MODIFY_BINDING_LIMIT_WITHDRAWAL, 1);

        MemberBindingLog::create([
            'member_id' => $this->member->id,
            'type' => 1,
            'before_value' => '0911111111',
            'value' => '0922222222',
            'ip' => '0.0.0.0',
            'validate_type' => 1,
            'status' => 1,
            'admin' => 0,
        ]);

        $response = $this->requestApi(self::API_INFO, [
            'username' => $this->member->username,
        ]);

        $response->assertStatus(HttpCode::HTTP_OK->value)
            ->assertJson([
                'data' => [
                    'modify_binding' => true,
                ],
            ]);
    }

    public function test_info_modify_binding_false_when_setting_disabled()
    {
        MemberBindingLog::create([
            'member_id' => $this->member->id,
            'type' => 1,
            'before_value' => '0911111111',
            'value' => '0922222222',
            'ip' => '0.0.0.0',
            'validate_type' => 1,
            'status' => 1,
            'admin' => 0,
        ]);

        $response = $this->requestApi(self::API_INFO, [
            'username' => $this->member->username,
        ]);

        $response->assertStatus(HttpCode::HTTP_OK->value)
            ->assertJson([
                'data' => [
                    'modify_binding' => false,
                ],
            ]);
    }

    /**
     * target/op_tickets#2833：修改綁定限制窗口須為 24 小時，而非誤沿用函式預設的 7 天，
     * 否則玩家換手機號 24 小時後仍會被持續擋轉出。
     */
    public function test_info_modify_binding_false_when_changed_more_than_24_hours_ago()
    {
        $this->createAgentSetting($this->agent, AgentCustomizedSettingKeyEnum::MODIFY_BINDING_LIMIT_WITHDRAWAL, 1);

        $log = MemberBindingLog::create([
            'member_id' => $this->member->id,
            'type' => 1,
            'before_value' => '0911111111',
            'value' => '0922222222',
            'ip' => '0.0.0.0',
            'validate_type' => 1,
            'status' => 1,
            'admin' => 0,
        ]);
        $log->created_at = now()->subDays(3);
        $log->save();

        $response = $this->requestApi(self::API_INFO, [
            'username' => $this->member->username,
        ]);

        $response->assertStatus(HttpCode::HTTP_OK->value)
            ->assertJson([
                'data' => [
                    'modify_binding' => false,
                ],
            ]);
    }
}
