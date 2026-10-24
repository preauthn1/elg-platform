<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\Agent;
use App\Models\MarqueeWinPoint;
use App\Services\MarqueeService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * MarqueeService 測試
 *
 * php artisan test tests/Unit/Services/MarqueeServiceTest.php
 */
class MarqueeServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->domain = 'marquee-' . uniqid('', true) . '.test';
        Cache::flush();
    }

    private function service(): MarqueeService
    {
        return new MarqueeService;
    }

    public function test_get_trigger_list_returns_empty_when_no_rows(): void
    {
        $agent = $this->createAgentAndDomain();

        $list = $this->service()->getTriggerList($agent);

        $this->assertTrue($list->isEmpty());
    }

    public function test_get_trigger_list_returns_active_rule_for_agent_hierarchy(): void
    {
        $agent = $this->createAgentAndDomain();

        MarqueeWinPoint::create([
            'agent_id' => $agent->id,
            'exclude_agent_id' => 0,
            'vendor_id' => 101,
            'trigger_amount' => '100.0000',
            'status' => 1,
            'admin' => 0,
        ]);

        $list = $this->service()->getTriggerList($agent);

        $this->assertCount(1, $list);
        $this->assertTrue($list->has(101));
        $row = $list->get(101);
        $this->assertSame('100.0000', (string) $row->trigger_amount);
    }

    public function test_get_trigger_list_ignores_inactive_status(): void
    {
        $agent = $this->createAgentAndDomain();

        MarqueeWinPoint::create([
            'agent_id' => $agent->id,
            'exclude_agent_id' => 0,
            'vendor_id' => 102,
            'trigger_amount' => '50.0000',
            'status' => 0,
            'admin' => 0,
        ]);

        $list = $this->service()->getTriggerList($agent);

        $this->assertTrue($list->isEmpty());
    }

    public function test_get_trigger_list_excludes_row_when_exclude_agent_in_hierarchy(): void
    {
        $agent = $this->createAgentAndDomain();

        MarqueeWinPoint::create([
            'agent_id' => $agent->id,
            'exclude_agent_id' => $agent->id,
            'vendor_id' => 103,
            'trigger_amount' => '200.0000',
            'status' => 1,
            'admin' => 0,
        ]);

        $list = $this->service()->getTriggerList($agent);

        $this->assertTrue($list->isEmpty());
    }

    public function test_get_trigger_list_same_vendor_prefers_descendant_agent_rule(): void
    {
        $parent = $this->createAgentAndDomain(['username' => 'parent_' . uniqid()]);

        $child = Agent::factory()->create([
            'parent' => $parent->id,
            'username' => 'c_' . uniqid(),
            'code' => Str::upper(Str::random(8)),
            'email' => 'c_' . uniqid() . '@example.invalid',
            'organization_id' => $parent->organization_id,
            'customized_setting_group_id' => $parent->customized_setting_group_id,
            'vendor_customize_group_id' => $parent->vendor_customize_group_id,
            'cash_flow_group_id' => $parent->cash_flow_group_id,
            'sms_gateway_group_id' => $parent->sms_gateway_group_id,
            'role_id' => $parent->role_id,
        ]);
        $child->hierarchy_path = "/{$parent->id}/{$child->id}";
        $child->hierarchy = '[-1,' . $parent->id . ',' . $child->id . ',-2]';
        $child->save();
        $child->refresh();

        MarqueeWinPoint::create([
            'agent_id' => $parent->id,
            'exclude_agent_id' => 0,
            'vendor_id' => 200,
            'trigger_amount' => '10.0000',
            'status' => 1,
            'admin' => 0,
        ]);
        MarqueeWinPoint::create([
            'agent_id' => $child->id,
            'exclude_agent_id' => 0,
            'vendor_id' => 200,
            'trigger_amount' => '99.0000',
            'status' => 1,
            'admin' => 0,
        ]);

        $list = $this->service()->getTriggerList($child);

        $this->assertCount(1, $list);
        $this->assertSame('99.0000', (string) $list->get(200)->trigger_amount);
    }

    public function test_get_trigger_list_uses_cache(): void
    {
        $agent = $this->createAgentAndDomain();

        $first = $this->service()->getTriggerList($agent);
        $this->assertTrue($first->isEmpty());

        MarqueeWinPoint::create([
            'agent_id' => $agent->id,
            'exclude_agent_id' => 0,
            'vendor_id' => 300,
            'trigger_amount' => '1.0000',
            'status' => 1,
            'admin' => 0,
        ]);

        $second = $this->service()->getTriggerList($agent);
        $this->assertTrue($second->isEmpty());

        Cache::flush();

        $third = $this->service()->getTriggerList($agent);
        $this->assertCount(1, $third);
        $this->assertTrue($third->has(300));
    }
}
