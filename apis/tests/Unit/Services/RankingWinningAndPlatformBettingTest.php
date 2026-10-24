<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\Agent;
use App\Models\Game;
use App\Models\Member;
use App\Models\Vendor;
use App\Services\RankingService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * RankingService::getWinningRanking / getPlatformBettingRanking 測試
 * （ranking/winning、ranking/platform_betting；tpower-master 與 vw-master 逐行完全相同）
 *
 * php artisan test tests/Unit/Services/RankingWinningAndPlatformBettingTest.php
 */
class RankingWinningAndPlatformBettingTest extends TestCase
{
    private RankingService $service;

    private Agent $agent;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->service = app(RankingService::class);
        $this->agent = $this->createAgentAndDomain();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function makeWinningRecord(Member $member, Vendor $vendor, Game $game, array $overrides = []): void
    {
        DB::table('ranking_tbr_records')->insert(array_merge([
            'summary_time' => now(),
            'member_id' => $member->id,
            'vendor_id' => $vendor->id,
            'game_id' => $game->id,
            'bet' => '100.0000',
            'valid_bet' => '100.0000',
            'win_lose' => '1000.0000',
            'bet_time' => now(),
            'payout_time' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    public function test_winning_ranking_returns_masked_username_and_game_vendor_names(): void
    {
        $member = $this->createMember($this->agent, ['username' => 'abcdefgh']);
        $vendor = Vendor::factory()->create(['name' => 'legacy vendor']);
        $game = Game::factory()->create(['name' => 'legacy game']);
        $this->makeWinningRecord($member, $vendor, $game);

        $rows = $this->service->getWinningRanking($this->agent, 2, 1);

        $this->assertCount(1, $rows);
        $this->assertSame('legacy game', $rows[0]->game_name);
        $this->assertSame('legacy vendor', $rows[0]->vendor_name);
        $this->assertNotSame('abcdefgh', $rows[0]->username);
        $this->assertStringContainsString('*', $rows[0]->username);
    }

    public function test_winning_ranking_excludes_below_threshold(): void
    {
        $member = $this->createMember($this->agent);
        $vendor = Vendor::factory()->create();
        $game = Game::factory()->create();
        $this->makeWinningRecord($member, $vendor, $game, ['win_lose' => '499.9999']);

        $rows = $this->service->getWinningRanking($this->agent, 2, 1);

        $this->assertCount(0, $rows);
    }

    public function test_winning_ranking_excludes_other_agent_hierarchy(): void
    {
        $other = Agent::factory()->create(['status' => 1]);
        $other->hierarchy_path = "/{$other->id}";
        $other->save();

        $member = $this->createMember($other);
        $vendor = Vendor::factory()->create();
        $game = Game::factory()->create();
        $this->makeWinningRecord($member, $vendor, $game);

        $rows = $this->service->getWinningRanking($this->agent, 2, 1);

        $this->assertCount(0, $rows);
    }

    public function test_winning_ranking_type_1_ignores_time_filter_and_sorts_by_payout_time(): void
    {
        $member = $this->createMember($this->agent);
        $vendor = Vendor::factory()->create();
        $game = Game::factory()->create();
        // 派彩時間在很久以前，type=2 的日排行區間會排除，type=1 即時不應排除
        $this->makeWinningRecord($member, $vendor, $game, ['payout_time' => now()->subYear()]);

        $filteredByDay = $this->service->getWinningRanking($this->agent, 2, 1);
        $this->assertCount(0, $filteredByDay);

        $realtime = $this->service->getWinningRanking($this->agent, 1, 1);
        $this->assertCount(1, $realtime);
    }

    public function test_winning_ranking_uses_localized_game_name(): void
    {
        $member = $this->createMember($this->agent);
        $vendor = Vendor::factory()->create();
        $game = Game::factory()->create(['name' => 'default name']);
        DB::table('game_names')->insert([
            'language_id' => 2,
            'game_id' => $game->id,
            'name' => 'localized name',
            'created_at' => now(),
            'updated_at' => now(),
            'admin' => 0,
        ]);
        $this->makeWinningRecord($member, $vendor, $game);

        $rows = $this->service->getWinningRanking($this->agent, 2, 2);

        $this->assertSame('localized name', $rows[0]->game_name);
    }

    public function test_winning_ranking_cache_key_does_not_leak_across_languages(): void
    {
        $member = $this->createMember($this->agent);
        $vendor = Vendor::factory()->create();
        $game = Game::factory()->create(['name' => 'default name']);
        DB::table('game_names')->insert([
            'language_id' => 2,
            'game_id' => $game->id,
            'name' => 'localized name',
            'created_at' => now(),
            'updated_at' => now(),
            'admin' => 0,
        ]);
        $this->makeWinningRecord($member, $vendor, $game);

        // 先以語系 1 觸發快取寫入，快取 key 若未帶 languageId，緊接著的語系 2 查詢會直接吃到這份快取
        $first = $this->service->getWinningRanking($this->agent, 2, 1);
        $this->assertSame('default name', $first[0]->game_name);

        $second = $this->service->getWinningRanking($this->agent, 2, 2);
        $this->assertSame('localized name', $second[0]->game_name);
    }

    public function test_winning_ranking_excludes_sibling_agent_with_colliding_path_prefix(): void
    {
        // 模擬 hierarchy_path 前綴碰撞：/1/715 的 LIKE '/1/715%' 會誤配到 /1/7151
        $this->agent->hierarchy_path = '/1/715';
        $this->agent->save();

        $sibling = Agent::factory()->create(['status' => 1, 'hierarchy_path' => '/1/7151']);
        $siblingMember = $this->createMember($sibling);
        $vendor = Vendor::factory()->create();
        $game = Game::factory()->create();
        $this->makeWinningRecord($siblingMember, $vendor, $game);

        $rows = $this->service->getWinningRanking($this->agent->fresh(), 2, 1);

        $this->assertCount(0, $rows);
    }

    private function makeBetRecord(Member $member, array $overrides = []): void
    {
        DB::table('summary_bet_records')->insert(array_merge([
            'summary_time' => now(),
            'data_time' => now(),
            'member_id' => $member->id,
            'vendor_id' => 1,
            'game_id' => 1,
            'bet' => '1000.0000',
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

    public function test_platform_betting_ranks_sequentially_without_tie_sharing(): void
    {
        $first = $this->createMember($this->agent, ['username' => 'firstplayer']);
        $second = $this->createMember($this->agent, ['username' => 'secondplayer']);
        $this->makeBetRecord($first, ['bet' => '2000.0000']);
        $this->makeBetRecord($second, ['bet' => '2000.0000']);

        $rows = $this->service->getPlatformBettingRanking($this->agent, 3)->values();

        $this->assertCount(2, $rows);
        // 同額也各自編號，不共享名次（見 getPlatformBettingRanking 的行為說明）
        $this->assertSame(1, $rows[0]->rank);
        $this->assertSame(2, $rows[1]->rank);
    }

    public function test_platform_betting_excludes_below_threshold(): void
    {
        $member = $this->createMember($this->agent);
        $this->makeBetRecord($member, ['bet' => '100.0000']);

        $rows = $this->service->getPlatformBettingRanking($this->agent, 3);

        $this->assertCount(0, $rows);
    }

    public function test_platform_betting_type_2_uses_previous_frontend_day(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-01 10:00:00', config('custom.frontend.timezone')));
        $member = $this->createMember($this->agent);

        // 昨天（frontend 時區）內的一筆
        $this->makeBetRecord($member, [
            'bet' => '1000.0000',
            'data_time' => Carbon::parse('2026-07-31 12:00:00', config('custom.frontend.timezone'))->toSystemTime(),
        ]);
        // 今天的一筆，type=1/2 依規則不應計入
        $this->makeBetRecord($member, [
            'bet' => '5000.0000',
            'data_time' => Carbon::parse('2026-08-01 09:00:00', config('custom.frontend.timezone'))->toSystemTime(),
        ]);

        $rows = $this->service->getPlatformBettingRanking($this->agent, 2)->values();

        $this->assertCount(1, $rows);
        $this->assertSame('1000.0000', (string) $rows[0]->sum_bet);
    }

    public function test_platform_betting_excludes_other_agent_hierarchy(): void
    {
        $other = Agent::factory()->create(['status' => 1]);
        $other->hierarchy_path = "/{$other->id}";
        $other->save();

        $member = $this->createMember($other);
        $this->makeBetRecord($member, ['bet' => '5000.0000']);

        $rows = $this->service->getPlatformBettingRanking($this->agent, 3);

        $this->assertCount(0, $rows);
    }
}
