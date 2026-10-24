<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Enums\Vendor\VendorInfoTypeEnum;
use App\Models\Agent;
use App\Models\AgentVendor;
use App\Models\Vendor;
use App\Models\VendorCustomizedItem;
use App\Models\VendorInfo;
use App\Services\VendorService;
use Illuminate\Support\Facades\App;
use Tests\TestCase;

/**
 * VendorService 遊戲商服務測試
 *
 * php artisan test tests/Unit/Services/VendorServiceTest.php
 */
class VendorServiceTest extends TestCase
{
    protected Agent $grandParent;

    protected Agent $parent;

    protected Agent $agent;

    protected Vendor $vendor;

    protected VendorInfo $vendorInfo;

    protected VendorInfo $vendorInfo2;

    protected VendorInfo $eventVendorInfo;

    protected VendorCustomizedItem $vendorCustomizedItem;

    protected function setUp(): void
    {
        parent::setUp();
        // 建立三層代理
        $this->grandParent = Agent::factory()->create();
        $this->parent = Agent::factory()->create([
            'parent' => $this->grandParent->id,
            'vendor_customize_group_id' => $this->grandParent->vendor_customize_group_id,
        ]);
        $this->parent->update([
            'hierarchy_path' => "/1/{$this->grandParent->id}/{$this->parent->id}",
            'hierarchy' => "[-1,{$this->parent->id},{$this->grandParent->id},1,-2]",
        ]);
        $this->agent = Agent::factory()->create([
            'parent' => $this->parent->id,
            'vendor_customize_group_id' => $this->parent->vendor_customize_group_id,
        ]);
        $this->agent->update([
            'hierarchy_path' => "/1/{$this->grandParent->id}/{$this->parent->id}/{$this->agent->id}",
            'hierarchy' => "[-1,{$this->agent->id},{$this->parent->id},{$this->grandParent->id},1,-2]",
        ]);

        $this->vendor = Vendor::factory()->create();
        $this->vendorInfo = VendorInfo::factory()->create([
            'vendor_id' => $this->vendor->id,
        ]);
        $this->vendorInfo2 = VendorInfo::factory()->create([
            'vendor_id' => $this->vendor->id,
        ]);
        $this->eventVendorInfo = VendorInfo::factory()->create([
            'vendor_id' => $this->vendor->id,
            'type' => VendorInfoTypeEnum::EVENT_V2->value,
        ]);
        $this->vendorCustomizedItem = VendorCustomizedItem::query()->create([
            'vendor_id' => $this->vendor->id,
            'agent_group_id' => $this->agent->vendor_customize_group_id,
            'type_id' => 0,
            'status' => 1,
        ]);
    }

    /**
     * 子代理 status=1 and self_status=1
     *
     * php artisan test tests/Unit/Services/VendorServiceTest.php
     */
    public function test_child_1_1()
    {
        $this->createAgentVendor($this->agent, $this->vendorInfo, 1, 1);

        $vendorIds = App::make(VendorService::class)->getVendorIds($this->agent);
        $this->assertEquals([$this->vendor->id], $vendorIds);

        $vendorIds = App::make(VendorService::class)->getEventWalletVendorIds($this->agent);
        $this->assertEquals([], $vendorIds);
    }

    /**
     * 子代理 status=0 and self_status=1
     *
     * php artisan test tests/Unit/Services/VendorServiceTest.php
     */
    public function test_child_0_1()
    {
        $this->createAgentVendor($this->agent, $this->vendorInfo, 0, 1);

        $vendorIds = App::make(VendorService::class)->getVendorIds($this->agent);
        $this->assertEquals([$this->vendor->id], $vendorIds);

        $vendorIds = App::make(VendorService::class)->getEventWalletVendorIds($this->agent);
        $this->assertEquals([], $vendorIds);
    }

