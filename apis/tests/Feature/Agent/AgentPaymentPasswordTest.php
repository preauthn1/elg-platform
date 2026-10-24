<?php

declare(strict_types=1);

namespace Tests\Feature\Agent;

use App\Http\Middleware\Frontend\DecryptRequest;
use App\Http\Middleware\RequestLogger;
use App\Models\Agent;
use App\Models\AgentAccessToken;
use App\Models\AgentOperateGeneralLog;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * 驗證操作者自己的支付密碼（target/tickets#4438，由 API_Agent verifyAgentPaymentPassword 移植）。
 *
 * 必須成立：
 * 1. 只驗登入者本人：密碼對過、錯擋；沒設支付密碼一律不過。
 * 2. 帶別人的 agent_id 也只驗自己（舊接口可以拿來試別人的支付密碼，新接口不收這個參數）。
 * 3. 一般操作紀錄照舊寫，但支付密碼不落明文。
 *
 * php artisan test tests/Feature/Agent/AgentPaymentPasswordTest.php
 */
class AgentPaymentPasswordTest extends TestCase
{
    private Agent $agent;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([DecryptRequest::class, RequestLogger::class]);
        $this->agent = $this->createAgentAndDomain();
        $this->agent->forceFill(['payment_password' => Hash::make('123456')])->save();
        $this->loginAs($this->agent);
    }

    public function test_verifies_only_the_operator_own_payment_password(): void
    {
        $this->verify(['payment_password' => '123456'])->assertOk()->assertJsonPath('code', 200);
        $this->assertNotSame(200, $this->verify(['payment_password' => '999999'])->json('code'));
    }

    public function test_agent_id_of_someone_else_is_ignored(): void
    {
        $other = Agent::factory()->create(['payment_password' => Hash::make('888888')]);

        // 別人的密碼＋別人的 id：只會跟自己的比，失敗
        $this->assertNotSame(200, $this->verify(['payment_password' => '888888', 'agent_id' => $other->id])->json('code'));
        // 自己的密碼帶別人的 id：仍以自己為準，成功
        $this->verify(['payment_password' => '123456', 'agent_id' => $other->id])->assertJsonPath('code', 200);
    }

    public function test_operator_without_payment_password_never_passes(): void
    {
        $noPassword = [REDACTED]'payment_password' => null, 'parent' => $this->agent->id]);
        $this->loginAs($noPassword);

        $this->assertNotSame(200, $this->verify(['payment_password' => '123456'])->json('code'));
    }

    public function test_general_log_does_not_store_payment_password_in_plain_text(): void
    {
        $this->verify(['payment_password' => '123456'])->assertOk();

        $log = AgentOperateGeneralLog::query()
            ->where('operator_id', $this->agent->id)
            ->where('page_path', 'like', '%payment-password/verify%')
            ->latest('id')
            ->first();
        $this->assertNotNull($log, '照舊寫一般操作紀錄');
        $this->assertStringNotContainsString('123456', (string) json_encode($log->content), '支付密碼不得明文落地');
    }

    private function loginAs(Agent $agent): void
    {
        $token = [REDACTED];
        AgentAccessToken:[REDACTED]'agent_id' => $agent->id, 'token' => $token, 'expire_date' => '2099-01-01 00:00:00', 'admin' => 1]);
        $this->addHeader('Authorization', 'Bearer ' . $token);
    }

    private function verify(array $payload): TestResponse
    {
        return $this->postJson('agent/api/payment-password/verify', $payload, $this->headers);
    }
}
