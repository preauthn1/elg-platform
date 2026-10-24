<?php

declare(strict_types=1);

namespace Tests\Feature\Frontend\Ranking;

use App\Enums\General\HttpCode;
use App\Helpers\Common;
use App\Models\Agent;
use App\Models\Member;
use App\Models\VendorHourRecord;
use Carbon\Carbon;
use Database\Seeders\SyncAgentSettingDefinesSeeder;
use Illuminate\Support\Facades\Redis;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * php artisan test tests/Feature/Frontend/Ranking/RankingGameWinnersTest.php
 */
class RankingGameWinnersTest extends TestCase
{
    private Agent $agent;

    private Member $member;

    private string $loginToken = [REDACTED]';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SyncAgentSettingDefinesSeeder::class);
        $this->agent = $this->createAgentAndDomain();
        $this->member = $this->createMember($this->agent);
        $this->loginToken = [REDACTED];

        $this->agent->vendor_customize_group_id = 1;
        $this->agent->save();
    }

    public function test_ranking_game_winners_success()
    {
        // Arrange
        Redis::shouldReceive('exists')->andReturn(false);
        Redis::shouldReceive('set')->andReturn(true);

        // date_hour 釘進「今日 frontend 視窗」正中（與 endpoint 的 startOfDay 視窗同口徑），
        // 避免 factory 預設的 now-10min（UTC）在 frontend 午夜後落到前一日視窗外造成 flaky。
        $windowTime = Carbon::now()->toFrontendTime()->startOfDay()->addHours(12)->toSystemTime();
        $vendor = $this->createBasicVendorGames($this->agent);
        $vendorHourRecord = VendorHourRecord::factory()->create([
            'vendor_id' => $vendor->id,
            'member_id' => $this->member->id,
            'bet' => 0,
            'win' => 99999999,
            'win_lose' => 99999999,
            'date_hour' => $windowTime->format('Y-m-d H:00:00'),
            'last_bet_time' => $windowTime->format('Y-m-d H:i:s'),
        ]);

        // Act
        $response = $this->act([
            'domain' => $this->domain,
        ]);

        // Assert
        $response->assertStatus(HttpCode::HTTP_OK->value)
            ->assertJsonStructure($this->basicResponseDataStructure());

        $data = $response->json('data');

        $this->assertEquals($vendor->name, $data[0]['vendor_name']);
        $this->assertEquals(Common::maskString($this->member->username), $data[0]['username']);
        $this->assertEquals(99999999, $data[0]['total_win_lose']);
        $this->assertCount(30, $data);
    }

    private function act(array $params): TestResponse
    {
        $response = $this->json(
            'POST',
            '/frontend/api/ranking/game-winners',
            $this->encryptParams($params),
            [
                'Accept-Language' => 'zh-TW',
                'Authorization' => 'Bearer ' . $this->loginToken,
            ]
        );

        return $response;
    }

    private function basicResponseDataStructure(): array
    {
        return [
            'code',
            'message',
            'data' => [
                [
                    'username',
                    'image',
                    'total_win_lose',
                    'vendor_name',
                    'rank',
                ],
            ],
        ];
    }
}
