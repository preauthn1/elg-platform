<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\AgentLoginIpLog;
use App\Services\BotNotifyService;
use App\Services\RiskService;
use Mockery;
use Tests\TestCase;

/**
 * RiskService 測試
 *
 * php artisan test tests/Unit/Services/RiskServiceTest.php
 */
class RiskServiceTest extends TestCase
{
    public function test_detect_ip_risk_logs_agent_login_ip(): void
    {
        $botNotify = Mockery::mock(BotNotifyService::class);
        $botNotify->shouldReceive('channel')->andReturnSelf();
        $botNotify->shouldReceive('send')->zeroOrMoreTimes();
        $this->instance(BotNotifyService::class, $botNotify);

        $agent = $this->createAgentAndDomain();
        $before = AgentLoginIpLog::query()->where('agent_id', $agent->id)->count();

        (new RiskService)->detectIpRisk($agent);

        $after = AgentLoginIpLog::query()->where('agent_id', $agent->id)->count();

        $this->assertSame($before + 1, $after);
        $log = AgentLoginIpLog::query()->where('agent_id', $agent->id)->latest('id')->first();
        $this->assertNotNull($log);
        $this->assertSame(127, (int) $log->ip_1);
        $this->assertSame(1, (int) $log->ip_4);
    }
}
