<?php

declare(strict_types=1);

namespace Tests\Unit\Frontend;

use App\Models\Agent;
use App\Models\AgentVendor;
use App\Models\Member;
use App\Services\VendorService;
use Database\Seeders\SyncAgentSettingDefinesSeeder;
use Tests\TestCase;

/**
 * VendorService 遊戲商服務測試（Frontend）
 *
 * php artisan test tests/Unit/Frontend/VendorServiceTest.php
 */
class VendorServiceTest extends TestCase
{
    private Agent $agent;

    private Member $member;

    private string $loginToken = [REDACTED]';

    private VendorService $vendorService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SyncAgentSettingDefinesSeeder::class);
        $this->agent = $this->createAgentAndDomain();
        $this->member = $this->createMember($this->agent);
        $this->loginToken = [REDACTED];

        $this->agent->vendor_customize_group_id = 1;
        $this->agent->save();

        $this->vendorService = new VendorService;
    }

    public function test_get_vendor_ids_parent_has_agent_vendor_but_self_not()
    {
        $vendor = $this->createBasicVendorGames($this->agent);

        $selfAgent = Agent::factory()->create([
            'parent' => $this->agent->id,
            'vendor_customize_group_id' => $this->agent->vendor_customize_group_id,
            'status' => 1,
        ]);

        $selfAgent->hierarchy_path = "/{$this->agent->id}/{$selfAgent->id}";
        $selfAgent->hierarchy = "[-1,{$this->agent->id},{$selfAgent->id},-2]";
        $selfAgent->save();

        $result = $this->vendorService->getVendorIds($selfAgent);
        $this->assertEquals([$vendor->id], $result);
    }

    public function test_get_vendor_ids_parent_open_but_self_closed()
    {
        $vendor = $this->createBasicVendorGames($this->agent);

        $selfAgent = Agent::factory()->create([
            'parent' => $this->agent->id,
            'vendor_customize_group_id' => $this->agent->vendor_customize_group_id,
            'status' => 1,
        ]);

        $selfAgent->hierarchy_path = "/{$this->agent->id}/{$selfAgent->id}";
        $selfAgent->hierarchy = "[-1,{$this->agent->id},{$selfAgent->id},-2]";
        $selfAgent->save();

        $selfAgentVendor = AgentVendor::create([
            'agent_id' => $selfAgent->id,
            'vendor_id' => $vendor->id,
            'vendor_info_id' => $vendor->vendorInfos->first()->id,
            'status' => 0,
            'self_status' => 0,
            'admin' => 0,
        ]);

        $result = $this->vendorService->getVendorIds($selfAgent);
        $this->assertEquals([], $result);
    }

    public function test_get_vendor_ids_three_level_agent_but_second_level_has_closed()
    {
        $vendor = $this->createBasicVendorGames($this->agent);

        $secondLevelAgent = Agent::factory()->create([
            'parent' => $this->agent->id,
            'vendor_customize_group_id' => $this->agent->vendor_customize_group_id,
            'status' => 1,
        ]);
        $secondLevelAgent->hierarchy_path = "/{$this->agent->id}/{$secondLevelAgent->id}";
        $secondLevelAgent->save();

        $thirdLevelAgent = Agent::factory()->create([
            'parent' => $secondLevelAgent->id,
            'vendor_customize_group_id' => $this->agent->vendor_customize_group_id,
            'status' => 1,
        ]);
        $thirdLevelAgent->hierarchy_path = "/{$this->agent->id}/{$secondLevelAgent->id}/{$thirdLevelAgent->id}";
        $thirdLevelAgent->save();

        $secondLevelAgentVendor = AgentVendor::create([
            'agent_id' => $secondLevelAgent->id,
            'vendor_id' => $vendor->id,
            'vendor_info_id' => $vendor->vendorInfos->first()->id,
            'status' => 0,
            'self_status' => 0,
            'admin' => 0,
        ]);

        $result = $this->vendorService->getVendorIds($thirdLevelAgent);
        $this->assertEquals([], $result);
    }
}
