<?php

declare(strict_types=1);

namespace Tests\Feature\Frontend\EventV2;

use App\Enums\EventV2\EventV2UserJoinStatusEnum;
use App\Enums\FlowLimitStatusEnum;
use App\Enums\FlowLimitTypeEnum;
use App\Enums\LanguageEnum;
use App\Http\Middleware\Frontend\DecryptRequest;
use App\Models\Agent;
use App\Models\EventV2BonusCode;
use App\Models\EventV2Detail;
use App\Models\EventV2DetailLang;
use App\Models\EventV2UserJoin;
use App\Models\FlowLimit;
use App\Models\Member;
use Database\Seeders\LanguageSeeder;
use Database\Seeders\SyncAgentSettingDefinesSeeder;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * php artisan test tests/Feature/Frontend/EventV2/DetailTest.php
 */
class DetailTest extends TestCase
{
    private Agent $agent;

    private ?Member $member = null;

    private string $apiPath = 'frontend/api/event-v2/detail';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(LanguageSeeder::class);
        $this->seed(SyncAgentSettingDefinesSeeder::class);

        $this->agent = $this->createAgentAndDomain();

        $this->member = $this->createMemberAndToken($this->agent);

        $this->creatEventDetail($this->agent);

        // 繞過 DecryptRequest 中間件
        $this->withoutMiddleware(DecryptRequest::class);
    }

    public function creatEventDetail(Agent $agent)
    {
        $detail = EventV2Detail::factory()->create([
            'id' => 2,
            'event_v2_id' => 2,
            'agent_id' => $agent->id,
            'condition_formula' => null,
            'condition_config' => null,
            'reward_formula' => '19',
            'reward_config' => null,
            'balance_formula' => '300',
            'balance_config' => null,
            'withdraw_formula' => '10',
            'withdraw_config' => null,
            'flow_formula' => null,
            'flow_config' => null,
        ]);
        EventV2DetailLang::create([
            'event_v2_detail_id' => $detail->id,
            'language_id' => LanguageEnum::ZH_TW->value,
            'name' => '每日 免费 AUD 19',
            'image' => 'image',
            'reward_name' => '每日 免费 AUD 19',
            'content' => '每日 免费 AUD 19',
        ]);

        return $detail;
    }

    // ─── 正常情境 ───────────────────────────────────────────────────────────────

    /** 成功取得活動詳細，validate=true */
    public function test_detail_success(): void
    {
        $detail = EventV2Detail::find(2);
        $response = $this->requestApi($this->apiPath, ['event_detail_id' => 2]);

        $response->assertStatus(200)
            ->assertJson([
                'code' => 200,
                'message' => '成功',
                'data' => [
                    'content' => '每日 免费 AUD 19',
                    'join_withdraw' => false,
                    'validate' => true,
                    'message' => '活動條件達成',
                    'bonus_code' => [],
                    'end_time' => Carbon::parse($detail->end_time)->toFrontendTime()->toDateTimeString(),
                ],
            ]);
    }

    /** join_withdraw=1 時，回傳欄位為 true */
    public function test_detail_join_withdraw_is_true(): void
    {
        $detail = EventV2Detail::factory()->create([
            'event_v2_id' => 2,
            'agent_id' => $this->agent->id,
            'join_withdraw' => 1,
            'reward_formula' => '19',
        ]);
        EventV2DetailLang::create([
            'event_v2_detail_id' => $detail->id,
            'language_id' => LanguageEnum::ZH_TW->value,
            'name' => '立即領獎活動',
            'image' => 'image',
            'reward_name' => '立即領獎',
            'content' => '立即領獎內容',
        ]);

        $response = $this->requestApi($this->apiPath, ['event_detail_id' => $detail->id]);

        $response->assertStatus(200)
            ->assertJson([
                'data' => [
                    'join_withdraw' => true,
                    'validate' => true,
                ],
            ]);
    }

    /** 語系 fallback：會員語系無對應內容時，回傳預設英文內容 */
    public function test_detail_language_fallback_to_default(): void
    {
        // 只建立英文（id=1）語系內容，不建立 ZH_TW
        $detail = EventV2Detail::factory()->create([
            'event_v2_id' => 2,
            'agent_id' => $this->agent->id,
            'reward_formula' => '10',
        ]);
        EventV2DetailLang::create([
            'event_v2_detail_id' => $detail->id,
            'language_id' => LanguageEnum::EN->value, // 僅英文
            'name' => 'Daily Free EN',
            'image' => 'image',
            'reward_name' => 'Daily Free',
            'content' => 'English content only',
        ]);

        // 預設 header Accept-Language: zh-TW，找不到 ZH_TW 時應 fallback 至 EN
        $response = $this->requestApi($this->apiPath, ['event_detail_id' => $detail->id]);

        $response->assertStatus(200)
            ->assertJson([
                'data' => [
                    'content' => 'English content only',
                    'validate' => true,
                ],
            ]);
    }

    /** 會員已有該活動的歷史優惠碼，response 包含 bonus_code 陣列 */
    public function test_detail_with_bonus_codes(): void
    {
        // COMPLETE 狀態不觸發 has_join / daily 驗證失敗
        $joinId = EventV2UserJoin::insertGetId([
            'event_v2_detail_id' => 2,
            'member_id' => $this->member->id,
            'reward' => 19,
            'status' => EventV2UserJoinStatusEnum::COMPLETE->value,
            'created_at' => now()->subDays(3),
            'updated_at' => now()->subDays(3),
        ]);
        EventV2BonusCode::create([
            'event_v2_user_join_id' => $joinId,
            'vendor_id' => 1,
            'bonus_code' => 'TESTCODE123',
        ]);

        $response = $this->requestApi($this->apiPath, ['event_detail_id' => 2]);

        $response->assertStatus(200)
            ->assertJson([
                'data' => [
                    'validate' => true,
                    'bonus_code' => [
                        ['bonus_code' => 'TESTCODE123'],
                    ],
                ],
            ]);
    }

    // ─── validate=false 情境 ─────────────────────────────────────────────────────

    /** 已參加同一活動（status=JOIN），回傳 validate=false */
    public function test_detail_joined_error(): void
    {
        EventV2UserJoin::insert([
            'event_v2_detail_id' => 2,
            'reward' => 10,
            'member_id' => $this->member->id,
            'status' => EventV2UserJoinStatusEnum::JOIN->value,
        ]);

        $response = $this->requestApi($this->apiPath, ['event_detail_id' => 2]);

        $response->assertStatus(200)
            ->assertJson([
                'code' => 200,
                'message' => '成功',
                'data' => [
                    'content' => '每日 免费 AUD 19',
                    'validate' => false,
                    'message' => '您已經參加過這個活動，無法再次參加',
                ],
            ]);
    }

    /** 今日已參加過（DailyFree 每日一次限制），回傳 validate=false */
    public function test_detail_daily_already_joined_today(): void
    {
        // COMPLETE 不觸發 has_join，但今日 date 仍觸發 daily 限制
        EventV2UserJoin::insert([
            'event_v2_detail_id' => 2,
            'member_id' => $this->member->id,
            'reward' => 19,
            'status' => EventV2UserJoinStatusEnum::COMPLETE->value,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->requestApi($this->apiPath, ['event_detail_id' => 2]);

        $response->assertStatus(200)
            ->assertJson([
                'data' => [
                    'validate' => false,
                    'message' => '您今天已經參加過這個活動，無法再次參加',
                ],
            ]);
    }

    /** 同時參加另一個活動（一次只能參加一個），回傳 validate=false */
    public function test_detail_already_joined_another_event(): void
    {
        // 建立屬於另一個 event_v2（id=1，RegisterService）的 detail
        $otherDetail = EventV2Detail::factory()->create([
            'event_v2_id' => 1,
            'agent_id' => $this->agent->id,
        ]);
        // 以 JOIN 狀態加入另一個活動
        EventV2UserJoin::insert([
            'event_v2_detail_id' => $otherDetail->id,
            'member_id' => $this->member->id,
            'reward' => 0,
            'status' => EventV2UserJoinStatusEnum::JOIN->value,
        ]);

        $response = $this->requestApi($this->apiPath, ['event_detail_id' => 2]);

        $response->assertStatus(200)
            ->assertJson([
                'data' => [
                    'validate' => false,
                    'message' => '一次只能參加一個活動',
                ],
            ]);
    }

    /** 有未通過的稽核流水（非儲值/優惠碼類型）不能參加活動，回傳 validate=false */
    public function test_detail_member_has_pending_flow_limit(): void
    {
        FlowLimit::create([
            'member_id' => $this->member->id,
            'type' => FlowLimitTypeEnum::EVENT_LOCK_MODE->value,
            'event_id' => 1,
            'reference_id' => 0,
            'flow_limit' => 100,
            'reach' => 10,
            'status' => FlowLimitStatusEnum::PENDING->value,
            'admin' => 0,
        ]);

        $response = $this->requestApi($this->apiPath, ['event_detail_id' => 2]);

        $response->assertStatus(200)
            ->assertJson([
                'data' => [
                    'validate' => false,
                    'message' => '鎖分模式中不能參加活動',
                ],
            ]);
    }

    /** 單純 event_mode > 0 但沒有對應的稽核流水紀錄，不再阻擋參加活動 */
    public function test_detail_member_in_event_mode_without_flow_limit(): void
    {
        $this->member->update(['event_mode' => 1]);

        $response = $this->requestApi($this->apiPath, ['event_detail_id' => 2]);

        $response->assertStatus(200)
            ->assertJson([
                'data' => [
                    'validate' => true,
                ],
            ]);
    }

    /** 活動已過期（end_time < now），validate=false，訊息「活動不存在」 */
    public function test_detail_event_expired(): void
    {
        $expiredDetail = EventV2Detail::factory()->create([
            'event_v2_id' => 2,
            'agent_id' => $this->agent->id,
            'start_time' => now()->subDays(10),
            'end_time' => now()->subDay(), // 已過期
            'reward_formula' => '10',
        ]);
        EventV2DetailLang::create([
            'event_v2_detail_id' => $expiredDetail->id,
            'language_id' => LanguageEnum::ZH_TW->value,
            'name' => '過期活動',
            'image' => 'image',
            'reward_name' => '過期',
            'content' => '過期活動內容',
        ]);

        $response = $this->requestApi($this->apiPath, ['event_detail_id' => $expiredDetail->id]);

        $response->assertStatus(200)
            ->assertJson([
                'data' => [
                    'validate' => false,
                    'message' => '活動不存在',
                ],
            ]);
    }

    /** 活動已停用（status=0），validate=false，訊息「活動不存在」 */
    public function test_detail_event_disabled(): void
    {
        $disabledDetail = EventV2Detail::factory()->create([
            'event_v2_id' => 2,
            'agent_id' => $this->agent->id,
            'status' => 0,
            'reward_formula' => '10',
        ]);
        EventV2DetailLang::create([
            'event_v2_detail_id' => $disabledDetail->id,
            'language_id' => LanguageEnum::ZH_TW->value,
            'name' => '停用活動',
            'image' => 'image',
            'reward_name' => '停用',
            'content' => '停用活動內容',
        ]);

        $response = $this->requestApi($this->apiPath, ['event_detail_id' => $disabledDetail->id]);

        $response->assertStatus(200)
            ->assertJson([
                'data' => [
                    'validate' => false,
                    'message' => '活動不存在',
                ],
            ]);
    }

    // ─── Request 驗證 ─────────────────────────────────────────────────────────────

    /** 缺少必填參數 event_detail_id，回傳 400 */
    public function test_detail_missing_required_param(): void
    {
        $response = $this->requestApi($this->apiPath, []);

        $response->assertStatus(400);
    }

    /** event_detail_id 為非整數，回傳 400 */
    public function test_detail_invalid_param_type(): void
    {
        $response = $this->requestApi($this->apiPath, ['event_detail_id' => 'abc']);

        $response->assertStatus(400);
    }

    // ─── 身份驗證 ─────────────────────────────────────────────────────────────────

    /** 未帶 Authorization token，回傳 319（HTTP_REDIRECT_LOGOUT） */
    public function test_detail_unauthenticated(): void
    {
        $response = $this->postJson(
            uri: $this->apiPath,
            data: ['event_detail_id' => 2],
            headers: ['Accept-Language' => 'zh-TW'], // 不帶 Bearer token
        );

        $response->assertStatus(319);
    }
}
