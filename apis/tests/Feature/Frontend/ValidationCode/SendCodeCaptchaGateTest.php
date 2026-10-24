<?php

declare(strict_types=1);

namespace Tests\Feature\Frontend\ValidationCode;

use App\CacheKeys\CacheKeys;
use App\Enums\Agent\AgentCustomizedSettingKeyEnum;
use App\Http\Middleware\Frontend\DecryptRequest;
use App\Models\Agent;
use App\Models\AgentCustomizedSetting;
use App\Models\AgentSettingDefine;
use App\Models\Organization;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

/**
 * 4188 發送驗證碼前的滑塊驗證閘門
 *
 * php artisan test tests/Feature/Frontend/ValidationCode/SendCodeCaptchaGateTest.php
 */
class SendCodeCaptchaGateTest extends TestCase
{
    protected Agent $agent;

    private const string API_CAPTCHA = 'frontend/api/guest/captcha';

    private const string API_CAPTCHA_VERIFY = 'frontend/api/guest/captcha/verify';

    private const string API_SEND_CODE = 'frontend/api/guest/send-validation-code';

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(DecryptRequest::class);

        $organization = Organization::create([
            'name' => '滑塊測試組織',
            'currency_id' => 1,
            'memo' => '',
        ]);

        $this->agent = $this->createAgentAndDomain([
            'organization_id' => $organization->id,
        ]);

        $this->createMember($this->agent, [
            'username' => 'slider01',
            'email' => 'user@example.invalid',
            'email_validate' => 1,
            'password' => Hash::make('123456'),
        ]);
    }

    /**
     * 測試庫只有 migration 內容，setting define 與代理設定值都由測試自己建
     */
    private function enableCaptchaSetting(): void
    {
        AgentSettingDefine::query()->updateOrCreate(
            ['setting_id' => AgentCustomizedSettingKeyEnum::SEND_CODE_CAPTCHA_ENABLED],
            [
                'parent' => 4000,
                'type' => 3,
                'is_visible' => 1,
                'agent_edit' => 0,
                'memo' => '發送驗證碼前需通過滑塊驗證',
                'language_text_code' => 'send_code_captcha_enabled',
                'default_value' => '0',
                'data_type' => 'boolean',
                'is_data_required' => 0,
                'sort' => 0,
                'created_by' => 1,
            ],
        );

        AgentCustomizedSetting::query()->create([
            'agent_group_id' => $this->agent->customized_setting_group_id,
            'setting_key' => AgentCustomizedSettingKeyEnum::SEND_CODE_CAPTCHA_ENABLED,
            'sub_category' => '',
            'value' => '1',
            'memo' => '',
        ]);
    }

    private function passCaptcha(): string
    {
        $question = $this->requestApi(self::API_CAPTCHA, ['domain' => $this->domain])
            ->assertStatus(200)
            ->json('data');

        // 答案不可出現在出題回應裡
        $this->assertArrayNotHasKey('x', $question);

        $answerX = (int) Redis::get(CacheKeys::sliderCaptchaAnswer($question['captcha_id']));

        return $this->requestApi(self::API_CAPTCHA_VERIFY, [
            'domain' => $this->domain,
            'captcha_id' => $question['captcha_id'],
            'x' => $answerX,
        ])->assertStatus(200)->json('data.captcha_token');
    }

    private function sendCodeParams(?string $token): array
    {
        return array_filter([
            'domain' => $this->domain,
            'type' => 2,
            'verify_by' => 2,
            'email' => 'user@example.invalid',
            'captcha_token' => $token,
        ]);
    }

    public function test_開關關閉時不帶_token_也可發送(): void
    {
        $this->requestApi(self::API_SEND_CODE, $this->sendCodeParams(null))
            ->assertStatus(200);
    }

    public function test_開關開啟時缺_token_不得發送(): void
    {
        $this->enableCaptchaSetting();

        $this->requestApi(self::API_SEND_CODE, $this->sendCodeParams(null))
            ->assertStatus(400)
            ->assertJsonPath('code', 2081);
    }

    public function test_開關開啟時帶有效_token_可發送且_token_不可重用(): void
    {
        $this->enableCaptchaSetting();

        $token = [REDACTED];

        $this->requestApi(self::API_SEND_CODE, $this->sendCodeParams($token))
            ->assertStatus(200);

        $this->requestApi(self::API_SEND_CODE, $this->sendCodeParams($token))
            ->assertStatus(400)
            ->assertJsonPath('code', 2081);
    }
}
