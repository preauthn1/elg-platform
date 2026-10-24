<?php

declare(strict_types=1);

namespace Tests\Feature\Agent\EventV2;

use App\Enums\EventV2\EventV2DetailCostTypeEnum;
use App\Enums\EventV2\EventV2ValidateCodeEnum;
use App\Enums\MemberRestrictionTargetEnum;
use App\Http\Middleware\Frontend\DecryptRequest;
use App\Http\Middleware\RequestLogger;
use App\Http\Requests\Agent\EventV2\CreateRequest;
use App\Models\Agent;
use App\Models\AgentAccessToken;
use App\Models\EventV2;
use App\Models\EventV2Detail;
use App\Models\EventV2DetailTarget;
use App\Models\Member;
use App\Models\MemberGroup;
use App\Services\EventV2\DailyFreeService;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * 活動 V2 目標用戶（event_v2_detail_targets，target/tickets#4234，所有類型共用）。
 *
 * 必須成立：
 * 1. 後台：類型 1=指定會員、2=會員群組；選了類型必須選內容、沒選類型不能帶內容；
 *    只能從活動代理商帳號那條線裡選（會員在它底下、群組由它的上層／自己／下線建立），選項接口 target-options 同一份規則。
 * 2. 後台：舊後台不送 target_type＝沿用原值；送 0＝清成不限制；詳情帶回類型與已勾選的 id／名稱。
 * 3. 參加：沒有目標列＝不限制（既有活動行為不變）；有的話本人被指定、或他目前的群組被指定才可參加，
 *    否則回 TARGET_NOT_MATCHED。群組調整即時生效（不快照成員）。
 *
 * php artisan test tests/Feature/Agent/EventV2/EventTargetsTest.php
 */
class EventTargetsTest extends TestCase
{
    private const string CREATE = 'agent/api/event-v2/create';

    private const string UPDATE = 'agent/api/event-v2/update';

    private const string DETAIL = 'agent/api/event-v2/detail';

    private Agent $agent;

    private int $typeId;

