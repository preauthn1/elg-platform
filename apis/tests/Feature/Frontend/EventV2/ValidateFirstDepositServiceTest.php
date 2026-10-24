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
 * FirstDepositService 的 validate 邏輯測試
 * 條件：每位會員只能參加一次此 event_v2_detail，且需符合活動開始後第一筆符合 condition_formula 的儲值
 *
 * php artisan test tests/Feature/Frontend/EventV2/ValidateFirstDepositServiceTest.php
 */
class ValidateFirstDepositServiceTest extends TestCase
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

    /**
     * 建立 FirstDepositService 的活動詳細
     */
    private function createDetail(array $overrides = [])
    {
        $eventV2Id = EventV2::where('handler_class', 'FirstDepositService')->value('id');

        $detail = EventV2Detail::factory()->create(array_merge([
            'event_v2_id' => $eventV2Id,
            'agent_id' => $this->agent->id,
            'condition_formula' => 'deposit >= 30',
            'condition_config' => null,
            'reward_formula' => 'deposit',
            'balance_formula' => null,
            'withdraw_formula' => null,
            'flow_formula' => null,
        ], $overrides));

        EventV2DetailLang::create([
            'event_v2_detail_id' => $detail->id,
            'language_id' => LanguageEnum::ZH_TW->value,
            'name' => '首儲禮',
            'image' => 'image',
            'reward_name' => '首儲禮',
            'content' => '首儲禮活動內容',
        ]);

        return $detail;
    }

    private function createDepositAndBalance(float $amount = 30, float $balance = 100): void
    {
        Order::factory()->create([
            'member_id' => $this->member->id,
            'status' => OrderStatusEnum::DEPOSIT_FINISHED->value,
            'amount' => $amount,
            'pay_time' => now(),
            'order_type_id' => 1,
            'admin' => 0,
        ]);
        $this->member->update(['balance' => $balance]);
    }

    /** 有符合條件的當日儲值、餘額足夠、尚未參加過，validate=true */
    public function test_validate_success(): void
    {
        $this->createDepositAndBalance();
        $detail = $this->createDetail();

        $response = $this->requestApi($this->apiPath, ['event_detail_id' => $detail->id]);

        $response->assertStatus(200)
            ->assertJson(['data' => ['validate' => true]]);
    }

    /** 今日無儲值，validate=false */
    public function test_validate_fail_no_deposit(): void
    {
        $detail = $this->createDetail();

        $response = $this->requestApi($this->apiPath, ['event_detail_id' => $detail->id]);

        $response->assertStatus(200)
            ->assertJson([
                'data' => [
                    'validate' => false,
                    'message' => '儲值尚未達到標準',
                ],
            ]);
    }

    /** 有符合條件的儲值，但主錢包餘額不足，validate=false */
    public function test_validate_fail_balance_insufficient(): void
    {
        $this->createDepositAndBalance(amount: 30, balance: 0);
        $detail = $this->createDetail();

        $response = $this->requestApi($this->apiPath, ['event_detail_id' => $detail->id]);

        $response->assertStatus(200)
            ->assertJson([
                'data' => [
                    'validate' => false,
                    'message' => '主錢包餘額不足',
                ],
            ]);
    }

    /** 已以任何狀態參加過此活動，validate=false（FirstDepositService 只能參加一次） */
    public function test_validate_fail_already_joined(): void
    {
        $this->createDepositAndBalance();
        $detail = $this->createDetail();

        EventV2UserJoin::insert([
            'event_v2_detail_id' => $detail->id,
            'member_id' => $this->member->id,
            'reward' => 30,
            'status' => EventV2UserJoinStatusEnum::COMPLETE->value,
            'created_at' => now()->subDays(2),
            'updated_at' => now()->subDays(2),
        ]);

        $response = $this->requestApi($this->apiPath, ['event_detail_id' => $detail->id]);

        $response->assertStatus(200)
            ->assertJson([
                'data' => [
                    'validate' => false,
                    'message' => '您已經參加過這個活動，無法再次參加',
                ],
            ]);
    }

    /** 活動期間內有多筆符合條件的儲值，join() 應挑選 pay_time 最早的一筆，而非最新一筆 */
    public function test_join_picks_earliest_qualifying_deposit_in_event_period(): void
    {
        $detail = $this->createDetail([
            'reward_formula' => 'deposit',
            'start_time' => now()->subDays(5),
            'end_time' => now()->addDays(5),
        ]);

        $earlierOrder = Order::factory()->create([
            'member_id' => $this->member->id,
            'status' => OrderStatusEnum::DEPOSIT_FINISHED->value,
            'amount' => 40,
            'pay_time' => now()->subDays(3),
            'order_type_id' => 1,
            'admin' => 0,
        ]);
        Order::factory()->create([
            'member_id' => $this->member->id,
            'status' => OrderStatusEnum::DEPOSIT_FINISHED->value,
            'amount' => 80,
            'pay_time' => now()->subDay(),
            'order_type_id' => 1,
            'admin' => 0,
        ]);
        $this->member->update(['balance' => 200]);

        $response = $this->requestApi('frontend/api/event-v2/join', ['event_detail_id' => $detail->id]);

        $response->assertStatus(200)->assertJson(['code' => 200]);

        $userJoin = EventV2UserJoin::where('event_v2_detail_id', $detail->id)
            ->where('member_id', $this->member->id)
            ->first();

        $this->assertSame($earlierOrder->id, $userJoin->order_id);
        $this->assertEquals(40, $userJoin->reward);
    }

    /**
     * 活動期間第一筆符合條件的訂單已被拿去參加別的活動 V2，
     * validate=false，且不會退而求其次改用下一筆較晚、尚未被使用的訂單
     */
    public function test_validate_fail_first_qualifying_deposit_already_used_by_other_event(): void
    {
        $detail = $this->createDetail([
            'start_time' => now()->subDays(5),
            'end_time' => now()->addDays(5),
        ]);

        $earlierOrder = Order::factory()->create([
            'member_id' => $this->member->id,
            'status' => OrderStatusEnum::DEPOSIT_FINISHED->value,
            'amount' => 40,
            'pay_time' => now()->subDays(3),
            'order_type_id' => 1,
            'admin' => 0,
        ]);
        Order::factory()->create([
            'member_id' => $this->member->id,
            'status' => OrderStatusEnum::DEPOSIT_FINISHED->value,
            'amount' => 80,
            'pay_time' => now()->subDay(),
            'order_type_id' => 1,
            'admin' => 0,
        ]);
        $this->member->update(['balance' => 200]);

        // earlierOrder 已被拿去參加另一個 DepositService 活動
        $otherEventV2Id = EventV2::where('handler_class', 'DepositService')->value('id');
        $otherDetail = EventV2Detail::factory()->create([
            'event_v2_id' => $otherEventV2Id,
            'agent_id' => $this->agent->id,
            'condition_formula' => 'deposit >= 30',
        ]);
        EventV2UserJoin::insert([
            'event_v2_detail_id' => $otherDetail->id,
            'member_id' => $this->member->id,
            'order_id' => $earlierOrder->id,
            'reward' => 40,
            'status' => EventV2UserJoinStatusEnum::COMPLETE->value,
            'created_at' => now()->subDays(2),
            'updated_at' => now()->subDays(2),
        ]);

        $response = $this->requestApi($this->apiPath, ['event_detail_id' => $detail->id]);

        $response->assertStatus(200)
            ->assertJson([
                'data' => [
                    'validate' => false,
                    'message' => '此筆儲值已用於參加其他活動',
                ],
            ]);
    }

    /**
     * 活動開始後第一筆訂單金額不符合 condition_formula，validate=false，
     * 即使後面還有一筆符合條件、尚未使用的訂單，也不會遞補使用
     */
    public function test_validate_fail_first_deposit_not_meet_condition_formula(): void
    {
        $detail = $this->createDetail([
            'start_time' => now()->subDays(5),
            'end_time' => now()->addDays(5),
        ]);

        // 第一筆訂單金額 10，不符合 condition_formula: deposit >= 30
        Order::factory()->create([
            'member_id' => $this->member->id,
            'status' => OrderStatusEnum::DEPOSIT_FINISHED->value,
            'amount' => 10,
            'pay_time' => now()->subDays(3),
            'order_type_id' => 1,
            'admin' => 0,
        ]);
        // 第二筆訂單金額 50，符合條件，但因為不是「第一筆」不會被拿來用
        Order::factory()->create([
            'member_id' => $this->member->id,
            'status' => OrderStatusEnum::DEPOSIT_FINISHED->value,
            'amount' => 50,
            'pay_time' => now()->subDay(),
            'order_type_id' => 1,
            'admin' => 0,
        ]);
        $this->member->update(['balance' => 200]);

        $response = $this->requestApi($this->apiPath, ['event_detail_id' => $detail->id]);

        $response->assertStatus(200)
            ->assertJson([
                'data' => [
                    'validate' => false,
                    'message' => '儲值尚未達到標準',
                ],
            ]);
    }
}
