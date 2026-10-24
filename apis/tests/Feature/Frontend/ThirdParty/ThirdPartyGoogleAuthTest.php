<?php

declare(strict_types=1);

namespace Tests\Feature\Frontend\ThirdParty;

use App\Enums\General\HttpCode;
use App\Http\Middleware\Frontend\ThirdParty\ThirdPartyDecryptRequest;
use App\Models\Agent;
use App\Models\AgentAuthentication;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class ThirdPartyGoogleAuthTest extends TestCase
{
    private Agent $agent;

    const API_GOOGLE_AUTH = '/frontend/api/third-party/google-auth';

    protected function setUp(): void
    {
        parent::setUp();

        $this->agent = $this->createAgentAndDomain();
        $this->withoutMiddleware(ThirdPartyDecryptRequest::class);
    }

    public function test_google_auth_success()
    {
        $secretKey = (new Google2FA)->generateSecretKey();

        // 舊系統 API_Agent 寫入明文 secret，不經過本 Model 的加密 accessor，故測試以 forceFill 模擬真實資料
        $auth = new AgentAuthentication;
        $auth->forceFill([
            'agent_id' => $this->agent->id,
            'type' => 1,
            'status' => 1,
            'secret' => $secretKey,
            'admin' => 0,
        ])->save();

        $otp = (new Google2FA)->getCurrentOtp($secretKey);

        $response = $this->requestApi(self::API_GOOGLE_AUTH, [
            'from' => $this->agent->username,
            'secret' => $otp,
        ]);

        $response->assertStatus(HttpCode::HTTP_OK->value);
    }

    public function test_google_auth_failed_wrong_code()
    {
        $secretKey = (new Google2FA)->generateSecretKey();

        // 舊系統 API_Agent 寫入明文 secret，不經過本 Model 的加密 accessor，故測試以 forceFill 模擬真實資料
        $auth = new AgentAuthentication;
        $auth->forceFill([
            'agent_id' => $this->agent->id,
            'type' => 1,
            'status' => 1,
            'secret' => $secretKey,
            'admin' => 0,
        ])->save();

        $response = $this->requestApi(self::API_GOOGLE_AUTH, [
            'from' => $this->agent->username,
            'secret' => '000000',
        ]);

        $response->assertStatus(HttpCode::HTTP_BAD_REQUEST->value)
            ->assertJson(['message' => __('error_message.9992')]);
    }

    public function test_google_auth_failed_no_authentication_setup()
    {
        $response = $this->requestApi(self::API_GOOGLE_AUTH, [
            'from' => $this->agent->username,
            'secret' => '123456',
        ]);

        $response->assertStatus(HttpCode::HTTP_BAD_REQUEST->value)
            ->assertJson(['message' => __('error_message.9992')]);
    }
}