    /**
     * 子代理 status=0 and self_status=0
     *
     * php artisan test tests/Unit/Services/VendorServiceTest.php
     */
    public function test_child_0_0()
    {
        $this->createAgentVendor($this->agent, $this->vendorInfo, 0, 0);

        $vendorIds = App::make(VendorService::class)->getVendorIds($this->agent);
        $this->assertEquals([], $vendorIds);

        $vendorIds = App::make(VendorService::class)->getEventWalletVendorIds($this->agent);
        $this->assertEquals([], $vendorIds);
    }

    /**
     * 父代理 status=1 and self_status=1
     * 子代理 status=1 and self_status=1
     *
     * php artisan test tests/Unit/Services/VendorServiceTest.php
     */
    public function test_parent_1_1_child_1_1()
    {
        $this->createAgentVendor($this->parent, $this->vendorInfo, 1, 1);
        $this->createAgentVendor($this->agent, $this->vendorInfo, 1, 1);

        $vendorIds = App::make(VendorService::class)->getVendorIds($this->agent);
        $this->assertEquals([$this->vendor->id], $vendorIds);

        $vendorIds = App::make(VendorService::class)->getEventWalletVendorIds($this->agent);
        $this->assertEquals([], $vendorIds);
    }

    /**
     * 父代理 status=1 and self_status=1
     * 子代理 status=0 and self_status=0
     *
     * php artisan test tests/Unit/Services/VendorServiceTest.php
     */
    public function test_parent_1_1_child_0_0()
    {
        $this->createAgentVendor($this->parent, $this->vendorInfo, 1, 1);
        $this->createAgentVendor($this->agent, $this->vendorInfo, 0, 0);

        $vendorIds = App::make(VendorService::class)->getVendorIds($this->agent);
        $this->assertEquals([], $vendorIds);

        $vendorIds = App::make(VendorService::class)->getEventWalletVendorIds($this->agent);
        $this->assertEquals([], $vendorIds);
    }

    /**
     * 父代理 status=1 and self_status=1
     * 子代理 null
     *
     * php artisan test tests/Unit/Services/VendorServiceTest.php
     */
    public function test_parent_1_1_child_null()
    {
        $this->createAgentVendor($this->parent, $this->vendorInfo, 1, 1);

        $vendorIds = App::make(VendorService::class)->getVendorIds($this->agent);
        $this->assertEquals([$this->vendor->id], $vendorIds);

        $vendorIds = App::make(VendorService::class)->getEventWalletVendorIds($this->agent);
        $this->assertEquals([], $vendorIds);
    }

    /**
     * 父代理 status=0 and self_status=1
     * 子代理 null
     *
     * php artisan test tests/Unit/Services/VendorServiceTest.php
     */
    public function test_parent_0_1_child_null()
    {
        $this->createAgentVendor($this->parent, $this->vendorInfo, 0, 1);

        $vendorIds = App::make(VendorService::class)->getVendorIds($this->agent);
        $this->assertEquals([], $vendorIds);

        $vendorIds = App::make(VendorService::class)->getEventWalletVendorIds($this->agent);
        $this->assertEquals([], $vendorIds);
    }

    /**
     * 父代理 status=0 and self_status=0
     * 子代理 null
     *
     * php artisan test tests/Unit/Services/VendorServiceTest.php
     */
    public function test_parent_0_0_child_null()
    {
        $this->createAgentVendor($this->parent, $this->vendorInfo, 0, 1);

        $vendorIds = App::make(VendorService::class)->getVendorIds($this->agent);
        $this->assertEquals([], $vendorIds);

        $vendorIds = App::make(VendorService::class)->getEventWalletVendorIds($this->agent);
        $this->assertEquals([], $vendorIds);
    }

