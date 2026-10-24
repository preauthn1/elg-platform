<?php

declare(strict_types=1);

namespace Tests\Feature\Frontend\Vip;

use App\Enums\General\HttpCode;
use App\Helpers\Common;
use App\Http\Middleware\Frontend\DecryptRequest;
use App\Models\Agent;
use App\Models\Member;
use App\Models\MemberVipPrize;
use App\Models\VipContentReward;
use App\Models\VipReward;
use Database\Seeders\SyncAgentSettingDefinesSeeder;
use Tests\TestCase;

class VipRewardHistoryTest extends TestCase
{
    private Agent $agent;

    private Member $member;

    const API_VIP_REWARD_HISTORY = '/frontend/api/vip/reward-history';

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

    public function test_vip_reward_history_success()
    {
        $vipReward = VipReward::create([
            'name' => '測試',
            'status' => 1,
        ]);
        $vipContentReward = VipContentReward::create([
            'vip_content_id' => 1,
            'vip_reward_id' => $vipReward->id,
            'point' => 100,
        ]);

        $memberVipPrize = MemberVipPrize::create([
            'member_id' => $this->member->id,
            'vip_content_reward_id' => $vipContentReward->id,
            'point' => 100,
            'status' => 1,
        ]);

        $response = $this->requestApi(self::API_VIP_REWARD_HISTORY, []);

        $response->assertStatus(HttpCode::HTTP_OK->value)
            ->assertJson([
                'code' => HttpCode::HTTP_OK->value,
                'data' => [
                    [
                        'vip_reward_id' => $vipReward->id,
                        'reward_name' => $vipReward->name,
                        'point' => Common::numFloor($memberVipPrize->point, 4),
                    ],
                ],
            ]);
    }
}
