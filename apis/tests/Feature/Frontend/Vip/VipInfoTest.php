<?php

declare(strict_types=1);

namespace Tests\Feature\Frontend\Vip;

use App\Enums\General\HttpCode;
use App\Http\Middleware\Frontend\DecryptRequest;
use App\Models\Agent;
use App\Models\Member;
use App\Models\MemberVip;
use App\Models\MemberVipPrize;
use App\Models\VipContent;
use App\Models\VipContentReward;
use App\Models\VipReward;
use Database\Seeders\SyncAgentSettingDefinesSeeder;
use Tests\TestCase;

class VipInfoTest extends TestCase
{
    private Agent $agent;

    private Member $member;

    const API_VIP_INFO = '/frontend/api/vip/info';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SyncAgentSettingDefinesSeeder::class);
        $this->withoutMiddleware(DecryptRequest::class);

        $this->agent = $this->createAgentAndDomain();
        $this->member = $this->createMemberAndToken($this->agent);

        $this->agent->vendor_customize_group_id = 1;
        $this->agent->save();
    }

    public function test_vip_info_success()
    {
        $vipContent = VipContent::create([
            'name' => '測試',
            'agent_id' => $this->agent->id,
            'level' => 1,
            'target_amount' => 100,
            'keep_amount' => 100,
            // 'status' => 1,
        ]);
        $vipReward = VipReward::create([
            'name' => '測試',
            'status' => 1,
        ]);
        $vipContentReward = VipContentReward::create([
            'vip_content_id' => $vipContent->id,
            'vip_reward_id' => $vipReward->id,
            'point' => 100,
            'status' => 1,
        ]);

        MemberVip::create([
            'member_id' => $this->member->id,
            'vip_content_id' => $vipContent->id,
            'deposit_amount' => 1000,
            'keep_amount' => 1000,
        ]);
        MemberVipPrize::create([
            'member_id' => $this->member->id,
            'vip_content_reward_id' => $vipContentReward->id,
        ]);

        $response = $this->requestApi(self::API_VIP_INFO, []);
        $response->assertStatus(HttpCode::HTTP_OK->value)
            ->assertJsonStructure([
                'code',
                'message',
                'data' => [
                    'vip_content_id',
                    'deposit_amount',
                    'keep_amount',
                    'level',
                    'this_rebate_records' => [
                        'total_bet',
                        'rebate_amount',
                    ],
                    'last_rebate_records' => [
                        'total_bet',
                        'rebate_amount',
                    ],
                    'rebate_summary_records' => [],
                    'reward' => [
                        [
                            'id',
                            'reward_id',
                            'name',
                            'status',
                            'expired_time',
                        ],
                    ],
                ],
            ]);
    }
}