    /**
     * 祖父代理 status=1 and self_status=1
     * 父代理 status=0 and self_status=1
     * 子代理 null
     *
     * php artisan test tests/Unit/Services/VendorServiceTest.php
     */
    public function test_grand_parent_1_1_parent_0_1_child_0_0()
    {
        $this->createAgentVendor($this->grandParent, $this->vendorInfo, 1, 1);
        $this->createAgentVendor($this->parent, $this->vendorInfo, 0, 1);

        $vendorIds = App::make(VendorService::class)->getVendorIds($this->agent);
        $this->assertEquals([], $vendorIds);

        $vendorIds = App::make(VendorService::class)->getEventWalletVendorIds($this->agent);
        $this->assertEquals([], $vendorIds);
    }

    /**
     * 祖父代理 status=1 and self_status=1
     * 父代理 null
     * 子代理 null
     *
     * php artisan test tests/Unit/Services/VendorServiceTest.php
     */
    public function test_grand_parent_1_1_parent_null_child_null()
    {
        $this->createAgentVendor($this->grandParent, $this->vendorInfo, 1, 1);

        $vendorIds = App::make(VendorService::class)->getVendorIds($this->agent);
        $this->assertEquals([$this->vendor->id], $vendorIds);

        $vendorIds = App::make(VendorService::class)->getEventWalletVendorIds($this->agent);
        $this->assertEquals([], $vendorIds);
    }

    /**
     * 祖父代理 null
     * 父代理 null
     * 子代理 null
     *
     * php artisan test tests/Unit/Services/VendorServiceTest.php
     */
    public function test_grand_parent_null_parent_null_child_null()
    {
        $vendorIds = App::make(VendorService::class)->getVendorIds($this->agent);
        $this->assertEquals([], $vendorIds);

        $vendorIds = App::make(VendorService::class)->getEventWalletVendorIds($this->agent);
        $this->assertEquals([], $vendorIds);
    }

    /**
     * 祖父代理 null
     * 父代理 null
     * 子代理 status=1 and self_status=1
     *
     * php artisan test tests/Unit/Services/VendorServiceTest.php
     */
    public function test_grand_parent_null_parent_null_child_1_1()
    {
        $this->createAgentVendor($this->agent, $this->vendorInfo, 1, 1);

        $vendorIds = App::make(VendorService::class)->getVendorIds($this->agent);
        $this->assertEquals([$this->vendor->id], $vendorIds);

        $vendorIds = App::make(VendorService::class)->getEventWalletVendorIds($this->agent);
        $this->assertEquals([], $vendorIds);
    }

    /**
     *  agent_vendor 設定為活動遊戲商
     *
     * @return void
     */
    public function test_agent_vendor_type_event_v2()
    {
        $this->createAgentVendor($this->agent, $this->eventVendorInfo, 1, 1);

        $vendorIds = App::make(VendorService::class)->getVendorIds($this->agent);
        $this->assertEquals([], $vendorIds);

        $vendorIds = App::make(VendorService::class)->getEventWalletVendorIds($this->agent);
        $this->assertEquals([$this->vendor->id], $vendorIds);
    }

    /**
     * 同時有兩筆 vendor_info 一個啟用一個未啟用
     *
     * @return void
     */
    public function test_agent_vendor_enable_and_disable()
    {
        $this->createAgentVendor($this->agent, $this->vendorInfo, 1, 1);
        $this->createAgentVendor($this->agent, $this->vendorInfo2, 0, 0);

        $vendorIds = App::make(VendorService::class)->getVendorIds($this->agent);

        $this->assertEquals([$this->vendor->id], $vendorIds);
    }

    /**
     * 同時有兩筆 vendor_info 一個未啟用一個啟用 (順序跟上面那個不同)
     *
     * @return void
     */
    public function test_agent_vendor_disable_and_enable()
    {
        $this->createAgentVendor($this->agent, $this->vendorInfo, 0, 0);
        $this->createAgentVendor($this->agent, $this->vendorInfo2, 1, 1);

        $vendorIds = App::make(VendorService::class)->getVendorIds($this->agent);

        $this->assertEquals([$this->vendor->id], $vendorIds);
    }

