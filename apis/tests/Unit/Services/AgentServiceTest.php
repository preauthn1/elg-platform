<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\Agent;
use App\Models\AgentDomain;
use App\Services\AgentService;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AgentService 測試
 *
 * php artisan test tests/Unit/Services/AgentServiceTest.php
 */
class AgentServiceTest extends TestCase
{
    private function service(): AgentService
    {
        return new AgentService;
    }

    public function test_get_agent_domains_returns_own_domains_when_agent_has_domain(): void
    {
        $agent = $this->createAgentAndDomain(['username' => 'as_own_' . uniqid()]);

        $domains = $this->service()->getAgentDomains($agent);

        $this->assertCount(1, $domains);
        $this->assertSame($agent->id, $domains->first()->agent_id);
        $this->assertSame($this->domain, $domains->first()->domain);
    }

    public function test_get_agent_domains_returns_parent_domains_when_child_has_no_domain(): void
    {
        $parent = $this->createAgentAndDomain(['username' => 'as_parent_' . uniqid()]);
        $parentDomain = $parent->agentDomains()->first();

        $child = $this->createChildAgent($parent);

        $domains = $this->service()->getAgentDomains($child);

        $this->assertCount(1, $domains);
        $this->assertSame($parent->id, $domains->first()->agent_id);
        $this->assertSame($parentDomain->domain, $domains->first()->domain);
    }

    public function test_get_agent_domains_prefers_nearest_agent_in_hierarchy(): void
    {
        $parent = $this->createAgentAndDomain(['username' => 'as_parent2_' . uniqid()]);

        $child = $this->createChildAgent($parent);
        $childDomain = $child->agentDomains()->create([
            'domain' => 'child-' . uniqid('', true) . '.test',
            'admin' => 1,
        ]);

        $domains = $this->service()->getAgentDomains($child);

        $this->assertCount(1, $domains);
        $this->assertSame($child->id, $domains->first()->agent_id);
        $this->assertSame($childDomain->domain, $domains->first()->domain);
    }

    public function test_get_agent_domains_falls_back_to_farthest_available_ancestor(): void
    {
        $grandParent = $this->createAgentAndDomain(['username' => 'as_gp_' . uniqid()]);
        $grandParentDomain = $grandParent->agentDomains()->first();

        $parent = $this->createChildAgent($grandParent);
        $child = $this->createChildAgent($parent);

        $domains = $this->service()->getAgentDomains($child);

        $this->assertCount(1, $domains);
        $this->assertSame($grandParent->id, $domains->first()->agent_id);
        $this->assertSame($grandParentDomain->domain, $domains->first()->domain);
    }

    public function test_get_agent_domains_returns_all_domains_of_nearest_agent(): void
    {
        $agent = $this->createAgentAndDomain(['username' => 'as_multi_' . uniqid()]);
        $secondaryDomain = $agent->agentDomains()->create([
            'domain' => 'secondary-' . uniqid('', true) . '.test',
            'admin' => 1,
        ]);

        $child = $this->createChildAgent($agent);

        $domains = $this->service()->getAgentDomains($child);

        $this->assertCount(2, $domains);
        $this->assertTrue($domains->every(fn (AgentDomain $agentDomain): bool => $agentDomain->agent_id === $agent->id));
        $this->assertSame(
            [$this->domain, $secondaryDomain->domain],
            $domains->pluck('domain')->all()
        );
    }

    public function test_get_agent_domains_ignores_inactive_domains(): void
    {
        $agent = $this->createAgentAndDomain(['username' => 'as_inactive_' . uniqid()]);
        $agent->agentDomains()->create([
            'domain' => 'inactive-' . uniqid('', true) . '.test',
            'status' => 0,
            'admin' => 1,
        ]);

        $domains = $this->service()->getAgentDomains($agent);

        $this->assertCount(1, $domains);
        $this->assertSame($this->domain, $domains->first()->domain);
    }

    public function test_get_agent_domains_returns_empty_when_hierarchy_has_no_domain(): void
    {
        $agent = Agent::factory()->create([
            'username' => 'as_nodomain_' . uniqid(),
            'code' => Str::upper(Str::random(8)),
            'email' => 'nodomain_' . uniqid() . '@example.invalid',
        ]);
        $agent->hierarchy_path = "/{$agent->id}";
        $agent->hierarchy = "[-1,{$agent->id},-2]";
        $agent->save();

        $domains = $this->service()->getAgentDomains($agent->refresh());

        $this->assertTrue($domains->isEmpty());
    }

    public function test_check_agent_domain_accepts_any_domain_of_nearest_agent(): void
    {
        $agent = $this->createAgentAndDomain(['username' => 'as_check_' . uniqid()]);
        $secondaryDomain = $agent->agentDomains()->create([
            'domain' => 'secondary-' . uniqid('', true) . '.test',
            'admin' => 1,
        ])->domain;

        $child = $this->createChildAgent($agent);

        $this->assertTrue($this->service()->checkAgentDomain($child, $this->domain));
        $this->assertTrue($this->service()->checkAgentDomain($child, $secondaryDomain));
    }

    public function test_check_agent_domain_rejects_unknown_domain(): void
    {
        $agent = $this->createAgentAndDomain(['username' => 'as_reject_' . uniqid()]);
        $child = $this->createChildAgent($agent);

        $this->assertFalse($this->service()->checkAgentDomain($child, 'unknown-' . uniqid('', true) . '.test'));
    }

    public function test_check_agent_domain_returns_false_when_hierarchy_has_no_domain(): void
    {
        $agent = Agent::factory()->create([
            'username' => 'as_check_empty_' . uniqid(),
            'code' => Str::upper(Str::random(8)),
            'email' => 'check_empty_' . uniqid() . '@example.invalid',
        ]);
        $agent->hierarchy_path = "/{$agent->id}";
        $agent->hierarchy = "[-1,{$agent->id},-2]";
        $agent->save();

        $this->assertFalse($this->service()->checkAgentDomain($agent->refresh(), 'any.test'));
    }

    private function createChildAgent(Agent $parent): Agent
    {
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
        $child->hierarchy_path = "{$parent->hierarchy_path}/{$child->id}";
        $child->hierarchy = '[-1,' . $child->id . ',' . $parent->id . ',-2]';
        $child->save();
        $child->refresh();

        return $child;
    }
}
