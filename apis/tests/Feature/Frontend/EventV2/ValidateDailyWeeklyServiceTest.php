<?php

declare(strict_types=1);

namespace Tests\Feature\Frontend\EventV2;

use App\Enums\EventV2\EventV2UserJoinStatusEnum;
use App\Enums\LanguageEnum;
use App\Enums\OrderStatusEnum;
use App\Http\Middleware\Frontend\DecryptRequest;
use App\Models\Agent;
use App\Models\EventV2;
use App\Models\EventV2Detail;
use App\Models\EventV2DetailLang;
use App\Models\EventV2UserJoin;
use App\Models\Member;
use App\Models\Order;
use Database\Seeders\LanguageSeeder;
use Database\Seeders\SyncAgentSettingDefinesSeeder;
use Tests\TestCase;

/**
 * 每日/每週週期活動 validate 邏輯測試
 * - DailyDepositService    (event_v2_id=6)  每日 + 存款條件
 * - DailyDepositRangeService (event_v2_id=7) 每日 + 存款條件
 * - WeeklyDepositService   (event_v2_id=8)  每週 + 存款條件
 * - DailyDepositComboService (event_v2_id=9) 每日 + 組合存款條件
 *
 * php artisan test tests/Feature/Frontend/EventV2/ValidateDailyWeeklyServiceTest.php
 */
class ValidateDailyWeeklyServiceTest extends TestCase
{
    private Agent $agent;

    private Member $member;

    private string $apiPath = 'frontend/api/event-v2/detail';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(LanguageSeeder::class);
        $this->seed(SyncAgentSettingDefinesSeeder::class);

        $this->agent = $this->createAgentAndDomain();
        $this->member = $this->createMemberAndToken($this->agent);

