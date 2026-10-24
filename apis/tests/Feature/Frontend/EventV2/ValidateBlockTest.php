<?php

declare(strict_types=1);

namespace Tests\Feature\Frontend\EventV2;

use App\Enums\EventV2\EventV2UserJoinStatusEnum;
use App\Enums\LanguageEnum;
use App\Enums\MemberBindingLogTypeEnum;
use App\Http\Middleware\Frontend\DecryptRequest;
use App\Models\Agent;
use App\Models\EventV2Detail;
use App\Models\EventV2DetailLang;
use App\Models\EventV2UserJoin;
use App\Models\Member;
use App\Models\RewardClaimRecord;
use Database\Seeders\LanguageSeeder;
use Database\Seeders\SyncAgentSettingDefinesSeeder;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * AbstractEventV2::validate() 的 IP / 手機 / 帳號 封鎖邏輯測試
 *
 * php artisan test tests/Feature/Frontend/EventV2/ValidateBlockTest.php
 */
class ValidateBlockTest extends TestCase
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
     * 建立具有唯一 username 的測試用輔助會員，避免 members_username_unique 衝突
     */
    private function createMemberB(Agent $agent, array $overrides = []): Member
    {
        $unique = uniqid('mb_');

        return $this->createMember($agent, array_merge([
            'username' => $unique,
            'nickname' => $unique,
        ], $overrides));
    }

    /**
     * 建立活動詳細，可自訂 ip_block / phone_block / account_block
     */
    private function createDetail(array $overrides = []): EventV2Detail
    {
        $detail = EventV2Detail::factory()->create(array_merge([
            'event_v2_id' => 2, // DailyFreeService
            'agent_id' => $this->agent->id,
            'condition_formula' => null,
            'condition_config' => null,
            'reward_formula' => '10',
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

    // ══════════════════════════════════════════════════════
    //  IP Block
    // ══════════════════════════════════════════════════════

    /**
     * ip_block=ALL_MEMBER(1)：同代理、相同 register_ip 且更早建立的會員 → 封鎖
     */
    public function test_ip_block_all_member_blocks(): void
    {
        // 建立比 member 更早創建的同代理會員，register_ip = 0.0.0.0
        $unique = uniqid('mb_');
        Member::factory()->create([
            'parent' => $this->agent->id,
            'username' => $unique,
            'nickname' => $unique,
            'register_ip' => ip2long('0.0.0.0'),
            'created_at' => $this->member->created_at->copy()->subDay(),
            'status' => 1,
            'admin' => 0,
        ]);

        $detail = $this->createDetail(['ip_block' => '1']);

        $response = $this->requestApi($this->apiPath, ['event_detail_id' => $detail->id]);

        $response->assertStatus(200)
            ->assertJson([
                'data' => [
                    'validate' => false,
                    'message' => '此IP已經參加過活動',
                ],
            ]);
    }

    /**
     * ip_block=ALL_EVENT(2)：同代理會員的 RewardClaimRecord 有相同 IP (type=4) → 封鎖
     */
    public function test_ip_block_all_event_blocks_v1(): void
    {
        $memberB = $this->createMemberB($this->agent);
        RewardClaimRecord::create([
            'member_id' => $memberB->id,
            'event_detail_id' => 0,
            'type' => 4, // IP 類型
            'value' => '0.0.0.0',
        ]);

        $detail = $this->createDetail(['ip_block' => '2']);

        $response = $this->requestApi($this->apiPath, ['event_detail_id' => $detail->id]);

        $response->assertStatus(200)
            ->assertJson([
                'data' => [
                    'validate' => false,
                    'message' => '此IP已經參加過活動',
                ],
            ]);
    }

    /**
     * ip_block=ALL_EVENT(2)：同代理會員的 EventV2UserJoin 有相同 IP → 封鎖
     */
    public function test_ip_block_all_event_blocks_v2(): void
    {
        $memberB = $this->createMemberB($this->agent);
        $otherDetail = $this->createDetail(['ip_block' => null]);

        EventV2UserJoin::insert([
            'event_v2_detail_id' => $otherDetail->id,
            'member_id' => $memberB->id,
            'reward' => 0,
            'ip' => '0.0.0.0',
            'status' => EventV2UserJoinStatusEnum::COMPLETE->value,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $detail = $this->createDetail(['ip_block' => '2']);

        $response = $this->requestApi($this->apiPath, ['event_detail_id' => $detail->id]);

        $response->assertStatus(200)
            ->assertJson([
                'data' => [
                    'validate' => false,
                    'message' => '此IP已經參加過活動',
                ],
            ]);
    }

    /**
     * ip_block=EVENT_V2(3)：同代理會員對同一活動 detail 有相同 IP 的 UserJoin → 封鎖
     */
    public function test_ip_block_event_v2_blocks(): void
    {
        $detail = $this->createDetail(['ip_block' => '3']);
        $memberB = $this->createMemberB($this->agent);

        EventV2UserJoin::insert([
            'event_v2_detail_id' => $detail->id,
            'member_id' => $memberB->id,
            'reward' => 0,
            'ip' => '0.0.0.0',
            'status' => EventV2UserJoinStatusEnum::COMPLETE->value,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->requestApi($this->apiPath, ['event_detail_id' => $detail->id]);

        $response->assertStatus(200)
            ->assertJson([
                'data' => [
                    'validate' => false,
                    'message' => '此IP已經參加過活動',
                ],
            ]);
    }

    /**
     * ip_block=ALL(1,2,3)：沒有任何符合的資料 → 所有 IP check 通過，validate=true
     */
    public function test_ip_block_no_match_passes(): void
    {
        $detail = $this->createDetail(['ip_block' => '1,2,3']);

        $response = $this->requestApi($this->apiPath, ['event_detail_id' => $detail->id]);

        $response->assertStatus(200)
            ->assertJson(['data' => ['validate' => true]]);
    }

    // ══════════════════════════════════════════════════════
    //  Phone Block
    // ══════════════════════════════════════════════════════

    /**
     * phone_block 設定，但會員手機未驗證 (mobile_validate=0) → 拋出 phone_not_validate
     */
    public function test_phone_not_validated_blocks(): void
    {
        $this->member->update(['mobile_validate' => 0, 'mobile' => '0912345678']);
        $detail = $this->createDetail(['phone_block' => '1']);

        $response = $this->requestApi($this->apiPath, ['event_detail_id' => $detail->id]);

        $response->assertStatus(200)
            ->assertJson([
                'data' => [
                    'validate' => false,
                    'message' => '請先綁定手機驗證',
                ],
            ]);
    }

    /**
     * phone_block=ALL_MEMBER(1)：同代理另一會員以相同手機較早進行綁定 → 封鎖
     */
    public function test_phone_block_all_member_blocks(): void
    {
        $phone = '0923456789';
        $this->member->update(['mobile' => $phone, 'mobile_validate' => 1]);

        $memberB = $this->createMemberB($this->agent, ['mobile_validate' => 1]);
        // created_at 在 MemberBindingLog 是 guarded，必須用 DB::table 繞過，才能設早於 member.created_at 的時間
        DB::table('member_binding_logs')->insert([
            'member_id' => $memberB->id,
            'type' => MemberBindingLogTypeEnum::BINDING_PHONE->value,
            'value' => $phone,
            'before_value' => null,
            'ip' => '0.0.0.0',
            'validate_type' => 1,
            'status' => 1,
            'remark' => '',
            'created_at' => $this->member->created_at->copy()->subDay()->toDateTimeString(),
            'updated_at' => $this->member->created_at->copy()->subDay()->toDateTimeString(),
            'admin' => 0,
        ]);

        $detail = $this->createDetail(['phone_block' => '1']);

        $response = $this->requestApi($this->apiPath, ['event_detail_id' => $detail->id]);

        $response->assertStatus(200)
            ->assertJson([
                'data' => [
                    'validate' => false,
                    'message' => '此手機已經參加過活動',
                ],
            ]);
    }

    /**
     * phone_block=ALL_EVENT(2)：同代理另一會員在 RewardClaimRecord (type=3) 中有相同手機 → 封鎖
     */
    public function test_phone_block_all_event_blocks_v1(): void
    {
        $phone = '0934567890';
        $this->member->update(['mobile' => $phone, 'mobile_validate' => 1]);

        $memberB = $this->createMemberB($this->agent, ['mobile_validate' => 1]);
        RewardClaimRecord::create([
            'member_id' => $memberB->id,
            'event_detail_id' => 0,
            'type' => 3, // 手機類型
            'value' => $phone,
        ]);

        $detail = $this->createDetail(['phone_block' => '2']);

        $response = $this->requestApi($this->apiPath, ['event_detail_id' => $detail->id]);

        $response->assertStatus(200)
            ->assertJson([
                'data' => [
                    'validate' => false,
                    'message' => '此手機已經參加過活動',
                ],
            ]);
    }

    /**
     * phone_block=ALL_EVENT(2)：同代理另一會員的 EventV2UserJoin.phone 相同 → 封鎖
     */
    public function test_phone_block_all_event_blocks_v2(): void
    {
        $phone = '0945678901';
        $this->member->update(['mobile' => $phone, 'mobile_validate' => 1]);

        $memberB = $this->createMemberB($this->agent);
        $otherDetail = $this->createDetail(['phone_block' => null]);

        EventV2UserJoin::insert([
            'event_v2_detail_id' => $otherDetail->id,
            'member_id' => $memberB->id,
            'reward' => 0,
            'phone' => $phone,
            'status' => EventV2UserJoinStatusEnum::COMPLETE->value,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $detail = $this->createDetail(['phone_block' => '2']);

        $response = $this->requestApi($this->apiPath, ['event_detail_id' => $detail->id]);

        $response->assertStatus(200)
            ->assertJson([
                'data' => [
                    'validate' => false,
                    'message' => '此手機已經參加過活動',
                ],
            ]);
    }

    /**
     * phone_block=EVENT_V2(3)：同代理另一會員對同一活動 detail 的 UserJoin.phone 相同 → 封鎖
     */
    public function test_phone_block_event_v2_blocks(): void
    {
        $phone = '0956789012';
        $this->member->update(['mobile' => $phone, 'mobile_validate' => 1]);

        $detail = $this->createDetail(['phone_block' => '3']);
        $memberB = $this->createMemberB($this->agent);

        EventV2UserJoin::insert([
            'event_v2_detail_id' => $detail->id,
            'member_id' => $memberB->id,
            'reward' => 0,
            'phone' => $phone,
            'status' => EventV2UserJoinStatusEnum::COMPLETE->value,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->requestApi($this->apiPath, ['event_detail_id' => $detail->id]);

        $response->assertStatus(200)
            ->assertJson([
                'data' => [
                    'validate' => false,
                    'message' => '此手機已經參加過活動',
                ],
            ]);
    }

    /**
     * phone_block=ALL(1,2,3)：mobile_validate=1，無符合資料 → 所有手機 check 通過，validate=true
     */
    public function test_phone_block_no_match_passes(): void
    {
        $this->member->update(['mobile' => '0999999999', 'mobile_validate' => 1]);
        $detail = $this->createDetail(['phone_block' => '1,2,3']);

        $response = $this->requestApi($this->apiPath, ['event_detail_id' => $detail->id]);

        $response->assertStatus(200)
            ->assertJson(['data' => ['validate' => true]]);
    }

    // ══════════════════════════════════════════════════════
    //  Account Block
    // ══════════════════════════════════════════════════════

    /**
     * account_block=ALL_MEMBER(1)：同代理另一會員有相同 first_name 且更早建立 → 封鎖
     */
    public function test_account_block_all_member_blocks(): void
    {
        $firstName = 'DuplicateName99';
        $this->member->update(['first_name' => $firstName]);

        $unique = uniqid('mb_');
        Member::factory()->create([
            'parent' => $this->agent->id,
            'username' => $unique,
            'nickname' => $unique,
            'first_name' => $firstName,
            'created_at' => $this->member->created_at->copy()->subDay(),
            'status' => 1,
            'admin' => 0,
        ]);

        $detail = $this->createDetail(['account_block' => '1']);

        $response = $this->requestApi($this->apiPath, ['event_detail_id' => $detail->id]);

        $response->assertStatus(200)
            ->assertJson([
                'data' => [
                    'validate' => false,
                    'message' => '此帳號已經參加過活動',
                ],
            ]);
    }

    /**
     * account_block=ALL_MEMBER(1)：同姓名帳號較晚註冊 → 不封鎖較早註冊的帳號
     */
    public function test_account_block_all_member_does_not_block_earlier_registered(): void
    {
        $firstName = 'DuplicateName99';
        $this->member->update(['first_name' => $firstName]);

        $unique = uniqid('mb_');
        Member::factory()->create([
            'parent' => $this->agent->id,
            'username' => $unique,
            'nickname' => $unique,
            'first_name' => $firstName,
            'created_at' => $this->member->created_at->copy()->addDay(),
            'status' => 1,
            'admin' => 0,
        ]);

        $detail = $this->createDetail(['account_block' => '1']);

        $response = $this->requestApi($this->apiPath, ['event_detail_id' => $detail->id]);

        $response->assertStatus(200)
            ->assertJson(['data' => ['validate' => true]]);
    }

    /**
     * account_block=ALL_EVENT(2)：同代理另一會員在 RewardClaimRecord (type=1) 中有相同 first_name → 封鎖
     */
    public function test_account_block_all_event_blocks_v1(): void
    {
        $firstName = 'DuplicateAccount01';
        $this->member->update(['first_name' => $firstName]);

        $memberB = $this->createMemberB($this->agent);
        RewardClaimRecord::create([
            'member_id' => $memberB->id,
            'event_detail_id' => 0,
            'type' => 1, // 真實姓名類型
            'value' => $firstName,
        ]);

        $detail = $this->createDetail(['account_block' => '2']);

        $response = $this->requestApi($this->apiPath, ['event_detail_id' => $detail->id]);

        $response->assertStatus(200)
            ->assertJson([
                'data' => [
                    'validate' => false,
                    'message' => '此帳號已經參加過活動',
                ],
            ]);
    }

    /**
     * account_block=ALL_EVENT(2)：同代理另一會員有 EventV2UserJoin 且 first_name 相同 → 封鎖
     */
    public function test_account_block_all_event_blocks_v2(): void
    {
        $firstName = 'DuplicateAccount02';
        $this->member->update(['first_name' => $firstName]);

        $memberB = $this->createMemberB($this->agent, ['first_name' => $firstName]);
        $otherDetail = $this->createDetail(['account_block' => null]);

        EventV2UserJoin::insert([
            'event_v2_detail_id' => $otherDetail->id,
            'member_id' => $memberB->id,
            'reward' => 0,
            'status' => EventV2UserJoinStatusEnum::COMPLETE->value,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $detail = $this->createDetail(['account_block' => '2']);

        $response = $this->requestApi($this->apiPath, ['event_detail_id' => $detail->id]);

        $response->assertStatus(200)
            ->assertJson([
                'data' => [
                    'validate' => false,
                    'message' => '此帳號已經參加過活動',
                ],
            ]);
    }

    /**
     * account_block=EVENT_V2(3)：同代理另一會員對同一活動 detail 有 UserJoin 且 first_name 相同 → 封鎖
     */
    public function test_account_block_event_v2_blocks(): void
    {
        $firstName = 'DuplicateAccount03';
        $this->member->update(['first_name' => $firstName]);

        $detail = $this->createDetail(['account_block' => '3']);
        $memberB = $this->createMemberB($this->agent, ['first_name' => $firstName]);

        EventV2UserJoin::insert([
            'event_v2_detail_id' => $detail->id,
            'member_id' => $memberB->id,
            'reward' => 0,
            'status' => EventV2UserJoinStatusEnum::COMPLETE->value,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->requestApi($this->apiPath, ['event_detail_id' => $detail->id]);

        $response->assertStatus(200)
            ->assertJson([
                'data' => [
                    'validate' => false,
                    'message' => '此帳號已經參加過活動',
                ],
            ]);
    }

    /**
     * account_block=ALL(1,2,3)：沒有符合的資料 → 所有帳號 check 通過，validate=true
     */
    public function test_account_block_no_match_passes(): void
    {
        $this->member->update(['first_name' => 'UniqueNameXYZ999']);
        $detail = $this->createDetail(['account_block' => '1,2,3']);

        $response = $this->requestApi($this->apiPath, ['event_detail_id' => $detail->id]);

        $response->assertStatus(200)
            ->assertJson(['data' => ['validate' => true]]);
    }
}
