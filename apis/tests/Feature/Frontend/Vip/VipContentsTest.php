<?php

declare(strict_types=1);

namespace Tests\Feature\Frontend\Vip;

use App\Enums\General\HttpCode;
use App\Http\Middleware\Frontend\DecryptRequest;
use App\Models\Agent;
use App\Models\Member;
use App\Models\VipContent;
use App\Models\VipContentReward;
use App\Models\VipContentWithdrawalType;
use App\Models\VipReward;
use App\Models\WithdrawalType;
use Database\Seeders\SyncAgentSettingDefinesSeeder;
use Tests\TestCase;

class VipContentsTest extends TestCase
{
    private Agent $agent;

    private Member $member;

    const API_VIP_CONTENTS = '/frontend/api/vip/contents';

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

    public function test_vip_contents_success()
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

        $withdrawalType = WithdrawalType::create([
            'name' => '測試',
            'status' => 1,
        ]);

        VipContentWithdrawalType::create([
            'vip_content_id' => $vipContent->id,
            'withdrawal_type_id' => $withdrawalType->id,
            'status' => 1,
        ]);

        $response = $this->requestApi(self::API_VIP_CONTENTS, []);
        $response->assertStatus(HttpCode::HTTP_OK->value)
            ->assertJsonStructure([
                'code',
                'message',
                'data' => [
                    [
                        'id',
                        'level',
                        'name',
                        'target_amount',
                        'keep_amount',
                        'withdrawal_times_limit',
                        'image',
                        'withdrawal_amount_limit',
                        'vip_channel',
                        'reward',
                        'support_order_type',
                        'rebate_proportion',
                    ],
                ],
            ]);
    }
}
