<?php

declare(strict_types=1);

namespace Tests\Feature\Frontend\Member;

use App\Enums\General\HttpCode;
use App\Http\Middleware\Frontend\DecryptRequest;
use App\Models\Agent;
use App\Models\Member;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ChangePaymentPasswordTest extends TestCase
{
    protected Agent $agent;

    protected Member $member;

    protected string $token;

    private const string API_URL = 'frontend/api/member/change-payment-password';

    protected function setUp(): void
    {
        parent::setUp();

        // 繞過 DecryptRequest 中間件
        $this->withoutMiddleware(DecryptRequest::class);

        $this->agent = $this->createAgentAndDomain();

        $this->member = $this->createMemberAndToken($this->agent, [
            'username' => 'keroro',
            'email' => 'user@example.invalid',
            'email_validate' => 1,
            'password' => Hash::make('123456'),
        ]);
    }

    /**
     * 首次設定支付密碼
     */
    public function test_set_payment_password_success()
    {
        $payment_password = [REDACTED]';

        $response = $this->requestApi(self::API_URL, [
            'new_payment_password' => $payment_password,
            'new_payment_password_confirmation' => $payment_password,
        ]);

        $this->member->refresh();

        // Assert
        $response->assertStatus(HttpCode::HTTP_OK->value);
        $this->assertTrue(Hash::check($payment_password, $this->member->payment_password));
    }

    /**
     * 修改支付密碼
     */
    public function test_modify_payment_password_success()
    {
        $old_payment_password = [REDACTED]';
        $new_payment_password = [REDACTED]';

        $this->member->update([
            'payment_password' => Hash::make($old_payment_password),
        ]);

        $response = $this->requestApi(self::API_URL, [
            'current_payment_password' => $old_payment_password,
            'new_payment_password' => $new_payment_password,
            'new_payment_password_confirmation' => $new_payment_password,
        ]);

        $this->member->refresh();

        // Assert
        $response->assertStatus(HttpCode::HTTP_OK->value);
        $this->assertTrue(Hash::check($new_payment_password, $this->member->payment_password));
    }

    /**
     * 支付密碼與登入密碼相同
     */
    public function test_payment_password_cannot_match_login_password()
    {
        $new_payment_password = [REDACTED]';

        $response = $this->requestApi(self::API_URL, [
            'new_payment_password' => $new_payment_password,
            'new_payment_password_confirmation' => $new_payment_password,
        ]);

        $this->member->refresh();

        // Assert
        $response->assertStatus(HttpCode::HTTP_BAD_REQUEST->value);
        $response->assertJson([
            'code' => HttpCode::HTTP_BAD_REQUEST->value,
            'message' => __('error_message.1094'),
        ]);
    }
}
