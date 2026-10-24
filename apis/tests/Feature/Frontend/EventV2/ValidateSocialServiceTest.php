<?php

declare(strict_types=1);

namespace Tests\Feature\Frontend\EventV2;

use App\Enums\EventV2\EventV2UserJoinStatusEnum;
use App\Enums\LanguageEnum;
use App\Http\Middleware\Frontend\DecryptRequest;
use App\Models\Agent;
use App\Models\EventV2;
use App\Models\EventV2Detail;
use App\Models\EventV2DetailLang;
use App\Models\EventV2UserJoin;
use App\Models\Member;
use App\Models\MemberCode;
use Database\Seeders\LanguageSeeder;
use Database\Seeders\SyncAgentSettingDefinesSeeder;
use Tests\TestCase;

/**
 * 社交型活動 validate 邏輯測試
 * - InviteFriendService       邀請好友人數不足
 * - DownlineCommissionService 直屬下級輸分條件
 *
 * php artisan test tests/Feature/Frontend/EventV2/ValidateSocialServiceTest.php
 */
class ValidateSocialServiceTest extends TestCase
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
            'name' => 'Test Event',
            'image' => 'image',
            'reward_name' => 'Test',
            'content' => 'Test content',
        ]);

        return $detail;
    }

    // ─── InviteFriendService ─────────────────────────────────────────────────

    /**
     * 邀請人數不足（需要 3 人，目前 0 人），validate=false
     * MemberCodeService::get() 會自動建立 MemberCode，無需手動建立
     */
    public function test_invite_friend_fail_invite_count(): void
    {
        $detail = $this->createDetail($this->eventV2Id('InviteFriendService'), [
            'condition_config' => ['invite' => 3],
            'reward_formula' => 'invite * 10',
        ]);

        $response = $this->requestApi($this->apiPath, ['event_detail_id' => $detail->id]);

        $response->assertStatus(200)
            ->assertJson([
                'data' => [
                    'validate' => false,
                    'message' => '邀請人數不足，已邀請人數為 0',
                ],
            ]);
    }

    /**
     * 邀請人數需求為 0（condition_config.invite=0），validate=true
     */
    public function test_invite_friend_success_zero_required(): void
    {
        $detail = $this->createDetail($this->eventV2Id('InviteFriendService'), [
            'condition_config' => ['invite' => 0],
            'reward_formula' => '10',
        ]);

        $response = $this->requestApi($this->apiPath, ['event_detail_id' => $detail->id]);

        $response->assertStatus(200)
            ->assertJson(['data' => ['validate' => true]]);
    }

    // ─── DownlineCommissionService ───────────────────────────────────────────

    /**
     * 直屬下級無輸分記錄（totalWinLose=0），validate=false
     * 需手動建立 MemberCode（DownlineCommissionService 直接查詢，不自動建立）
     */
    public function test_downline_commission_fail_no_win_lose(): void
    {
        // DownlineCommissionService 直接查詢 MemberCode，不自動建立
        MemberCode::create([
            'member_id' => $this->member->id,
            'agent_code' => 'TESTCODE01',
        ]);

        $detail = $this->createDetail($this->eventV2Id('DownlineCommissionService'), [
            'condition_formula' => 'win_lose < 0',
            'condition_config' => ['dayOfWeek' => 0],
            'reward_formula' => 'win_lose * -1 * 0.05',
        ]);

        // 無任何 SummaryBetRecord → totalWinLose=0

        $response = $this->requestApi($this->apiPath, ['event_detail_id' => $detail->id]);

        $response->assertStatus(200)
            ->assertJson([
                'data' => [
                    'validate' => false,
                    'message' => '直屬下級會員結算不為輸分',
                ],
            ]);
    }

    /**
     * 本週已參加過，validate=false（weekly 限制先於 win_lose 檢查）
     */
    public function test_downline_commission_fail_weekly(): void
    {
        MemberCode::create([
            'member_id' => $this->member->id,
            'agent_code' => 'TESTCODE02',
        ]);

        $detail = $this->createDetail($this->eventV2Id('DownlineCommissionService'), [
            'condition_formula' => 'win_lose < 0',
            'condition_config' => ['dayOfWeek' => 0],
            'reward_formula' => 'win_lose * -1 * 0.05',
        ]);

        // 使用前端時區的週一，避免系統 UTC 與前端 Taipei 跨週時對不上
        $weekStart = now()->toFrontendTime()->startOfWeek()->toSystemTime();
        EventV2UserJoin::insert([
            'event_v2_detail_id' => $detail->id,
            'member_id' => $this->member->id,
            'reward' => 50,
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
}
