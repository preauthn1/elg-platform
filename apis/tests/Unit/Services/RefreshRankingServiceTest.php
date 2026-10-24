<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Enums\Event\EventActionIdEnum;
use App\Enums\Vendor\VendorMaintainTypeEnum;
use App\Enums\WalletEnum;
use App\Exceptions\ApiException;
use App\Models\Agent;
use App\Models\Event;
use App\Models\EventDetail;
use App\Models\EventName;
use App\Models\EventRefreshRanking;
use App\Models\EventRefreshRankingRecord;
use App\Models\EventVendor;
use App\Models\EventVendorInfo;
use App\Models\Game;
use App\Models\Member;
use App\Models\MemberWallet;
use App\Models\Prize;
use App\Models\PrizeContent;
use App\Models\Vendor;
use App\Services\RefreshRankingService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * RefreshRankingService 測試（刷分排行榜 event/refresh_ranking/*，tpower 獨有）
 *
 * php artisan test tests/Unit/Services/RefreshRankingServiceTest.php
 */
class RefreshRankingServiceTest extends TestCase
{
    private RefreshRankingService $service;

    private Agent $agent;

    private Member $member;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(RefreshRankingService::class);
        $this->agent = $this->createAgentAndDomain();
        $this->member = $this->createMember($this->agent, ['username' => 'abcdef']);
    }

    /**
     * @return array{event: Event, detail: EventDetail, ranking: EventRefreshRanking, prize: Prize, content: PrizeContent}
     */
    private function makeRankingEvent(array $detailOverrides = [], array $rankingOverrides = []): array
    {
        $event = Event::factory()->create([
            'agent_id' => $this->agent->id,
            'status' => 1,
            'content' => 'event content',
            'start_time' => now()->subDay(),
            'end_time' => now()->addDay(),
        ]);

        $detail = EventDetail::factory()->create(array_merge([
            'event_id' => $event->id,
            'action_id' => EventActionIdEnum::REFRESH_RANKING->value,
            'status' => 1,
            'point' => '888.0000',
            'vendor_id' => 0,
            'game_type_id' => 0,
            'start_time' => now()->subDay(),
            'end_time' => now()->addDay(),
        ], $detailOverrides));

        $ranking = EventRefreshRanking::create(array_merge([
            'event_id' => $event->id,
            'event_detail_id' => $detail->id,
            'no' => 1,
            'status' => 0,
            'admin' => 0,
        ], $rankingOverrides));

        $prize = Prize::create([
            'event_id' => $event->id,
            'type_id' => 3,
            'name' => 'ranking prize',
            'background' => '',
            'number' => 1,
            'is_repeatedly' => 0,
            'flow_limit_multiple' => 0,
            'max_point' => 0,
            'expire_date' => now()->addYear(),
            'info' => json_encode([]),
            'status' => 1,
        ]);

        $content = PrizeContent::create([
            'prize_id' => $prize->id,
            'type' => 0,
            'name' => '頭獎',
            'number' => 3,
            'sent' => 0,
            'level' => 1,
            'point' => 500,
            'percentage' => 100,
            'point_type' => 1,
            'point_percentage' => 0,
            'achieve_point' => 0,
            'flow_limit_multiple' => 0,
            'status' => 1,
            'admin' => 0,
        ]);

        return compact('event', 'detail', 'ranking', 'prize', 'content');
    }

    private function makeRecord(array $ctx, ?Member $member = null, array $overrides = []): EventRefreshRankingRecord
    {
        return EventRefreshRankingRecord::create(array_merge([
            'event_refresh_ranking_id' => $ctx['ranking']->id,
            'member_id' => ($member ?? $this->member)->id,
            'bet_amount' => '1000.0000',
            'last_bet_time' => now()->subHours(2),
            'prize_content_id' => 0,
            'ranking' => 1,
            'point' => 0,
            'type' => 0,
            'is_calc' => 1,
            'status' => 0,
            'admin' => 0,
        ], $overrides));
    }

    public function test_issue_list_returns_own_agent_issues_with_my_bet_amount(): void
    {
        $ctx = $this->makeRankingEvent();
        $this->makeRecord($ctx, $this->member, ['bet_amount' => '250.0000']);

        $list = $this->service->getIssueList($this->member, $this->agent);

        $this->assertCount(1, $list);
        $this->assertSame($ctx['ranking']->id, (int) $list[0]->id);
        $this->assertSame('250.0000', (string) $list[0]->my_bet_amount);
    }

    public function test_issue_list_defaults_bet_amount_to_zero_without_record(): void
    {
        $this->makeRankingEvent();

        $list = $this->service->getIssueList($this->member, $this->agent);

        // MySQL IFNULL(decimal_col, 0) 沿用該欄位的 decimal(18,4) 格式輸出
        $this->assertSame('0.0000', (string) $list[0]->my_bet_amount);
    }

    public function test_issue_list_excludes_other_agent_events(): void
    {
        $other = Agent::factory()->create(['status' => 1]);
        $other->hierarchy_path = "/{$other->id}";
        $other->save();
        $this->makeRankingEvent();

        $otherEvent = Event::factory()->create(['agent_id' => $other->id, 'status' => 1, 'start_time' => now()->subDay()]);
        $otherDetail = EventDetail::factory()->create([
            'event_id' => $otherEvent->id,
            'action_id' => EventActionIdEnum::REFRESH_RANKING->value,
            'status' => 1,
            'start_time' => now()->subDay(),
            'end_time' => now()->addDay(),
        ]);
        EventRefreshRanking::create([
            'event_id' => $otherEvent->id,
            'event_detail_id' => $otherDetail->id,
            'no' => 1,
            'status' => 0,
            'admin' => 0,
        ]);

        $list = $this->service->getIssueList($this->member, $this->agent);

        $this->assertCount(1, $list);
        $this->assertNotSame($otherEvent->id, (int) $list[0]->event_id);
    }

    public function test_leader_board_masks_other_usernames_keep_ends_and_keeps_own(): void
    {
        $ctx = $this->makeRankingEvent();
        $rival = $this->createMember($this->agent, ['username' => 'zzwwqq']);
        $this->makeRecord($ctx, $rival, ['bet_amount' => '2000.0000', 'ranking' => 1]);
        $this->makeRecord($ctx, $this->member, ['bet_amount' => '1000.0000', 'ranking' => 2]);

        $rows = $this->service->getLeaderBoard($this->member, $this->agent, $ctx['event']->id, 1, 1, 10);

        $this->assertCount(2, $rows);
        $this->assertSame('z****q', $rows[0]['username']);
        $this->assertSame('abcdef', $rows[1]['username']);
    }

    public function test_leader_board_appends_self_at_end_when_not_ranked(): void
    {
        $ctx = $this->makeRankingEvent();
        $rival = $this->createMember($this->agent, ['username' => 'zzwwqq']);
        // 只塞入其他人的紀錄，讓自己擠不進 per_page=1 的名次
        $this->makeRecord($ctx, $rival, ['bet_amount' => '2000.0000', 'ranking' => 1]);
        $this->makeRecord($ctx, $this->member, ['bet_amount' => '500.0000', 'ranking' => 2]);

        $rows = $this->service->getLeaderBoard($this->member, $this->agent, $ctx['event']->id, 1, 1, 1);

        $this->assertCount(2, $rows);
        $this->assertSame('--', $rows[1]['ranking']);
        $this->assertSame('abcdef', $rows[1]['username']);
    }

    public function test_leader_board_excludes_uncalculated_records(): void
    {
        $ctx = $this->makeRankingEvent();
        $rival = $this->createMember($this->agent, ['username' => 'zzwwqq']);
        $this->makeRecord($ctx, $rival, ['bet_amount' => '2000.0000', 'is_calc' => 0]);

        $rows = $this->service->getLeaderBoard($this->member, $this->agent, $ctx['event']->id, 1, 1, 10);

        $this->assertCount(0, $rows);
    }

    public function test_wallet_balance_creates_wallet_from_event_point_when_missing(): void
    {
        $this->makeRankingEvent();

        $balance = $this->service->getWalletBalance($this->member, $this->agent);

        $this->assertSame('888.0000', $balance);
        $this->assertDatabaseHas('member_wallets', [
            'member_id' => $this->member->id,
            'wallet_type_id' => WalletEnum::CODE_EVENT_RANKING->value,
            'balance' => '888.0000',
        ]);
    }

    public function test_wallet_balance_returns_existing_wallet_without_resetting(): void
    {
        $this->makeRankingEvent();
        MemberWallet::create([
            'member_id' => $this->member->id,
            'wallet_type_id' => WalletEnum::CODE_EVENT_RANKING->value,
            'balance' => '123.4500000000',
            'status' => 1,
            'admin' => 0,
        ]);

        $balance = $this->service->getWalletBalance($this->member, $this->agent);

        $this->assertSame('123.4500000000', $balance);
    }

    public function test_wallet_balance_throws_when_no_active_event(): void
    {
        $this->expectException(ApiException::class);
        $this->expectExceptionMessage(__('error_message.1901'));
        $this->service->getWalletBalance($this->member, $this->agent);
    }

    public function test_vendor_list_throws_when_agent_has_no_event_vendor_configured(): void
    {
        $this->expectException(ApiException::class);
        $this->expectExceptionMessage(__('error_message.1426'));
        $this->service->getVendorList($this->agent);
    }

    public function test_vendor_list_returns_configured_vendors_with_fallback_names(): void
    {
        $vendor = Vendor::factory()->create(['name' => 'legacy vendor name', 'status' => 1]);
        $info = EventVendorInfo::create([
            'name' => null,
            'vendor_id' => $vendor->id,
            'currency' => 'CNY',
            'rate' => 1,
            'transfer_min_amount' => 0.01,
            'info' => json_encode(['API_URL' => 'https://example.test']),
            'prepared_account_number' => 0,
            'account_prefix' => 'evt',
            'status' => 1,
            'admin' => 0,
        ]);
        EventVendor::create([
            'agent_id' => $this->agent->id,
            'vendor_id' => $vendor->id,
            'event_vendor_info_id' => $info->id,
            'status' => 1,
            'admin' => 0,
        ]);

        $vendors = $this->service->getVendorList($this->agent);

        $this->assertCount(1, $vendors);
        $this->assertSame('legacy vendor name', $vendors[0]->name);
        $this->assertSame(1, (int) $vendors[0]->status);
    }

    public function test_vendor_list_marks_maintained_vendor_status_as_wallet_closed(): void
    {
        $vendor = Vendor::factory()->create(['status' => 1]);
        $info = EventVendorInfo::create([
            'name' => 'event vendor',
            'vendor_id' => $vendor->id,
            'currency' => 'CNY',
            'rate' => 1,
            'transfer_min_amount' => 0.01,
            'info' => json_encode([]),
            'prepared_account_number' => 0,
            'account_prefix' => 'evt',
            'status' => 1,
            'admin' => 0,
        ]);
        EventVendor::create([
            'agent_id' => $this->agent->id,
            'vendor_id' => $vendor->id,
            'event_vendor_info_id' => $info->id,
            'status' => 1,
            'admin' => 0,
        ]);
        // VendorMaintenances 模型未宣告 guarded/fillable（尚無其他寫入場景），改走 query builder 避免動到模型
        DB::table('vendor_maintenances')->insert([
            'vendor_id' => $vendor->id,
            'type' => VendorMaintainTypeEnum::BOTH_CLOSED->value,
            'start_time' => now()->subHour(),
            'end_time' => now()->addHour(),
            'agent_id' => $this->agent->id,
            'exclude_agent_id' => 0,
            'status' => 1,
            'memo' => '',
            'created_at' => now(),
            'updated_at' => now(),
            'admin' => 0,
        ]);

        $vendors = $this->service->getVendorList($this->agent);

        $this->assertSame(VendorMaintainTypeEnum::WALLET_CLOSED->value, (int) $vendors[0]->status);
    }

    public function test_vendor_list_prefers_nearest_agent_configuration(): void
    {
        $parent = Agent::factory()->create(['status' => 1]);
        $parent->hierarchy_path = "/{$parent->id}";
        $parent->save();

        $this->agent->parent = $parent->id;
        $this->agent->hierarchy_path = "/{$parent->id}/{$this->agent->id}";
        $this->agent->save();

        $farVendor = Vendor::factory()->create(['name' => 'far vendor', 'status' => 1]);
        $nearVendor = Vendor::factory()->create(['name' => 'near vendor', 'status' => 1]);

        $farInfo = EventVendorInfo::create([
            'name' => null, 'vendor_id' => $farVendor->id, 'currency' => 'CNY', 'rate' => 1,
            'transfer_min_amount' => 0.01, 'info' => json_encode([]), 'prepared_account_number' => 0,
            'account_prefix' => 'evt', 'status' => 1, 'admin' => 0,
        ]);
        $nearInfo = EventVendorInfo::create([
            'name' => null, 'vendor_id' => $nearVendor->id, 'currency' => 'CNY', 'rate' => 1,
            'transfer_min_amount' => 0.01, 'info' => json_encode([]), 'prepared_account_number' => 0,
            'account_prefix' => 'evt', 'status' => 1, 'admin' => 0,
        ]);

        EventVendor::create([
            'agent_id' => $parent->id, 'vendor_id' => $farVendor->id,
            'event_vendor_info_id' => $farInfo->id, 'status' => 1, 'admin' => 0,
        ]);
        EventVendor::create([
            'agent_id' => $this->agent->id, 'vendor_id' => $nearVendor->id,
            'event_vendor_info_id' => $nearInfo->id, 'status' => 1, 'admin' => 0,
        ]);

        $vendors = $this->service->getVendorList($this->agent->fresh());

        $this->assertCount(1, $vendors);
        $this->assertSame('near vendor', $vendors[0]->name);
    }

    public function test_game_list_returns_games_for_configured_vendor(): void
    {
        $vendor = Vendor::factory()->create(['status' => 1]);
        $info = EventVendorInfo::create([
            'name' => 'event vendor', 'vendor_id' => $vendor->id, 'currency' => 'CNY', 'rate' => 1,
            'transfer_min_amount' => 0.01, 'info' => json_encode([]), 'prepared_account_number' => 0,
            'account_prefix' => 'evt', 'status' => 1, 'admin' => 0,
        ]);
        EventVendor::create([
            'agent_id' => $this->agent->id, 'vendor_id' => $vendor->id,
            'event_vendor_info_id' => $info->id, 'status' => 1, 'admin' => 0,
        ]);
        $game = Game::factory()->create(['vendor_id' => $vendor->id, 'name' => 'slot A', 'status' => 1]);
        // EventVendorInfoGame 模型未宣告 guarded/fillable（尚無其他寫入場景），改走 query builder 避免動到模型
        DB::table('event_vendor_info_games')->insert([
            'event_vendor_info_id' => $info->id, 'game_id' => $game->id,
            'is_new' => 0, 'is_hot' => 0, 'sort' => 0, 'status' => 1,
            'created_at' => now(), 'updated_at' => now(), 'admin' => 0,
        ]);

        $games = $this->service->getGameList($this->agent, $vendor->id);

        $this->assertCount(1, $games);
        $this->assertSame('slot A', $games[0]->name);
    }

    public function test_game_list_throws_when_agent_has_no_event_vendor_configured(): void
    {
        $this->expectException(ApiException::class);
        $this->expectExceptionMessage(__('error_message.1426'));
        $this->service->getGameList($this->agent, 1);
    }

    public function test_awards_returns_localized_content_and_prizes(): void
    {
        $ctx = $this->makeRankingEvent();
        EventName::create([
            'event_id' => $ctx['event']->id,
            'language_id' => 2,
            'title' => 'localized title',
            'content' => 'localized content',
        ]);

        $default = $this->service->getAwards($this->agent, $ctx['event']->id, 1);
        $this->assertSame('event content', $default['content']);
        $this->assertCount(1, $default['prizes']);
        $this->assertSame('頭獎', $default['prizes'][0]->name);

        $localized = $this->service->getAwards($this->agent, $ctx['event']->id, 2);
        $this->assertSame('localized content', $localized['content']);
    }

    public function test_awards_rejects_unknown_event(): void
    {
        // 舊版查無活動時回 content = null；改為與老虎機爭霸賽一致，先擋代理層級再查
        $this->expectException(ApiException::class);
        $this->expectExceptionMessage(__('error_message.1901'));

        $this->service->getAwards($this->agent, 0, 1);
    }

    public function test_awards_rejects_other_agent_event(): void
    {
        $other = Agent::factory()->create(['status' => 1]);
        $other->hierarchy_path = "/{$other->id}";
        $other->save();
        $otherEvent = Event::factory()->create(['agent_id' => $other->id, 'status' => 1]);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage(__('error_message.1901'));

        $this->service->getAwards($this->agent, $otherEvent->id, 1);
    }

    public function test_leader_board_rejects_other_agent_event(): void
    {
        $other = Agent::factory()->create(['status' => 1]);
        $other->hierarchy_path = "/{$other->id}";
        $other->save();
        $otherEvent = Event::factory()->create(['agent_id' => $other->id, 'status' => 1]);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage(__('error_message.1901'));

        $this->service->getLeaderBoard($this->member, $this->agent, $otherEvent->id, 1, 1, 10);
    }

    public function test_leader_board_without_per_page_returns_all_rows_when_no_active_prize(): void
    {
        $ctx = $this->makeRankingEvent();
        // 獎項下架 → 預設每頁筆數的 SUM 為 0；舊版此時不加 LIMIT，須撈出全部名次
        $ctx['content']->update(['status' => 0]);

        $rival = $this->createMember($this->agent, ['username' => 'zzwwqq']);
        $this->makeRecord($ctx, $rival, ['bet_amount' => '2000.0000', 'ranking' => 1]);
        $this->makeRecord($ctx, $this->member, ['bet_amount' => '1000.0000', 'ranking' => 2]);

        $rows = $this->service->getLeaderBoard($this->member, $this->agent, $ctx['event']->id, 1, null, null);

        $this->assertCount(2, $rows);
    }

    public function test_leader_board_appends_self_even_when_not_calculated(): void
    {
        $ctx = $this->makeRankingEvent();
        $rival = $this->createMember($this->agent, ['username' => 'zzwwqq']);
        $this->makeRecord($ctx, $rival, ['bet_amount' => '2000.0000', 'ranking' => 1]);
        // 自己已下注但批次還沒跑（is_calc = 0）：舊版仍以 `--` 補在尾端，不可被主排行榜條件濾掉
        $this->makeRecord($ctx, $this->member, ['bet_amount' => '500.0000', 'ranking' => 0, 'is_calc' => 0]);

        $rows = $this->service->getLeaderBoard($this->member, $this->agent, $ctx['event']->id, 1, 1, 10);

        $this->assertCount(2, $rows);
        $this->assertSame('--', $rows[1]['ranking']);
        $this->assertSame('abcdef', $rows[1]['username']);
    }
}
