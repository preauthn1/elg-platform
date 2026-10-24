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
 * 需要當日存款的活動 validate 邏輯測試
 * - MonthlyDepositService  (event_v2_id=3) 月限 + 存款條件
 * - MonthlyDeposit2Service (event_v2_id=4) 月限 + 需先完成 MonthlyDeposit
 * - DepositService         (event_v2_id=5) 純存款條件（無時間限制）
 *
 * 上述服務均設 deductFromMainWallet=true，AbstractEventV2 會先確認當日是否有存款
 *
 * php artisan test tests/Feature/Frontend/EventV2/ValidateWelcomeBonusServiceTest.php
 */
class ValidateWelcomeBonusServiceTest extends TestCase
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
            'reward_formula' => '50',
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
    private function createDepositAndBalance(float $amount = 100, float $balance = 200): void
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

    // ─── MonthlyDepositService (event_v2_id=3) ───────────────────────────────

    /** 有當日存款、餘額足夠、本月未參加，validate=true */
    public function test_welcome_bonus50_success(): void
    {
        $this->createDepositAndBalance();
        $detail = $this->createDetail(3);

        $response = $this->requestApi($this->apiPath, ['event_detail_id' => $detail->id]);

        $response->assertStatus(200)
            ->assertJson(['data' => ['validate' => true]]);
    }

    /** 本月已參加過，validate=false */
    public function test_welcome_bonus50_fail_monthly(): void
    {
        $this->createDepositAndBalance();
        $detail = $this->createDetail(3);

        // 本月任何狀態的參加記錄均觸發 monthly 限制
        EventV2UserJoin::insert([
            'event_v2_detail_id' => $detail->id,
            'member_id' => $this->member->id,
            'reward' => 50,
            'status' => EventV2UserJoinStatusEnum::COMPLETE->value,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->requestApi($this->apiPath, ['event_detail_id' => $detail->id]);

        $response->assertStatus(200)
            ->assertJson([
                'data' => [
                    'validate' => false,
                    'message' => '您這個月已經參加過這個活動，無法再次參加',
                ],
            ]);
    }

    /** 今日無存款（deductFromMainWallet 抽象檢查），validate=false */
    public function test_welcome_bonus50_fail_no_deposit(): void
    {
        $detail = $this->createDetail(3);
        // 不建立 Order，member.balance 維持預設

        $response = $this->requestApi($this->apiPath, ['event_detail_id' => $detail->id]);

        $response->assertStatus(200)
            ->assertJson([
                'data' => [
                    'validate' => false,
                    'message' => '儲值尚未達到標準',
                ],
            ]);
    }

    /** 有存款但主錢包餘額不足，validate=false */
    public function test_welcome_bonus50_fail_balance_insufficient(): void
    {
        Order::factory()->create([
            'member_id' => $this->member->id,
            'status' => OrderStatusEnum::DEPOSIT_FINISHED->value,
            'amount' => 100,
            'pay_time' => now(),
            'order_type_id' => 1,
            'admin' => 0,
        ]);
        // member.balance=0（預設），< deposit.amount=100
        $this->member->update(['balance' => 0]);

        $detail = $this->createDetail(3);

        $response = $this->requestApi($this->apiPath, ['event_detail_id' => $detail->id]);

        $response->assertStatus(200)
            ->assertJson([
                'data' => [
                    'validate' => false,
                    'message' => '主錢包餘額不足',
                ],
            ]);
    }

    // ─── MonthlyDeposit2Service (event_v2_id=4) ──────────────────────────────

    /** 本月未完成 WelcomeBonus50，validate=false */
    public function test_welcome_bonus100_fail_bonus50_not_completed(): void
    {
        $this->createDepositAndBalance();
        $detail = $this->createDetail(4);
        // 不建立 WelcomeBonus50（event_v2_id=3）完成記錄

        $response = $this->requestApi($this->apiPath, ['event_detail_id' => $detail->id]);

        $response->assertStatus(200)
            ->assertJson([
                'data' => [
                    'validate' => false,
                    'message' => '您尚未完成"歡迎獎金50%"這個活動，請先完成',
                ],
            ]);
    }

    /** 本月已完成 WelcomeBonus50，validate=true */
    public function test_welcome_bonus100_success(): void
    {
        $this->createDepositAndBalance();

        // 建立 WelcomeBonus50 的 detail 與 COMPLETE 參加記錄
        $bonus50Detail = $this->createDetail(3);
        EventV2UserJoin::insert([
            'event_v2_detail_id' => $bonus50Detail->id,
            'member_id' => $this->member->id,
            'reward' => 50,
            'status' => EventV2UserJoinStatusEnum::COMPLETE->value,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $detail = $this->createDetail(4);

        $response = $this->requestApi($this->apiPath, ['event_detail_id' => $detail->id]);

        $response->assertStatus(200)
            ->assertJson(['data' => ['validate' => true]]);
    }

    /** WelcomeBonus100 本月已參加過，validate=false */
    public function test_welcome_bonus100_fail_monthly(): void
    {
        $this->createDepositAndBalance();

        // WelcomeBonus50 完成
        $bonus50Detail = $this->createDetail(3);
        EventV2UserJoin::insert([
            'event_v2_detail_id' => $bonus50Detail->id,
            'member_id' => $this->member->id,
            'reward' => 50,
            'status' => EventV2UserJoinStatusEnum::COMPLETE->value,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // WelcomeBonus100 本月也參加過
        $detail = $this->createDetail(4);
        EventV2UserJoin::insert([
            'event_v2_detail_id' => $detail->id,
            'member_id' => $this->member->id,
            'reward' => 100,
            'status' => EventV2UserJoinStatusEnum::COMPLETE->value,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->requestApi($this->apiPath, ['event_detail_id' => $detail->id]);

        $response->assertStatus(200)
            ->assertJson([
                'data' => [
                    'validate' => false,
                    'message' => '您這個月已經參加過這個活動，無法再次參加',
                ],
            ]);
    }

    // ─── DepositService（無時間限制，純存款條件）─────────────────────────────

    /** DepositService：有存款，validate=true */
    public function test_deposit_service_success(): void
    {
        $this->createDepositAndBalance();
        $detail = $this->createDetail($this->eventV2Id('DepositService'));

        $response = $this->requestApi($this->apiPath, ['event_detail_id' => $detail->id]);

        $response->assertStatus(200)
            ->assertJson(['data' => ['validate' => true]]);
    }

    /** DepositService：無存款，validate=false */
    public function test_deposit_service_fail_no_deposit(): void
    {
        $detail = $this->createDetail($this->eventV2Id('DepositService'));

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
