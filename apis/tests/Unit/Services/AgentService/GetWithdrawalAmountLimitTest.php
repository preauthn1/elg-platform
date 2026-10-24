<?php

declare(strict_types=1);

namespace Tests\Unit\Services\AgentService;

use App\Models\Agent;
use App\Models\AgentCashFlow;
use App\Services\AgentService;
use Database\Seeders\CashFlowSeeder;
use Tests\TestCase;

/**
 * AgentService 取得提款金額上限測試
 *
 * php artisan test tests/Unit/Services/AgentService/GetWithdrawalAmountLimitTest.php
 */
class GetWithdrawalAmountLimitTest extends TestCase
{
    protected Agent $agent;

    protected AgentCashFlow $agentCashFlow;

    protected string $token;

    protected AgentService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CashFlowSeeder::class);
        $this->agent = $this->createAgentAndDomain();

        $this->service = new AgentService;
    }

    public function test_success()
    {
        $this->agentCashFlow = $this->createAgentCashFlow($this->agent);
        // Act
        $result = $this->service->getWithdrawalAmountLimit($this->agent);
        // Assert JSON 結構
        $this->assertArrayHasKey('min_amount', $result);
        $this->assertArrayHasKey('max_amount', $result);
        // Assert 值
        $this->assertEquals(50, $result['min_amount']);
        $this->assertEquals(1000, $result['max_amount']);
    }

    public function test_empty()
    {
        // Act
        $result = $this->service->getWithdrawalAmountLimit($this->agent);
        // Assert JSON 結構
        $this->assertArrayHasKey('min_amount', $result);
        $this->assertArrayHasKey('max_amount', $result);
        // Assert 值
        $this->assertEquals(0, $result['min_amount']);
        $this->assertEquals(0, $result['max_amount']);
    }
}
