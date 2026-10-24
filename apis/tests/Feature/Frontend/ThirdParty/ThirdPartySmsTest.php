<?php

declare(strict_types=1);

namespace Tests\Feature\Frontend\ThirdParty;

use App\Enums\General\HttpCode;
use App\Http\Middleware\Frontend\ThirdParty\ThirdPartyDecryptRequest;
use App\Models\Agent;
use App\Models\AgentGroup;
use App\Models\Country;
use App\Models\SmsGateway;
use App\Models\SmsGatewayGroupDetail;
use App\Models\SmsGatewayInfo;
use App\Models\SmsGatewayInfoCountry;
use App\Models\SmsText;
use App\Models\ValidateToken;
use Tests\TestCase;

class ThirdPartySmsTest extends TestCase
{
    private Agent $agent;

    const API_SEND_SMS = '/frontend/api/third-party/send-sms';

    const API_CHECK_CODE = '/frontend/api/third-party/check-code';

    protected function setUp(): void
    {
        parent::setUp();

        $this->agent = $this->createAgentAndDomain([
            'mobile' => '886912345678',
            'sms_gateway_group_id' => 1,
        ]);
        $this->withoutMiddleware(ThirdPartyDecryptRequest::class);

        $country = Country::create([
            'code' => 'TW',
            'name' => 'Taiwan',
            'country_code' => 886,
            'status' => 1,
            'admin' => 0,
        ]);

        $gateway = SmsGateway::create([
            'name' => 'LocalTest',
            'code' => 'local',
            'info' => '{}',
            'url' => 'http://localhost',
            'memo' => '',
            'status' => 1,
            'admin' => 0,
        ]);

        $info = SmsGatewayInfo::create([
            'sms_gateway_group_id' => $this->agent->sms_gateway_group_id,
            'sms_gateway_id' => $gateway->id,
            'info' => '{}',
            'sort' => 0,
            'cost' => 0,
            'status' => 1,
            'admin' => 0,
        ]);

        SmsGatewayInfoCountry::create([
            'sms_gateway_info_id' => $info->id,
            'country_id' => $country->id,
            'status' => 1,
            'admin' => 0,
        ]);

        SmsText::create([
            'agent_id' => $this->agent->id,
            'sms_gateway_group_id' => $this->agent->sms_gateway_group_id,
            'type_id' => 1,
            'country_id' => $country->id,
            'text' => 'Your code is {code}',
            'status' => 1,
            'admin' => 0,
        ]);
    }

    public function test_send_sms_success_creates_new_token()
    {
        $response = $this->requestApi(self::API_SEND_SMS, [
            'from' => $this->agent->username,
            'mobile' => $this->agent->mobile,
            'type' => 1,
            'verify_by' => 1,
        ]);

        $response->assertStatus(HttpCode::HTTP_OK->value);

        $this->assertDatabaseHas('validate_tokens', [
            'agent_id' => $this->agent->id,
            'info' => $this->agent->mobile,
            'status' => 0,
            'times' => 1,
        ]);
    }

