<?php

declare(strict_types=1);

namespace Tests\Unit\Services\AgentService;

use App\Enums\Agent\AgentPersonalSettingKeyEnum;
use App\Models\Agent;
use App\Services\AgentService;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

/**
 * AgentService::getMaintainAgents 單元測試
 *
 * php artisan test tests/Unit/Services/AgentService/GetMaintainAgentsTest.php
 */
class GetMaintainAgentsTest extends TestCase
{
    private Agent $agent;

    private AgentService $service;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();

        $this->agent = $this->createAgentAndDomain();
        $this->service = new AgentService;
    }

    #[TestDox('沒有任何維護設定時，回傳空集合')]
    public function test_returns_empty_collection_when_no_maintenance_configured(): void
    {
        $result = $this->service->getMaintainAgents();

        $this->assertTrue($result->isEmpty());
    }

    #[TestDox('代理維護中時，回傳該代理資訊（含 agent_id、start_time、end_time）')]
    public function test_returns_agent_when_under_maintenance(): void
    {
        $this->createAgentPersonalSetting($this->agent, AgentPersonalSettingKeyEnum::AGENT_MAINTAIN_FLAG, '1');
        $this->createAgentPersonalSetting($this->agent, AgentPersonalSettingKeyEnum::AGENT_MAINTAIN_START_TIME, gmdate('Y-m-d H:i:s', strtotime('-1 hour')));
        $this->createAgentPersonalSetting($this->agent, AgentPersonalSettingKeyEnum::AGENT_MAINTAIN_END_TIME, gmdate('Y-m-d H:i:s', strtotime('+1 hour')));

        $result = $this->service->getMaintainAgents();

        $this->assertCount(1, $result);
        $this->assertEquals($this->agent->id, $result->first()->agent_id);
        $this->assertNotNull($result->first()->start_time);
        $this->assertNotNull($result->first()->end_time);
    }

    #[TestDox('維護已結束時（end_time 在過去），不回傳該代理')]
    public function test_excludes_agent_when_maintenance_has_ended(): void
    {
        $this->createAgentPersonalSetting($this->agent, AgentPersonalSettingKeyEnum::AGENT_MAINTAIN_FLAG, '1');
        $this->createAgentPersonalSetting($this->agent, AgentPersonalSettingKeyEnum::AGENT_MAINTAIN_START_TIME, gmdate('Y-m-d H:i:s', strtotime('-2 hours')));
        $this->createAgentPersonalSetting($this->agent, AgentPersonalSettingKeyEnum::AGENT_MAINTAIN_END_TIME, gmdate('Y-m-d H:i:s', strtotime('-1 hour')));

        $result = $this->service->getMaintainAgents();

        $this->assertTrue($result->isEmpty());
    }

    #[TestDox('維護尚未開始時（start_time 在未來），不回傳該代理')]
    public function test_excludes_agent_when_maintenance_has_not_started(): void
    {
        $this->createAgentPersonalSetting($this->agent, AgentPersonalSettingKeyEnum::AGENT_MAINTAIN_FLAG, '1');
        $this->createAgentPersonalSetting($this->agent, AgentPersonalSettingKeyEnum::AGENT_MAINTAIN_START_TIME, gmdate('Y-m-d H:i:s', strtotime('+1 hour')));
        $this->createAgentPersonalSetting($this->agent, AgentPersonalSettingKeyEnum::AGENT_MAINTAIN_END_TIME, gmdate('Y-m-d H:i:s', strtotime('+2 hours')));

        $result = $this->service->getMaintainAgents();

        $this->assertTrue($result->isEmpty());
    }

    #[TestDox('維護開關關閉（flag≠1）時，不回傳該代理')]
    public function test_excludes_agent_when_flag_is_off(): void
    {
        $this->createAgentPersonalSetting($this->agent, AgentPersonalSettingKeyEnum::AGENT_MAINTAIN_FLAG, '0');
        $this->createAgentPersonalSetting($this->agent, AgentPersonalSettingKeyEnum::AGENT_MAINTAIN_START_TIME, gmdate('Y-m-d H:i:s', strtotime('-1 hour')));
        $this->createAgentPersonalSetting($this->agent, AgentPersonalSettingKeyEnum::AGENT_MAINTAIN_END_TIME, gmdate('Y-m-d H:i:s', strtotime('+1 hour')));

        $result = $this->service->getMaintainAgents();

        $this->assertTrue($result->isEmpty());
    }

    #[TestDox('結果會被快取，第二次呼叫不重查資料庫')]
    public function test_result_is_cached(): void
    {
        $this->createAgentPersonalSetting($this->agent, AgentPersonalSettingKeyEnum::AGENT_MAINTAIN_FLAG, '1');
        $this->createAgentPersonalSetting($this->agent, AgentPersonalSettingKeyEnum::AGENT_MAINTAIN_START_TIME, gmdate('Y-m-d H:i:s', strtotime('-1 hour')));
        $this->createAgentPersonalSetting($this->agent, AgentPersonalSettingKeyEnum::AGENT_MAINTAIN_END_TIME, gmdate('Y-m-d H:i:s', strtotime('+1 hour')));

        $first = $this->service->getMaintainAgents();

        // 快取存在後刪除 DB 資料，第二次仍應從快取取得
        \App\Models\AgentPersonalSetting::where('agent_id', $this->agent->id)->delete();

        $second = $this->service->getMaintainAgents();

        $this->assertCount(1, $second);
        $this->assertEquals($first->first()->agent_id, $second->first()->agent_id);
    }
}
