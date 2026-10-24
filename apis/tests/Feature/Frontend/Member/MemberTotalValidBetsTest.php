<?php

declare(strict_types=1);

namespace Tests\Feature\Frontend\Member;

use App\Enums\General\HttpCode;
use App\Http\Middleware\Frontend\DecryptRequest;
use App\Models\Agent;
use App\Models\Member;
use App\Models\SummaryBetRecord;
use Carbon\Carbon;
use Database\Seeders\SyncAgentSettingDefinesSeeder;
use Tests\TestCase;

class MemberTotalValidBetsTest extends TestCase
{
    private Agent $agent;

    private Member $member;

    const API_MEMBER_TOTAL_VALID_BETS = '/frontend/api/member/total-valid-bets';

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

    public function test_member_total_valid_bets_success()
    {
        $vendor = $this->createBasicVendorGames($this->agent);

        $searchDay = Carbon::now()->toFrontendTime()->format('Y-m-d');
        $rangeStart = Carbon::parseFrontend($searchDay)->startOfDay()->toSystemTime();
        $time1 = $rangeStart->copy()->addHour();
        $time2 = $rangeStart->copy()->addHours(2);

        SummaryBetRecord::create([
            'member_id' => $this->member->id,
            'summary_time' => $time1->format('Y-m-d H:i:s'),
            'data_time' => $time1->format('Y-m-d H:i:s'),
            'vendor_id' => $vendor->id,
            'game_id' => 1,
            'bet' => 100,
            'win_lose' => 100,
        ]);

        SummaryBetRecord::create([
            'member_id' => $this->member->id,
            'summary_time' => $time2->format('Y-m-d H:i:s'),
            'data_time' => $time2->format('Y-m-d H:i:s'),
            'vendor_id' => $vendor->id,
            'game_id' => 1,
            'bet' => 200,
            'win_lose' => 200,
        ]);

        $response = $this->requestApi(self::API_MEMBER_TOTAL_VALID_BETS, [
            'search_day' => $searchDay,
            'per_page' => 10,
            'page' => 1,
        ]);

        $response->assertStatus(HttpCode::HTTP_OK->value);
        $response->assertJson([
            'code' => HttpCode::HTTP_OK->value,
            'data' => [
                'items' => [
                    [
                        'name' => $vendor->name,
                        'image' => $vendor->image,
                        'summary_time' => $time2->copy()->toFrontendTime()->format('Y-m-d H:i:s'),
                    ],
                ],
            ],
        ]);
        $this->assertEquals(300, (float) $response->json('data.items.0.total_valid_bet'));
    }
}