    public function test_send_sms_reuses_valid_token_and_increments_times()
    {
        $existing = ValidateToken:[REDACTED]
            'agent_id' => $this->agent->id,
            'member_id' => 0,
            'type_id' => 11,
            'info' => $this->agent->mobile,
            'info_2' => '',
            'token' => '123456',
            'expire_date' => now()->addMinutes(6),
            'status' => 0,
            'times' => 1,
            'admin' => $this->agent->id,
        ]);

        $response = $this->requestApi(self::API_SEND_SMS, [
            'from' => $this->agent->username,
            'mobile' => $this->agent->mobile,
            'type' => 1,
            'verify_by' => 1,
        ]);

        $response->assertStatus(HttpCode::HTTP_OK->value);

        $existing->refresh();
        $this->assertSame(2, $existing->times);
    }

    public function test_send_sms_failed_max_send_reached()
    {
        ValidateToken:[REDACTED]
            'agent_id' => $this->agent->id,
            'member_id' => 0,
            'type_id' => 11,
            'info' => $this->agent->mobile,
            'info_2' => '',
            'token' => '123456',
            'expire_date' => now()->addMinutes(6),
            'status' => 0,
            'times' => 3,
            'admin' => $this->agent->id,
        ]);

        $response = $this->requestApi(self::API_SEND_SMS, [
            'from' => $this->agent->username,
            'mobile' => $this->agent->mobile,
            'type' => 1,
            'verify_by' => 1,
        ]);

        // 迴歸：原本誤用 error_message.2048（已通過實名認證），與此處語意不符；
        // 應為 error_message.2037（已超過發送次數），且 :values 佔位符須完整替換（不留尾巴 s）
        $response->assertStatus(HttpCode::HTTP_BAD_REQUEST->value)
            ->assertJsonPath('code', 2037)
            ->assertJsonPath('message', __('error_message.2037', ['values' => 3]));
    }

    public function test_send_sms_uses_agent_group_setting_for_expiry_and_max_send()
    {
        $agentGroup = AgentGroup::create([
            'agent_group_type' => 3,
            'organization_id' => $this->agent->organization_id,
            'name' => 'sms group',
            'memo' => '',
            'is_default' => 0,
        ]);

        SmsGatewayGroupDetail::create([
            'agent_group_id' => $agentGroup->id,
            'setting' => ['token_expired' => 10, 'max_send' => 1],
            'admin' => 0,
        ]);

        // 第一次發送成功建立 token（times=1）
        $this->requestApi(self::API_SEND_SMS, [
            'from' => $this->agent->username,
            'mobile' => $this->agent->mobile,
            'type' => 1,
            'verify_by' => 1,
        ])->assertStatus(HttpCode::HTTP_OK->value);

        // max_send=1，第二次應直接視為達上限
        $response = $this->requestApi(self::API_SEND_SMS, [
            'from' => $this->agent->username,
            'mobile' => $this->agent->mobile,
            'type' => 1,
            'verify_by' => 1,
        ]);

        $response->assertStatus(HttpCode::HTTP_BAD_REQUEST->value);
    }

    public function test_send_sms_failed_agent_not_found()
    {
        $response = $this->requestApi(self::API_SEND_SMS, [
            'from' => $this->agent->username,
            'mobile' => '0000000000',
            'type' => 1,
            'verify_by' => 1,
        ]);

        $response->assertStatus(HttpCode::HTTP_BAD_REQUEST->value)
            ->assertJson(['message' => __('error_message.1007')]);
    }

    public function test_check_code_success_consumes_pure_verification_token()
    {
        ValidateToken:[REDACTED]
            'agent_id' => $this->agent->id,
            'member_id' => 0,
            'type_id' => 11,
            'info' => $this->agent->mobile,
            'info_2' => '',
            'token' => '654321',
            'expire_date' => now()->addMinutes(6),
            'status' => 0,
            'times' => 1,
            'admin' => $this->agent->id,
        ]);

        $response = $this->requestApi(self::API_CHECK_CODE, [
            'from' => $this->agent->username,
            'token' => '654321',
            'mobile' => $this->agent->mobile,
        ]);

        $response->assertStatus(HttpCode::HTTP_OK->value);

        $this->assertDatabaseHas('validate_tokens', [
            'token' => '654321',
            'status' => 1,
        ]);
    }

    public function test_check_code_failed_wrong_mobile()
    {
        ValidateToken:[REDACTED]
            'agent_id' => $this->agent->id,
            'member_id' => 0,
            'type_id' => 11,
            'info' => $this->agent->mobile,
            'info_2' => '',
            'token' => '111111',
            'expire_date' => now()->addMinutes(6),
            'status' => 0,
            'times' => 1,
            'admin' => $this->agent->id,
        ]);

        $response = $this->requestApi(self::API_CHECK_CODE, [
            'from' => $this->agent->username,
            'token' => '111111',
            'mobile' => '0999999999',
        ]);

        $response->assertStatus(HttpCode::HTTP_BAD_REQUEST->value)
            ->assertJson(['message' => __('error_message.9992')]);
    }

    public function test_check_code_failed_expired_token()
    {
        ValidateToken:[REDACTED]
            'agent_id' => $this->agent->id,
            'member_id' => 0,
            'type_id' => 11,
            'info' => $this->agent->mobile,
            'info_2' => '',
            'token' => '222222',
            'expire_date' => now()->subMinutes(1),
            'status' => 0,
            'times' => 1,
            'admin' => $this->agent->id,
        ]);

        $response = $this->requestApi(self::API_CHECK_CODE, [
            'from' => $this->agent->username,
            'token' => '222222',
            'mobile' => $this->agent->mobile,
        ]);

        $response->assertStatus(HttpCode::HTTP_BAD_REQUEST->value)
            ->assertJson(['message' => __('error_message.9992')]);
    }

    /**
     * 驗證碼必須屬於 from 指定的代理：拿自己帳號（A）的手機與驗證碼，不能替別的代理（V）通過驗證。
     */
    public function test_check_code_failed_token_belongs_to_other_agent()
    {
        $victim = Agent::factory()->create([
            'mobile' => '886900000001',
            'status' => 1,
        ]);

        ValidateToken:[REDACTED]
            'agent_id' => $this->agent->id,
            'member_id' => 0,
            'type_id' => 11,
            'info' => $this->agent->mobile,
            'info_2' => '',
            'token' => '333333',
            'expire_date' => now()->addMinutes(6),
            'status' => 0,
            'times' => 1,
            'admin' => $this->agent->id,
        ]);

        $this->requestApi(self::API_CHECK_CODE, [
            'from' => $victim->username,
            'token' => '333333',
            'mobile' => $this->agent->mobile,
        ])->assertStatus(HttpCode::HTTP_BAD_REQUEST->value)
            ->assertJson(['message' => __('error_message.9992')]);

        // 失敗的嘗試不消耗驗證碼
        $this->assertDatabaseHas('validate_tokens', ['token' => '333333', 'status' => 0]);

        // 同一組驗證碼配回本人帳號（from=A）仍可通過
        $this->requestApi(self::API_CHECK_CODE, [
            'from' => $this->agent->username,
            'token' => '333333',
            'mobile' => $this->agent->mobile,
        ])->assertStatus(HttpCode::HTTP_OK->value);

        $this->assertDatabaseHas('validate_tokens', ['token' => '333333', 'status' => 1]);
    }
}
