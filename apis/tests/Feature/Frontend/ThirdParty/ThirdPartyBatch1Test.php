<?php

declare(strict_types=1);

namespace Tests\Feature\Frontend\ThirdParty;

use App\Enums\Agent\AgentCustomizedSettingKeyEnum;
use App\Enums\General\HttpCode;
use App\Http\Middleware\Frontend\ThirdParty\ThirdPartyDecryptRequest;
use App\Models\Agent;
use App\Models\AgentAccessToken;
use App\Models\Bank;
use App\Models\BankName;
use App\Models\Member;
use Database\Seeders\SyncAgentSettingDefinesSeeder;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ThirdPartyBatch1Test extends TestCase
{
    private Agent $agent;

    private Member $member;

    const API_AGENT_INFO = '/frontend/api/third-party/agent-info';

    const API_LOGIN_SETTING = '/frontend/api/third-party/login-setting';

    const API_CHECK_AGENT_TOKEN = [REDACTED]';

    const API_CHECK_PASSWORD = [REDACTED]';

    const API_CHECK_FLOW_LIMIT = '/frontend/api/third-party/check-flow-limit';

    const API_BANK_LIST = '/frontend/api/third-party/bank-list';

    const API_CS_SETTING = '/frontend/api/third-party/cs-setting';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SyncAgentSettingDefinesSeeder::class);
        $this->agent = $this->createAgentAndDomain();
        $this->withoutMiddleware(ThirdPartyDecryptRequest::class);

        $this->member = $this->createMemberAndToken($this->agent, [
            'username' => 'member1',
            'password' => Hash::make('123456'),
            'payment_password' => Hash::make('654321'),
            'balance' => 0,
        ]);
    }

    public function test_agent_info_success()
    {
        $this->agent->balance = 1000;
        $this->agent->is_balance_unlimited = 1;
        $this->agent->save();

        $response = $this->requestApi(self::API_AGENT_INFO, [
            'from' => $this->agent->username,
        ]);

        $response->assertStatus(HttpCode::HTTP_OK->value)
            ->assertJson([
                'data' => [
                    'balance' => 1000,
                    'is_balance_unlimited' => 1,
                ],
            ]);
    }

    public function test_agent_info_failed_agent_not_found()
    {
        // 代理不存在時，middleware（ThirdPartyAuthMember）已先擋下，不會進到 controller
        $response = $this->requestApi(self::API_AGENT_INFO, [
            'from' => 'no-such-agent',
        ]);

        $response->assertStatus(HttpCode::HTTP_NOT_ACCEPTABLE->value)
            ->assertJson(['message' => __('error_message.1005')]);
    }

    public function test_get_login_setting_success()
    {
        $this->createAgentSetting($this->agent, AgentCustomizedSettingKeyEnum::TWO_FACTOR_AUTHENTICATION_VISIBLE, 1);
        $this->createAgentSetting($this->agent, AgentCustomizedSettingKeyEnum::NEED_MOBILE_VALIDATE, 1);

        $response = $this->requestApi(self::API_LOGIN_SETTING, [
            'from' => $this->agent->username,
        ]);

        $response->assertStatus(HttpCode::HTTP_OK->value)
            ->assertJson([
                'data' => [
                    'id' => $this->agent->id,
                    'username' => $this->agent->username,
                    'f2a_auth' => 1,
                    'need_mobile_validate' => 1,
                ],
            ]);
    }

    public function test_check_agent_token_success()
    {
        AgentAccessToken:[REDACTED]
            'agent_id' => $this->agent->id,
            'token' => 'valid-token',
            'expire_date' => now()->addMinutes(10),
            'admin' => 0,
        ]);

        $response = $this->requestApi(self::API_CHECK_AGENT_TOKEN, [
            'from' => $this->agent->username,
            'agent_username' => $this->agent->username,
            'agent_token' => 'valid-token',
        ]);

        $response->assertStatus(HttpCode::HTTP_OK->value);
    }

    public function test_check_agent_token_failed_expired()
    {
        AgentAccessToken:[REDACTED]
            'agent_id' => $this->agent->id,
            'token' => 'expired-token',
            'expire_date' => now()->subMinutes(10),
            'admin' => 0,
        ]);

        $response = $this->requestApi(self::API_CHECK_AGENT_TOKEN, [
            'from' => $this->agent->username,
            'agent_username' => $this->agent->username,
            'agent_token' => 'expired-token',
        ]);

        $response->assertStatus(HttpCode::HTTP_BAD_REQUEST->value)
            ->assertJson(['message' => __('error_message.1050')]);
    }

    public function test_check_password_success_login_password()
    {
        $response = $this->requestApi(self::API_CHECK_PASSWORD, [
            'username' => $this->member->username,
            'password' => '123456',
            'password_type' => 0,
        ]);

        $response->assertStatus(HttpCode::HTTP_OK->value);
    }

    public function test_check_password_success_payment_password()
    {
        $response = $this->requestApi(self::API_CHECK_PASSWORD, [
            'username' => $this->member->username,
            'password' => '654321',
            'password_type' => 1,
        ]);

        $response->assertStatus(HttpCode::HTTP_OK->value);
    }

    public function test_check_password_failed_wrong_password()
    {
        $response = $this->requestApi(self::API_CHECK_PASSWORD, [
            'username' => $this->member->username,
            'password' => 'wrong1',
            'password_type' => 0,
        ]);

        $response->assertStatus(HttpCode::HTTP_BAD_REQUEST->value)
            ->assertJson(['message' => __('error_message.1007')]);
    }

    public function test_check_flow_limit_success()
    {
        $this->createAgentSetting($this->agent, AgentCustomizedSettingKeyEnum::FLOW_LIMIT_VISIBLE, 1);

        $response = $this->requestApi(self::API_CHECK_FLOW_LIMIT, [
            'username' => $this->member->username,
        ]);

        $response->assertStatus(HttpCode::HTTP_OK->value)
            ->assertJson([
                'data' => [
                    'flow_limit_visable' => 1,
                ],
            ]);
    }

    public function test_get_bank_list_success()
    {
        $bank = Bank::create([
            'agent_group_id' => $this->agent->cash_flow_group_id,
            'type' => 1,
            'name' => 'Default Bank Name',
            'code' => 'BANK01',
            'status' => 1,
        ]);

        BankName::create([
            'bank_id' => $bank->id,
            'language_id' => 1,
            'name' => 'Trans Bank',
        ]);

        $response = $this->requestApi(self::API_BANK_LIST, [
            'from' => $this->agent->username,
            'lang' => 1,
        ]);

        $response->assertStatus(HttpCode::HTTP_OK->value)
            ->assertJson([
                'data' => [
                    [
                        'id' => $bank->id,
                        'name' => 'Trans Bank',
                        'code' => 'BANK01',
                    ],
                ],
            ]);
    }

    public function test_get_bank_list_falls_back_to_default_name_without_translation()
    {
        $bank = Bank::create([
            'agent_group_id' => $this->agent->cash_flow_group_id,
            'type' => 1,
            'name' => 'Default Bank Name',
            'code' => 'BANK02',
            'status' => 1,
        ]);

        $response = $this->requestApi(self::API_BANK_LIST, [
            'from' => $this->agent->username,
            'lang' => 1,
        ]);

        $response->assertStatus(HttpCode::HTTP_OK->value)
            ->assertJson([
                'data' => [
                    [
                        'id' => $bank->id,
                        'name' => 'Default Bank Name',
                        'code' => 'BANK02',
                    ],
                ],
            ]);
    }

    public function test_cs_setting_success()
    {
        $response = $this->requestApi(self::API_CS_SETTING, [
            'from' => $this->agent->username,
        ]);

        $response->assertStatus(HttpCode::HTTP_OK->value);
        $this->assertNotEmpty($response->json('data'));
    }
}
