<?php

declare(strict_types=1);

namespace Tests\Feature\Agent\Vendor;

use App\Http\Middleware\Frontend\DecryptRequest;
use App\Http\Middleware\RequestLogger;
use App\Models\Agent;
use App\Models\AgentAccessToken;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * agent/api/vendor/balance 的入境守門測試（4005）。
 *
 * 只涵蓋碰到 Vendor Driver 之前就攔下的路徑（會員不存在／越權／vendor 不存在／驗證），
 * 實際餘額查詢需真打三方，不在此測。
 *
 * php artisan test tests/Feature/Agent/Vendor/BalanceTest.php
 */
class BalanceTest extends TestCase
{
    private string $apiPath = 'agent/api/vendor/balance';

    private Agent $agent;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([
            DecryptRequest::class,
            RequestLogger::class,
        ]);

        // host_id 明確給 0：host_id=1（admin 子帳號）會跳過下線範疇檢查
        $this->agent = $this->createAgentAndDomain(['host_id' => 0]);

        $token = [REDACTED];
        AgentAccessToken:[REDACTED]
            'agent_id' => $this->agent->id,
            'token' => $token,
            'expire_date' => now()->addHours(2)->toDateTimeString(),
            'admin' => 1,
        ]);
        $this->addHeader('Authorization', 'Bearer ' . $token);
    }

    private function act(array $payload): TestResponse
    {
        return $this->postJson($this->apiPath, $payload, $this->headers);
    }

    public function test_會員不存在時回報1007(): void
    {
        $response = $this->act([
            'member_id' => 999999999,
            'vendor_id' => 1,
        ]);

        $response->assertJsonPath('message', __('error_message.1007'));
    }

    public function test_查詢非自己下線的會員時回報權限不足(): void
    {
        // createAgentAndDomain 會用 $this->domain 建 agent_domains（唯一鍵），第二個代理需換網域
        $originalDomain = $this->domain;
        $this->domain = Str::random(12) . '.test.local';
        $otherAgent = $this->createAgentAndDomain(['host_id' => 0]);
        $this->domain = $originalDomain;

        $otherMember = $this->createMember($otherAgent);

        $response = $this->act([
            'member_id' => $otherMember->id,
            'vendor_id' => 1,
        ]);

        $response->assertJsonPath('message', __('error_message.9003'));
    }

    public function test_下線會員但vendor不存在時回報9994(): void
    {
        $member = $this->createMember($this->agent);

        $response = $this->act([
            'member_id' => $member->id,
            'vendor_id' => 999999,
        ]);

        $response->assertJsonPath('message', __('error_message.9994') . '(1)');
    }

    public function test_缺少必填參數時回驗證錯誤(): void
    {
        $this->act(['vendor_id' => 1])->assertStatus(400);
        $this->act(['member_id' => 1])->assertStatus(400);
    }
}