    /**
     * 同時有兩筆 vendor_info 都未啟用
     *
     * @return void
     */
    public function test_agent_vendor_disable_all()
    {
        $this->createAgentVendor($this->agent, $this->vendorInfo, 0, 0);
        $this->createAgentVendor($this->agent, $this->vendorInfo2, 0, 0);

        $vendorIds = App::make(VendorService::class)->getVendorIds($this->agent);

        $this->assertEquals([], $vendorIds);
    }

    /**
     * 同時有兩筆 vendor_info 都啟用
     *
     * php artisan test tests/Unit/Services/VendorServiceTest.php --filter test_agent_vendor_enable_all
     *
     * @return void
     */
    public function test_agent_vendor_enable_all()
    {
        $this->createAgentVendor($this->agent, $this->vendorInfo, 1, 1);
        $this->createAgentVendor($this->agent, $this->vendorInfo2, 1, 1);

        $vendorIds = App::make(VendorService::class)->getVendorIds($this->agent);
        $this->assertEquals([$this->vendor->id], $vendorIds);

        $vendorInfoIds = App::make(VendorService::class)->getVendorInfoIds($this->agent);
        $expectedVendorInfoIds = [$this->vendorInfo2->id, $this->vendorInfo->id];
        $this->assertTrue(sort($vendorInfoIds) == sort($expectedVendorInfoIds));
    }

    /**
     * 同時有活動跟一般遊戲商
     *
     * @return void
     */
    public function test_agent_vendor_event_v2_enable_and_disable()
    {
        $this->createAgentVendor($this->agent, $this->vendorInfo, 0, 0);
        $this->createAgentVendor($this->agent, $this->eventVendorInfo, 1, 1);

        $vendorIds = App::make(VendorService::class)->getEventWalletVendorIds($this->agent);
        $this->assertEquals([$this->vendor->id], $vendorIds);

        $vendorIds = App::make(VendorService::class)->getVendorIds($this->agent);
        $this->assertEquals([], $vendorIds);
    }

    /**
     * 同時有活動跟一般遊戲商
     *
     * @return void
     */
    public function test_agent_vendor_event_v2_disable_and_enable()
    {
        $this->createAgentVendor($this->agent, $this->vendorInfo, 1, 1);
        $this->createAgentVendor($this->agent, $this->eventVendorInfo, 0, 0);

        $vendorIds = App::make(VendorService::class)->getEventWalletVendorIds($this->agent);
        $this->assertEquals([], $vendorIds);

        $vendorIds = App::make(VendorService::class)->getVendorIds($this->agent);
        $this->assertEquals([$this->vendor->id], $vendorIds);
    }

    /**
     * 父代理 跟 子代理的 vendor_info_id 不同
     *
     * php artisan test tests/Unit/Services/VendorServiceTest.php --filter test_parent_and_child_vendor_info_id_different
     */
    public function test_parent_and_child_vendor_info_id_different()
    {
        $this->createAgentVendor($this->parent, $this->vendorInfo, 1, 1);
        $this->createAgentVendor($this->agent, $this->vendorInfo2, 1, 1);

        $vendorIds = App::make(VendorService::class)->getVendorIds($this->agent);
        $this->assertEquals([$this->vendorInfo2->vendor_id], $vendorIds);
        $vendorInfoIds = App::make(VendorService::class)->getVendorInfoIds($this->agent);
        $this->assertEquals([$this->vendorInfo2->id], $vendorInfoIds);
    }

    protected function createAgentVendor(Agent $agent, VendorInfo $vendorInfo, $status, $selfStatus)
    {
        AgentVendor::create([
            'agent_id' => $agent->id,
            'vendor_id' => $vendorInfo->vendor_id,
            'vendor_info_id' => $vendorInfo->id,
            'status' => $status,
            'self_status' => $selfStatus,
        ]);
    }
}
