<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\Agent;
use App\Models\AgentVendor;
use App\Models\Vendor;
use App\Models\VendorInfo;
use App\Services\VendorService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * VendorService::getRateList 測試（api/vendor/rate/list；tpower-master 與 vw-master 逐行完全相同）
 *
 * php artisan test tests/Unit/Services/VendorRateListTest.php
 */
class VendorRateListTest extends TestCase
{
    private VendorService $service;

    private Agent $agent;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(VendorService::class);
        $this->agent = $this->createAgentAndDomain();
    }

    private function makeAgentVendor(Agent $agent, array $vendorOverrides = []): Vendor
    {
        $vendor = Vendor::factory()->create(array_merge(['status' => 1], $vendorOverrides));
        $vendorInfo = VendorInfo::factory()->create(['vendor_id' => $vendor->id, 'status' => 1]);
        AgentVendor::create([
            'agent_id' => $agent->id,
            'vendor_id' => $vendor->id,
            'vendor_info_id' => $vendorInfo->id,
            'status' => 1,
            'admin' => 0,
        ]);

        return $vendor;
    }

    public function test_returns_configured_vendors_with_default_rate(): void
    {
        $vendor = $this->makeAgentVendor($this->agent, ['name' => 'legacy vendor', 'valid_bet_rate' => '0.80']);

        $result = $this->service->getRateList($this->agent);

        $this->assertCount(1, $result);
        $this->assertSame($vendor->id, (int) $result[0]->id);
        $this->assertSame('legacy vendor', $result[0]->name);
        $this->assertSame('0.80', (string) $result[0]->unlimit_rate);
    }

    public function test_uses_vendor_image_override_when_present(): void
    {
        $vendor = $this->makeAgentVendor($this->agent, ['name' => 'legacy vendor', 'valid_bet_rate' => '0.80']);
        DB::table('vendor_images')->insert([
            'agent_group_id' => $this->agent->vendor_customize_group_id,
            'vendor_id' => $vendor->id,
            'name' => 'custom name',
            'image' => '',
            'app_icon' => '',
            'valid_bet_rate' => '0.50',
            'admin' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $result = $this->service->getRateList($this->agent);

        $this->assertSame('custom name', $result[0]->name);
        $this->assertSame('0.50', (string) $result[0]->unlimit_rate);
    }

    public function test_returns_empty_when_no_agent_in_hierarchy_has_configured_vendors(): void
    {
        Vendor::factory()->create(['status' => 1]);

        $result = $this->service->getRateList($this->agent);

        $this->assertCount(0, $result);
    }

    public function test_falls_back_to_parent_agent_configuration(): void
    {
        $parent = Agent::factory()->create(['status' => 1]);
        $parent->hierarchy_path = "/{$parent->id}";
        $parent->save();

        $this->agent->parent = $parent->id;
        $this->agent->hierarchy_path = "/{$parent->id}/{$this->agent->id}";
        $this->agent->save();

        $vendor = $this->makeAgentVendor($parent, ['name' => 'parent vendor']);

        $result = $this->service->getRateList($this->agent->fresh());

        $this->assertCount(1, $result);
        $this->assertSame($vendor->id, (int) $result[0]->id);
    }

    public function test_excludes_disabled_agent_vendor(): void
    {
        $vendor = Vendor::factory()->create(['status' => 1]);
        $vendorInfo = VendorInfo::factory()->create(['vendor_id' => $vendor->id, 'status' => 1]);
        AgentVendor::create([
            'agent_id' => $this->agent->id,
            'vendor_id' => $vendor->id,
            'vendor_info_id' => $vendorInfo->id,
            'status' => 0,
            'admin' => 0,
        ]);

        $result = $this->service->getRateList($this->agent);

        $this->assertCount(0, $result);
    }
}
