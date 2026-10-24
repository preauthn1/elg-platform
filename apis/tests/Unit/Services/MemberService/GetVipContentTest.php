<?php

declare(strict_types=1);

namespace Tests\Unit\Services\MemberService;

use App\Enums\WithdrawalTypeEnum;
use App\Models\Agent;
use App\Models\Member;
use App\Models\MemberVip;
use App\Models\VipContent;
use App\Services\MemberService;
use Tests\TestCase;

/**
 * MemberService 取得 VIP 內容測試
 *
 * php artisan test tests/Unit/Services/MemberService/GetVipContentTest.php
 */
class GetVipContentTest extends TestCase
{
    protected Member $member;

    protected Agent $agent;

    protected MemberService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = \App::make(MemberService::class);
        $this->agent = $this->createAgentAndDomain();
        $this->member = $this->createMember($this->agent);
    }

    public function test_success()
    {
        $originalVipContent = $this->createVipContent();
        $this->createMemberVip($originalVipContent);

        $vipContent = $this->service->getVipContent($this->member);

        $this->assertInstanceOf(VipContent::class, $vipContent);
        $this->assertEquals($vipContent->id, $originalVipContent->id);
    }

    public function test_no_vip()
    {
        $vipContent = $this->service->getVipContent($this->member);
        $this->assertNull($vipContent);
    }

    public function test_no_vip0()
    {
        $this->createVipContent1();
        $vipContent = $this->service->getVipContent($this->member);
        $this->assertNull($vipContent);
    }

    public function test_parent_vip()
    {
        $originalVipContent = $this->createVipContent();
        $vipContent = $this->service->getVipContent($this->member);
        $this->assertInstanceOf(VipContent::class, $vipContent);
        $this->assertEquals($vipContent->id, $originalVipContent->id);
    }

    protected function createVipContent(): VipContent
    {
        $vipContent = new VipContent;
        $vipContent->agent_id = $this->agent->id;
        $vipContent->level = 0;
        $vipContent->name = 'VIP0';
        $vipContent->target_amount = 500;
        $vipContent->keep_amount = 300;
        $vipContent->withdrawal_times_limit = 103;
        $vipContent->tax = 1;
        $vipContent->image = '';
        $vipContent->withdrawal_amount_limit = 5000;
        $vipContent->vip_channel = 0;
        $vipContent->is_downgrade = 1;
        $vipContent->admin = 1;
        $vipContent->save();

        $vipContent->withdrawalTypes()->create([
            'withdrawal_type_id' => WithdrawalTypeEnum::AUSTRALIA_BANK->value,
            'status' => 1,
            'admin' => 1,
        ]);

        return $vipContent;
    }

    protected function createVipContent1(): VipContent
    {
        $vipContent = new VipContent;
        $vipContent->agent_id = $this->agent->id;
        $vipContent->level = 1;
        $vipContent->name = 'VIP1';
        $vipContent->target_amount = 1000;
        $vipContent->keep_amount = 500;
        $vipContent->withdrawal_times_limit = 103;
        $vipContent->tax = 1;
        $vipContent->image = '';
        $vipContent->withdrawal_amount_limit = 7000;
        $vipContent->vip_channel = 1;
        $vipContent->is_downgrade = 1;
        $vipContent->admin = 1;
        $vipContent->save();

        $vipContent->withdrawalTypes()->create([
            'withdrawal_type_id' => WithdrawalTypeEnum::AUSTRALIA_BANK->value,
            'status' => 1,
            'admin' => 1,
        ]);

        return $vipContent;
    }

    protected function createMemberVip(VipContent $vipContent): MemberVip
    {
        $memberVip = new MemberVip;
        $memberVip->member_id = $this->member->id;
        $memberVip->vip_content_id = $vipContent->id;
        $memberVip->deposit_amount = 0;
        $memberVip->keep_amount = 0;
        $memberVip->admin = 1;
        $memberVip->save();

        return $memberVip;
    }
}
