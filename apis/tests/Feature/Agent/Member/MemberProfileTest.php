<?php

declare(strict_types=1);

namespace Tests\Feature\Agent\Member;

use App\Enums\Agent\AgentCustomizedSettingKeyEnum;
use App\Enums\WalletEnum;
use App\Http\Middleware\Frontend\DecryptRequest;
use App\Http\Middleware\RequestLogger;
use App\Models\Agent;
use App\Models\AgentAccessToken;
use App\Models\AgentCustomizedSetting;
use App\Models\Member;
use App\Models\MemberWallet;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * 讀單一會員資料（target/tickets#4438，由 API_Agent member/view 移植）。
 *
 * 必須成立：
 * 1. 回開洗分彈窗、補點彈窗、會員編輯頁用到的欄位（帳號、上級、主錢包、禮碼、積分、編輯頁各欄）。
 * 2. 不回會員支付密碼雜湊。
 * 3. 範圍外會員擋下。
 *
 * php artisan test tests/Feature/Agent/Member/MemberProfileTest.php
 */
class MemberProfileTest extends TestCase
{
    private Agent $agent;

    private Member $member;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([DecryptRequest::class, RequestLogger::class]);
        $this->agent = $this->createAgentAndDomain();
        $this->member = $this->createMember($this->agent, [
            'username' => 'mp' . bin2hex(random_bytes(5)),
            'balance' => '123.4500',
            'email' => 'user@example.invalid',
            'payment_password' => Hash::make('654321'),
        ]);

        $token = [REDACTED];
        AgentAccessToken:[REDACTED]'agent_id' => $this->agent->id, 'token' => $token, 'expire_date' => '2099-01-01 00:00:00', 'admin' => 1]);
        $this->addHeader('Authorization', 'Bearer ' . $token);
    }

    public function test_returns_fields_used_by_backend_pages_without_payment_password_hash(): void
    {
        MemberWallet::query()->create(['member_id' => $this->member->id, 'wallet_type_id' => WalletEnum::CODE_ROLLING_CHIP->value, 'balance' => '20']);
        MemberWallet::query()->create(['member_id' => $this->member->id, 'wallet_type_id' => WalletEnum::CODE_SCORE->value, 'balance' => '7.5']);
        AgentCustomizedSetting::query()->create([
            'agent_group_id' => $this->agent->customized_setting_group_id,
            'setting_key' => AgentCustomizedSettingKeyEnum::PAYMENT_PASSWORD_BUTTON_VISIBLE,
            'sub_category' => '',
            'value' => '1',
            'memo' => '',
        ]);

        $data = $this->profile($this->member->id)->assertOk()->assertJsonPath('code', 200)->json('data');

        $this->assertSame($this->member->id, $data['id']);
        $this->assertSame($this->member->username, $data['username']);
        $this->assertSame($this->agent->username, $data['agent_username']);
        $this->assertSame('123.4500', $data['balance']);
        $this->assertSame('20.0000', $data['rolling_chip']);
        $this->assertSame('7.5000', $data['score']);
        $this->assertSame('user@example.invalid', $data['email']);
        $this->assertSame(1, $data['payment_password_visible']);
        foreach (['nickname', 'first_name', 'birthday', 'mobile', 'country_id', 'city', 'address', 'status', 'mobile_validate', 'realname_validate', 'ip_change_mobile_verify', 'ip_change_email_verify', 'member_group_id'] as $key) {
            $this->assertArrayHasKey($key, $data, "會員編輯頁要用 {$key}");
        }
        $this->assertArrayNotHasKey('payment_password', $data, '不回支付密碼雜湊');
    }

    public function test_member_without_wallets_returns_zero_balances(): void
    {
        $data = $this->profile($this->member->id)->assertOk()->json('data');

        $this->assertSame('0.0000', $data['rolling_chip']);
        $this->assertSame('0.0000', $data['score']);
        $this->assertSame(0, $data['payment_password_visible'], '沒設自訂值回 0，同舊接口');
    }

    public function test_member_outside_agent_tree_is_rejected(): void
    {
        // createAgentAndDomain 共用 $this->domain，第二棵樹需換新域名避免撞唯一鍵
        $this->domain = 'mp' . bin2hex(random_bytes(5)) . '.test.local';
        $outsider = $this->createMember($this->createAgentAndDomain(), ['username' => 'mpo' . bin2hex(random_bytes(5))]);

        $this->assertNotSame(200, $this->profile($outsider->id)->json('code'));
    }

    private function profile(int $memberId): TestResponse
    {
        return $this->postJson('agent/api/member/profile', ['member_id' => $memberId], $this->headers);
    }
}