        $this->withoutMiddleware(DecryptRequest::class);
    }

    private function eventV2Id(string $handlerClass): int
    {
        return EventV2::where('handler_class', $handlerClass)->value('id');
    }

    /**
     * 建立指定 event_v2_id 的活動詳細
     */
    private function createDetail(int $eventV2Id, array $overrides = [])
    {
        $detail = EventV2Detail::factory()->create(array_merge([
            'event_v2_id' => $eventV2Id,
            'agent_id' => $this->agent->id,
            'condition_formula' => '1',  // 不觸發 str_contains 'deposit' 路徑
            'condition_config' => null,
            'reward_formula' => '30',
            'balance_formula' => null,
            'withdraw_formula' => null,
            'flow_formula' => null,
        ], $overrides));

        EventV2DetailLang::create([
            'event_v2_detail_id' => $detail->id,
            'language_id' => LanguageEnum::ZH_TW->value,
            'name' => 'Test Event',
            'image' => 'image',
            'reward_name' => 'Test',
            'content' => 'Test content',
        ]);

        return $detail;
    }

    /**
     * 建立今日存款成功的 Order，並給會員足夠餘額
     */
    private function createDepositAndBalance(float $amount = 100, float $balance = 200): Order
    {
        $order = Order::factory()->create([
            'member_id' => $this->member->id,
            'status' => OrderStatusEnum::DEPOSIT_FINISHED->value,
            'amount' => $amount,
            'pay_time' => now(),
            'order_type_id' => 1,
            'admin' => 0,
        ]);
        $this->member->update(['balance' => $balance]);

        return $order;
    }

    /**
     * 新增今日參加記錄（模擬已參加）
     */
    private function insertTodayJoin($detail, ?string $status = null): void
    {
        EventV2UserJoin::insert([
            'event_v2_detail_id' => $detail->id,
            'member_id' => $this->member->id,
            'reward' => 30,
            'status' => $status ?? EventV2UserJoinStatusEnum::COMPLETE->value,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    // ─── DailyDepositService ─────────────────────────────────────────────────

    /** 有今日存款、今日未參加，validate=true */
    public function test_daily_deposit_success(): void
    {
        $this->createDepositAndBalance();
        $detail = $this->createDetail($this->eventV2Id('DailyDepositService'));

        $response = $this->requestApi($this->apiPath, ['event_detail_id' => $detail->id]);

        $response->assertStatus(200)
            ->assertJson(['data' => ['validate' => true]]);
    }

    /** 今日已參加過，validate=false */
    public function test_daily_deposit_fail_daily(): void
    {
        $this->createDepositAndBalance();
        $detail = $this->createDetail($this->eventV2Id('DailyDepositService'));
        $this->insertTodayJoin($detail);

        $response = $this->requestApi($this->apiPath, ['event_detail_id' => $detail->id]);

        $response->assertStatus(200)
            ->assertJson([
                'data' => [
                    'validate' => false,
                    'message' => '您今天已經參加過這個活動，無法再次參加',
                ],
            ]);
    }

    // ─── DailyDepositRangeService ─────────────────────────────────────────────

    /** 今日未參加，validate=true */
    public function test_daily_deposit_range_success(): void
    {
        $this->createDepositAndBalance();
        $detail = $this->createDetail($this->eventV2Id('DailyDepositRangeService'));

        $response = $this->requestApi($this->apiPath, ['event_detail_id' => $detail->id]);

        $response->assertStatus(200)
            ->assertJson(['data' => ['validate' => true]]);
    }

    /** 今日已參加過，validate=false */
    public function test_daily_deposit_range_fail_daily(): void
    {
        $detail = $this->createDetail($this->eventV2Id('DailyDepositRangeService'));
        $this->insertTodayJoin($detail);

        $response = $this->requestApi($this->apiPath, ['event_detail_id' => $detail->id]);

        $response->assertStatus(200)
            ->assertJson([
                'data' => [
                    'validate' => false,
                    'message' => '您今天已經參加過這個活動，無法再次參加',
                ],
            ]);
    }

    // ─── WeeklyDepositService ─────────────────────────────────────────────────

    /** 本週未參加，validate=true */
    public function test_weekly_deposit_success(): void
    {
        $this->createDepositAndBalance();
        $detail = $this->createDetail($this->eventV2Id('WeeklyDepositService'));

        $response = $this->requestApi($this->apiPath, ['event_detail_id' => $detail->id]);

        $response->assertStatus(200)
            ->assertJson(['data' => ['validate' => true]]);
    }

    /** 本週已參加過，validate=false */
    public function test_weekly_deposit_fail_weekly(): void
    {
        $detail = $this->createDetail($this->eventV2Id('WeeklyDepositService'));

        // 本週內任何時間參加過都觸發限制
        // 使用前端時區的週一，避免系統 UTC 與前端 Taipei 跨週時對不上
        $weekStart = now()->toFrontendTime()->startOfWeek()->toSystemTime();
        EventV2UserJoin::insert([
            'event_v2_detail_id' => $detail->id,
            'member_id' => $this->member->id,
            'reward' => 30,
            'status' => EventV2UserJoinStatusEnum::COMPLETE->value,
            'created_at' => $weekStart,
            'updated_at' => $weekStart,
        ]);

        $response = $this->requestApi($this->apiPath, ['event_detail_id' => $detail->id]);

        $response->assertStatus(200)
            ->assertJson([
                'data' => [
                    'validate' => false,
                    'message' => '您這周已經參加過這個活動，無法再次參加',
                ],
            ]);
    }

    // ─── DailyDepositComboService ─────────────────────────────────────────────

    /** 今日有符合組合條件的存款，validate=true */
    public function test_daily_deposit_combo_success(): void
    {
        // 條件：今日 amount=100 的存款 >= 1 筆
        $detail = $this->createDetail($this->eventV2Id('DailyDepositComboService'), [
            'condition_config' => [['deposit' => 100, 'count' => 1]],
            'reward_config' => [['condition' => ['deposit' => 100, 'count' => 1], 'reward' => 20]],
            'balance_config' => [['condition' => ['deposit' => 100, 'count' => 1], 'balance' => 200]],
        ]);

        Order::factory()->create([
            'member_id' => $this->member->id,
            'status' => OrderStatusEnum::DEPOSIT_FINISHED->value,
            'amount' => 100,
            'pay_time' => now(),
            'order_type_id' => 1,
            'admin' => 0,
        ]);

        $response = $this->requestApi($this->apiPath, ['event_detail_id' => $detail->id]);

        $response->assertStatus(200)
            ->assertJson(['data' => ['validate' => true]]);
    }

    /** 今日無符合條件的存款，validate=false */
    public function test_daily_deposit_combo_fail_condition(): void
    {
        $detail = $this->createDetail($this->eventV2Id('DailyDepositComboService'), [
            'condition_config' => [['deposit' => 100, 'count' => 3]],
            'reward_config' => [['condition' => ['deposit' => 100, 'count' => 3], 'reward' => 60]],
            'balance_config' => [['condition' => ['deposit' => 100, 'count' => 3], 'balance' => 600]],
        ]);

        // 只有 1 筆，條件需要 3 筆
        Order::factory()->create([
            'member_id' => $this->member->id,
            'status' => OrderStatusEnum::DEPOSIT_FINISHED->value,
            'amount' => 100,
            'pay_time' => now(),
            'order_type_id' => 1,
            'admin' => 0,
        ]);

        $response = $this->requestApi($this->apiPath, ['event_detail_id' => $detail->id]);

        $response->assertStatus(200)
            ->assertJson([
                'data' => [
                    'validate' => false,
                    'message' => '儲值尚未達到標準',
                ],
            ]);
    }

    /** 今日已參加過（組合活動的每日限制），validate=false */
    public function test_daily_deposit_combo_fail_daily(): void
    {
        $detail = $this->createDetail($this->eventV2Id('DailyDepositComboService'), [
            'condition_config' => [['deposit' => 100, 'count' => 1]],
            'reward_config' => [['condition' => ['deposit' => 100, 'count' => 1], 'reward' => 20]],
            'balance_config' => [['condition' => ['deposit' => 100, 'count' => 1], 'balance' => 200]],
        ]);

        $this->insertTodayJoin($detail);

        $response = $this->requestApi($this->apiPath, ['event_detail_id' => $detail->id]);

        $response->assertStatus(200)
            ->assertJson([
                'data' => [
                    'validate' => false,
                    'message' => '您今天已經參加過這個活動，無法再次參加',
                ],
            ]);
    }
}