    protected function setUp(): void
    {
        parent::setUp();

        if (array_key_exists('is_backend_deposit', (new CreateRequest)->rules())
            && !Schema::hasColumn('event_v2_details', 'is_backend_deposit')) {
            $this->markTestSkipped('event_v2_details.is_backend_deposit 尚無 migration（target/tickets#4352），後台活動 V2 端點無法在乾淨庫執行');
        }

        $this->withoutMiddleware([DecryptRequest::class, RequestLogger::class]);

        $this->agent = $this->createAgentAndDomain(['host_id' => 1]);
        $token = [REDACTED];
        AgentAccessToken:[REDACTED]
            'agent_id' => $this->agent->id,
            'token' => $token,
            'expire_date' => now()->addHours(2)->toDateTimeString(),
            'admin' => 1,
        ]);
        $this->addHeader('Authorization', 'Bearer ' . $token);

        $this->typeId = EventV2::query()->firstOrCreate(['handler_class' => 'DailyFreeService'], ['name' => '每日登入', 'schedule' => null])->id;
    }

    // -------------------------------------------------------
    // 後台建立／更新／詳情
    // -------------------------------------------------------

    public function test_create_with_member_groups_and_detail_returns_them(): void
    {
        $vip = $this->group('VIP');
        $gold = $this->group('黃金');

        $this->create(['target_type' => MemberRestrictionTargetEnum::MEMBER_GROUP->value, 'target_ids' => [$vip->id, $gold->id]])
            ->assertOk()->assertJsonPath('code', 200);
        $detail = $this->latestDetail();

        $this->assertSame(2, EventV2DetailTarget::query()->where('event_v2_detail_id', $detail->id)->where('target_type', 'member_group')->count());

        $res = $this->postJson(self::DETAIL, ['event_detail_id' => $detail->id], $this->headers)->assertOk();
        $this->assertSame(MemberRestrictionTargetEnum::MEMBER_GROUP->value, $res->json('data.target_type'));
        $this->assertEqualsCanonicalizing([$vip->name, $gold->name], array_column($res->json('data.targets'), 'name'));
    }

    public function test_create_with_members_stores_usernames_in_detail(): void
    {
        $a = $this->createMember($this->agent, ['username' => 'tga' . bin2hex(random_bytes(4))]);
        $b = $this->createMember($this->agent, ['username' => 'tgb' . bin2hex(random_bytes(4))]);

        $this->create(['target_type' => MemberRestrictionTargetEnum::MEMBER->value, 'target_ids' => [$a->id, $b->id, $a->id]])
            ->assertOk()->assertJsonPath('code', 200);
        $detail = $this->latestDetail();

        $this->assertSame(2, EventV2DetailTarget::query()->where('event_v2_detail_id', $detail->id)->count(), '重複 id 只存一次');
        $res = $this->postJson(self::DETAIL, ['event_detail_id' => $detail->id], $this->headers)->assertOk();
        $this->assertSame(MemberRestrictionTargetEnum::MEMBER->value, $res->json('data.target_type'));
        $this->assertEqualsCanonicalizing([$a->username, $b->username], array_column($res->json('data.targets'), 'name'));
    }

    public function test_create_without_target_is_unrestricted(): void
    {
        $this->create()->assertOk()->assertJsonPath('code', 200);
        $detail = $this->latestDetail();

        $this->assertSame(0, EventV2DetailTarget::query()->where('event_v2_detail_id', $detail->id)->count());
        $res = $this->postJson(self::DETAIL, ['event_detail_id' => $detail->id], $this->headers)->assertOk();
        $this->assertSame(0, $res->json('data.target_type'));
        $this->assertSame([], $res->json('data.targets'));
    }

    public function test_create_rejects_type_without_ids_ids_without_type_and_unknown_ids(): void
    {
        $this->create(['target_type' => MemberRestrictionTargetEnum::MEMBER_GROUP->value, 'target_ids' => []])
            ->assertStatus(400)->assertJsonPath('message', __('event_v2.validation.target_ids_required'));

        $this->create(['target_type' => 0, 'target_ids' => [$this->group('孤兒')->id]])
            ->assertStatus(400)->assertJsonPath('message', __('event_v2.validation.target_type_required'));

        $this->create(['target_type' => MemberRestrictionTargetEnum::MEMBER_GROUP->value, 'target_ids' => [99999999]])
            ->assertStatus(400)->assertJsonPath('message', __('event_v2.validation.target_ids_invalid'));
    }

    public function test_targets_must_be_inside_event_agent_line(): void
    {
        // 另一條線（不同總代）的群組與會員：選了活動也看不到，存檔擋下
        $otherTop = $this->anotherAgent();
        $foreignGroup = MemberGroup::query()->create(['agent_id' => $otherTop->id, 'name' => 'foreign' . bin2hex(random_bytes(2))]);
        $foreignMember = $this->createMember($otherTop, ['username' => 'tgf' . bin2hex(random_bytes(4))]);

        $this->create(['target_type' => MemberRestrictionTargetEnum::MEMBER_GROUP->value, 'target_ids' => [$foreignGroup->id]])
            ->assertStatus(400)->assertJsonPath('message', __('event_v2.validation.target_ids_invalid'));
        $this->create(['target_type' => MemberRestrictionTargetEnum::MEMBER->value, 'target_ids' => [$foreignMember->id]])
            ->assertStatus(400)->assertJsonPath('message', __('event_v2.validation.target_ids_invalid'));
    }

    public function test_line_scope_allows_ancestor_groups_and_downline_members_only(): void
    {
        // 活動掛在下線代理 child：上層（$this->agent）建的群組可套用到 child 底下的會員 → 可選；
        // child 底下的會員可選；只掛在上層、不在 child 底下的會員不可選
        $child = $this->childAgent($this->agent);
        $ancestorGroup = $this->group('上層群組');
        $downlineMember = $this->createMember($child, ['username' => 'tgd' . bin2hex(random_bytes(4))]);
        $upperOnlyMember = $this->createMember($this->agent, ['username' => 'tgu' . bin2hex(random_bytes(4))]);

        $this->create(['agent_id' => $child->id, 'target_type' => MemberRestrictionTargetEnum::MEMBER_GROUP->value, 'target_ids' => [$ancestorGroup->id]])
            ->assertOk()->assertJsonPath('code', 200);
        $this->create(['agent_id' => $child->id, 'target_type' => MemberRestrictionTargetEnum::MEMBER->value, 'target_ids' => [$downlineMember->id]])
            ->assertOk()->assertJsonPath('code', 200);
        $this->create(['agent_id' => $child->id, 'target_type' => MemberRestrictionTargetEnum::MEMBER->value, 'target_ids' => [$upperOnlyMember->id]])
            ->assertStatus(400)->assertJsonPath('message', __('event_v2.validation.target_ids_invalid'));
    }

    public function test_target_options_lists_only_event_agent_line(): void
    {
        $mine = $this->group('本線');
        $otherTop = $this->anotherAgent();
        MemberGroup::query()->create(['agent_id' => $otherTop->id, 'name' => 'foreign' . bin2hex(random_bytes(2))]);
        $prefix = 'tgo' . bin2hex(random_bytes(3));
        $inLine = $this->createMember($this->agent, ['username' => $prefix . 'a']);
        $this->createMember($otherTop, ['username' => $prefix . 'b']);

        $groups = $this->postJson('agent/api/event-v2/target-options', [
            'agent_id' => $this->agent->id,
            'target_type' => MemberRestrictionTargetEnum::MEMBER_GROUP->value,
        ], $this->headers)->assertOk()->json('data');
        $this->assertContains($mine->id, array_column($groups, 'id'));
        $this->assertSame([], array_values(array_filter($groups, fn ($g) => str_starts_with($g['name'], 'foreign'))), '別條線的群組不列');

        $members = $this->postJson('agent/api/event-v2/target-options', [
            'agent_id' => $this->agent->id,
            'target_type' => MemberRestrictionTargetEnum::MEMBER->value,
            'keyword' => $prefix,
        ], $this->headers)->assertOk()->json('data');
        $this->assertSame([['id' => $inLine->id, 'name' => $inLine->username]], $members, '會員只列本線、前綴比對');

        $empty = $this->postJson('agent/api/event-v2/target-options', [
            'agent_id' => $this->agent->id,
            'target_type' => MemberRestrictionTargetEnum::MEMBER->value,
        ], $this->headers)->assertOk()->json('data');
        $this->assertSame([], $empty, '沒有關鍵字不查會員');
    }

    public function test_update_keeps_targets_when_not_sent_replaces_when_sent_clears_with_zero(): void
    {
        $vip = $this->group('VIP');
        $gold = $this->group('黃金');
        $this->create(['target_type' => MemberRestrictionTargetEnum::MEMBER_GROUP->value, 'target_ids' => [$vip->id]])->assertOk();
        $detail = $this->latestDetail();
        $ids = fn () => EventV2DetailTarget::query()->where('event_v2_detail_id', $detail->id)->pluck('target_id')->all();

        // 舊後台整包送但沒有 target_type：不動
        $this->update($detail, [])->assertOk()->assertJsonPath('code', 200);
        $this->assertSame([$vip->id], $ids());

        // 送了就整組重寫
        $this->update($detail, ['target_type' => MemberRestrictionTargetEnum::MEMBER_GROUP->value, 'target_ids' => [$gold->id]])->assertOk();
        $this->assertSame([$gold->id], $ids());

        // 送 0＝清成不限制
        $this->update($detail, ['target_type' => 0, 'target_ids' => []])->assertOk();
        $this->assertSame([], $ids());
    }

    // -------------------------------------------------------
    // 參加判斷（AbstractEventV2::validate，所有類型共用）
    // -------------------------------------------------------

    public function test_validate_no_targets_is_unrestricted(): void
    {
        $detail = $this->detail();
        $member = $this->createMember($this->agent, ['username' => 'tgn' . bin2hex(random_bytes(4))]);

        $this->assertNotSame(EventV2ValidateCodeEnum::TARGET_NOT_MATCHED, $this->validateCode($detail, $member));
    }

    public function test_validate_member_group_target_follows_current_group(): void
    {
        $vip = $this->group('VIP');
        $normal = $this->group('一般');
        $detail = $this->detail();
        $this->target($detail, MemberRestrictionTargetEnum::MEMBER_GROUP, $vip->id);

        $member = $this->createMember($this->agent, ['username' => 'tgg' . bin2hex(random_bytes(4)), 'member_group_id' => $normal->id]);
        $this->assertSame(EventV2ValidateCodeEnum::TARGET_NOT_MATCHED, $this->validateCode($detail, $member), '不在 VIP 群組：擋');

        // 群組調整即時生效
        $member->update(['member_group_id' => $vip->id]);
        $this->assertNotSame(EventV2ValidateCodeEnum::TARGET_NOT_MATCHED, $this->validateCode($detail, $member->fresh()), '調進 VIP：可參加');

        $noGroup = $this->createMember($this->agent, ['username' => 'tgz' . bin2hex(random_bytes(4))]);
        $this->assertSame(EventV2ValidateCodeEnum::TARGET_NOT_MATCHED, $this->validateCode($detail, $noGroup), '沒有群組：擋');
    }

    public function test_validate_member_target_only_listed_members(): void
    {
        $detail = $this->detail();
        $listed = $this->createMember($this->agent, ['username' => 'tgl' . bin2hex(random_bytes(4))]);
        $other = $this->createMember($this->agent, ['username' => 'tgo' . bin2hex(random_bytes(4))]);
        $this->target($detail, MemberRestrictionTargetEnum::MEMBER, $listed->id);

        $this->assertNotSame(EventV2ValidateCodeEnum::TARGET_NOT_MATCHED, $this->validateCode($detail, $listed));
        $this->assertSame(EventV2ValidateCodeEnum::TARGET_NOT_MATCHED, $this->validateCode($detail, $other));
    }

    // -------------------------------------------------------

    /** 另建一個代理：createAgentAndDomain 用 $this->domain 建網域（唯一鍵），先換一個、建完換回來 */
    private function anotherAgent(): Agent
    {
        $originalDomain = $this->domain;
        $this->domain = bin2hex(random_bytes(8)) . '.test.local';
        $agent = $this->createAgentAndDomain();
        $this->domain = $originalDomain;

        return $agent;
    }

    /** 在 $parent 底下建一個下線代理（hierarchy_path 接在上層後面） */
    private function childAgent(Agent $parent): Agent
    {
        $child = $this->anotherAgent();
        $child->update([
            'hierarchy_path' => $parent->hierarchy_path . '/' . $child->id,
            'hierarchy' => "[-1,{$parent->id},{$child->id},-2]",
        ]);

        return $child->fresh();
    }

    private function group(string $name): MemberGroup
    {
        return MemberGroup::query()->create(['agent_id' => $this->agent->id, 'name' => $name . bin2hex(random_bytes(2))]);
    }

    private function detail(): EventV2Detail
    {
        return EventV2Detail::factory()->create([
            'event_v2_id' => $this->typeId,
            'agent_id' => $this->agent->id,
            'reward_formula' => '10',
            'join_withdraw' => 1,
            'cost_type' => EventV2DetailCostTypeEnum::COMPLETE->value,
        ]);
    }

    private function target(EventV2Detail $detail, MemberRestrictionTargetEnum $type, int $id): void
    {
        EventV2DetailTarget::query()->insert([
            'event_v2_detail_id' => $detail->id,
            'target_type' => $type->morphAlias(),
            'target_id' => $id,
        ]);
    }

    private function validateCode(EventV2Detail $detail, Member $member): ?EventV2ValidateCodeEnum
    {
        $result = app(DailyFreeService::class)->validate($detail->id, $member);

        return $result->isFailed() ? $result->code : null;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function create(array $overrides = []): TestResponse
    {
        return $this->postJson(self::CREATE, $this->payload($overrides), $this->headers);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function update(EventV2Detail $detail, array $overrides): TestResponse
    {
        return $this->postJson(self::UPDATE, $this->payload(array_merge(['event_detail_id' => $detail->id], $overrides)), $this->headers);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides): array
    {
        return array_merge([
            'event_v2_id' => $this->typeId,
            'agent_id' => $this->agent->id,
            'condition_formula' => null,
            'condition_config' => null,
            'reward_formula' => '10',
            'reward_config' => null,
            'balance_formula' => null,
            'balance_config' => null,
            'flow_formula' => null,
            'flow_config' => null,
            'withdraw_formula' => null,
            'withdraw_config' => null,
            'bonus_vendor_id' => null,
            'ip_block' => null,
            'phone_block' => null,
            'account_block' => null,
            'join_withdraw' => 1,
            'cost_type' => EventV2DetailCostTypeEnum::COMPLETE->value,
            'start_time' => now()->subDay()->format('Y-m-d H:i:s'),
            'end_time' => now()->addMonth()->format('Y-m-d H:i:s'),
            'is_recycle' => 1,
            'status' => 1,
            'detail_lang' => [],
            'exclude_agent' => [],
            'restrict_game_type' => [],
            'restrict_vendor' => [],
            'exclude_vendor_data' => [],
        ], $overrides);
    }

    private function latestDetail(): EventV2Detail
    {
        return EventV2Detail::query()->where('agent_id', $this->agent->id)->latest('id')->firstOrFail();
    }
}
