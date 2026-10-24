<?php

declare(strict_types=1);

namespace Tests\Feature\Frontend\Member;

use App\Enums\General\HttpCode;
use App\Http\Middleware\Frontend\DecryptRequest;
use App\Models\Agent;
use App\Models\Game;
use App\Models\Member;
use App\Models\SummaryBetRecord;
use App\Models\Vendor;
use Carbon\Carbon;
use Database\Seeders\SyncAgentSettingDefinesSeeder;
use Tests\TestCase;

/**
 * php artisan test tests/Feature/Frontend/Member/MemberWinlosesTest.php
 */
class MemberWinlosesTest extends TestCase
{
    private Agent $agent;

    private Member $member;

    const API_MEMBER_WINLOSSES = '/frontend/api/member/winloses';

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

    public function test_member_vendors_success()
    {
        $vendor = Vendor::factory()->create();
        $game1 = Game::create([
            'vendor_id' => $vendor->id,
            'type_id' => 0,
            'name' => 'test1',
            'game_type' => 'TEST1',
            'status' => 1,
            'open_window' => 0,
            'image' => 'https://example.invalid',
            'admin' => 0,
        ]);

        $game2 = Game::create([
            'vendor_id' => $vendor->id,
            'type_id' => 0,
            'name' => 'test2',
            'game_type' => 'TEST2',
            'status' => 1,
            'open_window' => 0,
            'image' => 'https://example.invalid',
            'admin' => 0,
        ]);

        $searchDay = Carbon::now()->toFrontendTime()->format('Y-m-d');
        $rangeStart = Carbon::parseFrontend($searchDay)->startOfDay()->toSystemTime();
        $time1 = $rangeStart->copy()->addHour();
        $time2 = $rangeStart->copy()->addHours(2);

        $record1 = SummaryBetRecord::create([
            'member_id' => $this->member->id,
            'summary_time' => $time1->format('Y-m-d H:i:s'),
            'data_time' => $time1->format('Y-m-d H:i:s'),
            'vendor_id' => $vendor->id,
            'game_id' => $game1->id,
            'bet' => 100,
            'win_lose' => 100,
            'record_count' => 2,
        ]);

        $record2 = SummaryBetRecord::create([
            'member_id' => $this->member->id,
            'summary_time' => $time2->format('Y-m-d H:i:s'),
            'data_time' => $time2->format('Y-m-d H:i:s'),
            'vendor_id' => $vendor->id,
            'game_id' => $game2->id,
            'bet' => 200,
            'win_lose' => 200,
            'record_count' => 3,
        ]);

        $response = $this->requestApi(self::API_MEMBER_WINLOSSES, [
            'vendor_id' => $vendor->id,
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
                        'valid_bet' => $record1->bet,
                        'win_lose' => $record1->win_lose,
                        'record_count' => $record1->record_count,
                        'name' => $game1->name,
                    ],
                    [
                        'valid_bet' => $record2->bet,
                        'win_lose' => $record2->win_lose,
                        'record_count' => $record2->record_count,
                        'name' => $game2->name,
                    ],
                ],
                'sum' => [
                    'sum_win_lose' => $record1->win_lose + $record2->win_lose,
                    'sum_record_count' => $record1->record_count + $record2->record_count,
                ],
            ],
        ]);
    }
}
