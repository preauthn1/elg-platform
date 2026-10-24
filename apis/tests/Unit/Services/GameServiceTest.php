<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\Agent;
use App\Models\Member;
use App\Models\Vendor;
use App\Services\GameService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * GameService::getRecentPromotionGames / getRecommendPromotionGames 測試
 * （api/getPromotionGames；vw-master 獨有，tpower-master 無此端點）
 *
 * php artisan test tests/Unit/Services/GameServiceTest.php
 */
class GameServiceTest extends TestCase
{
    private GameService $service;

    private Agent $agent;

    private Member $member;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(GameService::class);
        $this->agent = $this->createAgentAndDomain();
        $this->member = $this->createMember($this->agent);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function makeBetRecord(Vendor $vendor, int $gameId, array $overrides = []): void
    {
        DB::table('summary_bet_records')->insert(array_merge([
            'summary_time' => now(),
            'data_time' => now(),
            'member_id' => $this->member->id,
            'vendor_id' => $vendor->id,
            'game_id' => $gameId,
            'bet' => '100.0000',
            'win_lose' => '0.0000',
            'rolling_chip' => '0.0000',
            'jackpot' => '0.0000',
            'record_count' => 1,
            'bak' => '0.0000',
            'bak1' => '0.0000',
            'v_jackpot' => '0.0000',
            'cost' => '0.0000',
            'correction' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    public function test_recent_games_returns_games_bet_within_last_month(): void
    {
        $vendor = $this->createBasicVendorGames($this->agent);
        $game = $vendor->games()->first();
        $this->makeBetRecord($vendor, $game->id);

        $rows = $this->service->getRecentPromotionGames($this->member, 1);

        $this->assertCount(1, $rows);
        $this->assertSame($game->id, (int) $rows[0]->id);
        $this->assertSame(0, (int) $rows[0]->disable);
    }

    public function test_recent_games_excludes_bets_older_than_a_month(): void
    {
        $vendor = $this->createBasicVendorGames($this->agent);
        $game = $vendor->games()->first();
        $this->makeBetRecord($vendor, $game->id, ['summary_time' => now()->subMonths(2)]);

        $rows = $this->service->getRecentPromotionGames($this->member, 1);

        $this->assertCount(0, $rows);
    }

    public function test_recent_games_excludes_other_members_bets(): void
    {
        $vendor = $this->createBasicVendorGames($this->agent);
        $game = $vendor->games()->first();
        $other = $this->createMember($this->agent, ['username' => 'other_' . Str::random(12)]);
        DB::table('summary_bet_records')->insert([
            'summary_time' => now(), 'data_time' => now(), 'member_id' => $other->id,
            'vendor_id' => $vendor->id, 'game_id' => $game->id, 'bet' => '100.0000', 'win_lose' => '0.0000',
            'rolling_chip' => '0.0000', 'jackpot' => '0.0000', 'record_count' => 1, 'bak' => '0.0000',
            'bak1' => '0.0000', 'v_jackpot' => '0.0000', 'cost' => '0.0000', 'correction' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $rows = $this->service->getRecentPromotionGames($this->member, 1);

        $this->assertCount(0, $rows);
    }

    public function test_recent_games_uses_localized_name(): void
    {
        $vendor = $this->createBasicVendorGames($this->agent);
        $game = $vendor->games()->first();
        $game->update(['name' => 'default name']);
        DB::table('game_names')->insert([
            'language_id' => 2, 'game_id' => $game->id, 'name' => 'localized name',
            'created_at' => now(), 'updated_at' => now(), 'admin' => 0,
        ]);
        $this->makeBetRecord($vendor, $game->id);

        $rows = $this->service->getRecentPromotionGames($this->member, 2);

        $this->assertSame('localized name', $rows[0]->name);
    }

    public function test_recommend_games_returns_games_bet_today_regardless_of_member(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-01 10:00:00', config('custom.frontend.timezone')));
        $vendor = $this->createBasicVendorGames($this->agent);
        $game = $vendor->games()->first();
        $other = $this->createMember($this->agent, ['username' => 'other_' . Str::random(12)]);
        DB::table('summary_bet_records')->insert([
            'summary_time' => now(), 'data_time' => now(), 'member_id' => $other->id,
            'vendor_id' => $vendor->id, 'game_id' => $game->id, 'bet' => '100.0000', 'win_lose' => '0.0000',
            'rolling_chip' => '0.0000', 'jackpot' => '0.0000', 'record_count' => 1, 'bak' => '0.0000',
            'bak1' => '0.0000', 'v_jackpot' => '0.0000', 'cost' => '0.0000', 'correction' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $rows = $this->service->getRecommendPromotionGames($this->member, 1);

        $this->assertCount(1, $rows);
        $this->assertSame($game->id, (int) $rows[0]->id);
    }

    public function test_recommend_games_excludes_yesterdays_bets(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-01 10:00:00', config('custom.frontend.timezone')));
        $vendor = $this->createBasicVendorGames($this->agent);
        $game = $vendor->games()->first();
        $this->makeBetRecord($vendor, $game->id, [
            'summary_time' => Carbon::parse('2026-07-31 12:00:00', config('custom.frontend.timezone'))->toSystemTime(),
        ]);

        $rows = $this->service->getRecommendPromotionGames($this->member, 1);

        $this->assertCount(0, $rows);
    }

    public function test_returns_empty_when_no_bet_records(): void
    {
        $rows = $this->service->getRecentPromotionGames($this->member, 1);

        $this->assertCount(0, $rows);
    }
}
