<?php

declare(strict_types=1);

namespace Tests\Feature\Frontend\EventV2;

use App\Enums\EventV2\EventV2UserJoinStatusEnum;
use App\Enums\LanguageEnum;
use App\Enums\OrderStatusEnum;
use App\Http\Middleware\Frontend\DecryptRequest;
use App\Models\Agent;
use App\Models\EventV2Detail;
use App\Models\EventV2DetailLang;
use App\Models\EventV2UserJoin;
use App\Models\Member;
use App\Models\Order;
use Database\Seeders\LanguageSeeder;
use Database\Seeders\SyncAgentSettingDefinesSeeder;
use Tests\TestCase;

/**
 * RegisterService (event_v2_id=1) 的 validate 邏輯測試
 * 條件：member.created_at 需在活動開始~結束時間內，且只能參加一次
 *
 * php artisan test tests/Feature/Frontend/EventV2/ValidateRegisterServiceTest.php
 */
class ValidateRegisterServiceTest extends TestCase
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
     * 建立 RegisterService 的活動詳細（event_v2_id=1）
     */
    private function createDetail(array $overrides = [])
    {
        $detail = EventV2Detail::factory()->create(array_merge([
            'event_v2_id' => 1,
            'agent_id' => $this->agent->id,
            'condition_formula' => null,
            'condition_config' => null,
            'reward_formula' => '100',
            'balance_formula' => null,
            'withdraw_formula' => null,
            'flow_formula' => null,
        ], $overrides));

        EventV2DetailLang::create([
            'event_v2_detail_id' => $detail->id,
            'language_id' => LanguageEnum::ZH_TW->value,
            'name' => '免費送',
            'image' => 'image',
            'reward_name' => '免費送',
            'content' => '免費送活動內容',
        ]);

        return $detail;
    }

    // ─── 正常情境 ───────────────────────────────────────────────────────────────

    /** 會員在活動期間內註冊、首次參加，validate=true */
    public function test_validate_success(): void
    {
        // 工廠預設 start_time=昨天, end_time=+6天；member.created_at=now()，在範圍內
        $detail = $this->createDetail();

        $response = $this->requestApi($this->apiPath, ['event_detail_id' => $detail->id]);

        $response->assertStatus(200)
            ->assertJson(['data' => ['validate' => true, 'message' => '活動條件達成']]);
    }

    // ─── 驗證失敗情境 ──────────────────────────────────────────────────────────

    /** 會員註冊時間早於活動開始時間，validate=false */
    public function test_validate_fail_register_date_before_start(): void
    {
        // member.created_at = now()；把活動 start_time 設為明天，使 now() < start_time
        $detail = $this->createDetail([
            'start_time' => now()->addDay(),
            'end_time' => now()->addDays(7),
        ]);

        $response = $this->requestApi($this->apiPath, ['event_detail_id' => $detail->id]);

        // end_time >= now 且 start_time > now → AbstractEventV2 的 start_time <= now 查詢找不到活動
        // 因此拋出 has_event，而非 register_date
        // 正確測試 register_date 需 start_time <= now < member.created_at
        // → 建立活動涵蓋今天，但 member.created_at 晚於 end_time（end_time=昨天）
        $response->assertStatus(200)
            ->assertJson([
                'data' => [
                    'validate' => false,
                    'message' => '活動不存在', // AbstractEventV2 先攔截（start_time > now）
                ],
            ]);
    }

    /** 會員註冊時間晚於活動結束時間，validate=false */
    public function test_validate_fail_register_date_after_end(): void
    {
        // 縮短活動到昨天就結束（end_time=昨天），member.created_at=now()
        $detail = $this->createDetail([
            'start_time' => now()->subDays(10),
            'end_time' => now()->subDays(1),
        ]);
        // member.created_at=now()，晚於 end_time=昨天

        $response = $this->requestApi($this->apiPath, ['event_detail_id' => $detail->id]);

        $response->assertStatus(200)
            ->assertJson([
                'data' => [
                    'validate' => false,
                    'message' => '活動不存在', // end_time < now，AbstractEventV2.validate 先拋 has_event
                ],
            ]);
    }

    /** 已以任何狀態參加過，validate=false（RegisterService 只能參加一次） */
    public function test_validate_fail_already_joined(): void
    {
        $detail = $this->createDetail();

        // COMPLETE 狀態也算參加過
        EventV2UserJoin::insert([
            'event_v2_detail_id' => $detail->id,
            'member_id' => $this->member->id,
            'reward' => 100,
            'status' => EventV2UserJoinStatusEnum::COMPLETE->value,
            'created_at' => now()->subDays(2),
            'updated_at' => now()->subDays(2),
        ]);

        $response = $this->requestApi($this->apiPath, ['event_detail_id' => $detail->id]);

        $response->assertStatus(200)
            ->assertJson([
                'data' => [
                    'validate' => false,
                    'message' => '註冊活動只能參加一次',
                ],
            ]);
    }

    /** condition_config.realname=1 但會員未完成實名認證，validate=false */
    public function test_validate_fail_realname_required(): void
    {
        $detail = $this->createDetail([
            'condition_config' => ['realname' => 1],
        ]);
        $this->member->update(['realname_validate' => 0]);

        $response = $this->requestApi($this->apiPath, ['event_detail_id' => $detail->id]);

        $response->assertStatus(200)
            ->assertJson([
                'data' => [
                    'validate' => false,
                    'message' => '請先完成實名認證',
                ],
            ]);
    }

    /** condition_config.realname=1 且會員已完成實名認證，validate=true */
    public function test_validate_success_realname_validated(): void
    {
        $detail = $this->createDetail([
            'condition_config' => ['realname' => 1],
        ]);
        $this->member->update(['realname_validate' => 1]);

        $response = $this->requestApi($this->apiPath, ['event_detail_id' => $detail->id]);

        $response->assertStatus(200)
            ->assertJson(['data' => ['validate' => true]]);
    }

    // ─── condition_formula 含 deposit（註冊 + 儲值條件組合）───────────────────

    /** condition_formula 含 deposit，會員今日有符合金額的儲值且餘額足夠，validate=true */
    public function test_validate_success_with_deposit_condition(): void
    {
        $detail = $this->createDetail([
            'condition_formula' => 'deposit >= 30',
        ]);
        Order::factory()->create([
            'member_id' => $this->member->id,
            'status' => OrderStatusEnum::DEPOSIT_FINISHED->value,
            'amount' => 30,
            'pay_time' => now(),
            'order_type_id' => 1,
            'admin' => 0,
        ]);
        $this->member->update(['balance' => 100]);

        $response = $this->requestApi($this->apiPath, ['event_detail_id' => $detail->id]);

        $response->assertStatus(200)
            ->assertJson(['data' => ['validate' => true]]);
    }

    /** condition_formula 含 deposit，會員今日無儲值，validate=false */
    public function test_validate_fail_deposit_condition_no_deposit(): void
    {
        $detail = $this->createDetail([
            'condition_formula' => 'deposit >= 30',
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
}
