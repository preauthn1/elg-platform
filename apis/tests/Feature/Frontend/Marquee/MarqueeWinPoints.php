<?php

declare(strict_types=1);

namespace Tests\Feature\Frontend\Marquee;

use App\Enums\General\HttpCode;
use App\Http\Middleware\Frontend\DecryptRequest;
use App\Models\Agent;
use App\Models\MarqueeWinPoint;
use App\Models\Member;
use App\Models\TotalBetRecord;
use Database\Seeders\SyncAgentSettingDefinesSeeder;
use Tests\TestCase;

class MarqueeWinPoints extends TestCase
{
    private Agent $agent;

    private Member $member;

    const API_MARQUEE_WIN_POINTS = '/frontend/api/marquee/win-points';

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

    public function test_marquee_win_points_success()
    {
        $vendor = $this->createBasicVendorGames($this->agent, 1);

        MarqueeWinPoint::create([
            'agent_id' => $this->agent->id,
            'exclude_agent_id' => 0,
            'status' => 1,
            'vendor_id' => $vendor->id,
            'trigger_amount' => 10.00,
            'admin' => 0,
        ]);

        TotalBetRecord::create([
            'member_id' => $this->member->id,
            'vendor_id' => $vendor->id,
            'bet_record_id' => 1,
            'bet' => 100,
            'win_lose' => 100,
            'payout_time' => now(),
            'bet_time' => now(),
            'summary_time' => now(),
            'game_id' => $vendor->games->first()->id,
            'reference_no' => '1234567890',
        ]);

        $response = $this->requestApi(self::API_MARQUEE_WIN_POINTS, [
            'domain' => $this->domain,
        ]);

        $response->assertStatus(HttpCode::HTTP_OK->value)
            ->assertJsonStructure([
                'code',
                'message',
                'data' => [
                    '*' => [
                        'id',
                        'win',
                        'username',
                        'game_name',
                        'game_id',
                        'vendor_id',
                        'image',
                    ],
                ],
            ]);

        $data = $response->json('data');
        $this->assertEquals($data[0]['win'], '200.00');
        $this->assertEquals($data[0]['username'], $this->member->username);
        $this->assertEquals($data[0]['game_name'], $vendor->games->first()->name);
        $this->assertEquals($data[0]['game_id'], $vendor->games->first()->id);
        $this->assertEquals($data[0]['vendor_id'], $vendor->id);
    }
}
