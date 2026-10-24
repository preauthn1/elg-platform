<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Exceptions\ApiException;
use App\Models\Agent;
use App\Models\Member;
use App\Models\MemberGroup;
use App\Models\MemberRestrictionRule;
use App\Services\Agent\MemberRestrictionService;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

/**
 * 風控會員限制後台 CRUD（4118）：目標解析、可見性 scope、unique 重複。
 *
 * php artisan test tests/Unit/Services/MemberRestrictionServiceTest.php
 */
class MemberRestrictionServiceTest extends TestCase
{
    private function service(): MemberRestrictionService
    {
        return app(MemberRestrictionService::class);
    }

    /** 建代理並設好 hierarchy_path（可掛在某父代理下形成階層）。 */
    private function makeAgent(?Agent $parent = null): Agent
    {
        $agent = Agent::factory()->create([
            'status' => 1,
            'parent' => $parent?->id ?? 0,
        ]);
        $agent->hierarchy_path = $parent
            ? $parent->hierarchy_path . '/' . $agent->id
            : '/' . $agent->id;
        $agent->save();

        return $agent->refresh();
    }

    /** factory 預設 username 為固定值，需自帶唯一帳號避免撞 members_username_unique */
    private function newMember(Agent $agent, array $data = []): Member
    {
        return $this->createMember($agent, array_merge(['username' => 'mr' . bin2hex(random_bytes(6))], $data));
    }

    private function basePayload(array $overrides = []): array
    {
        return array_merge([
            'remark' => 'test',
            'status' => 1,
            'is_disabled' => 0,
            'withdrawal_locked' => 0,
        ], $overrides);
    }

    #[TestDox('create type=1：以會員帳號解析目標，agent_id 取自 member.parent')]
    public function test_create_member_rule_resolves_target_and_agent(): void
    {
        $agent = $this->makeAgent();
        $member = $this->newMember($agent);

        $rule = $this->service()->create($agent, $this->basePayload([
            'type' => 1,
            'username' => $member->username,
        ]));

        $this->assertSame('member', $rule->target_type);
        $this->assertSame($member->id, (int) $rule->target_id);
        $this->assertSame($agent->id, (int) $rule->agent_id);
        $this->assertSame($agent->id, (int) $rule->admin);
    }

    #[TestDox('create type=1：會員帳號不存在拋 1007')]
    public function test_create_member_rule_rejects_unknown_username(): void
    {
        $agent = $this->makeAgent();

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage(__('error_message.1007'));

        $this->service()->create($agent, $this->basePayload([
            'type' => 1,
            'username' => 'no-such-user-' . uniqid(),
        ]));
    }

    #[TestDox('create type=1：非 admin 不可對線外會員建規則（拋 1007）')]
    public function test_create_member_rule_rejects_out_of_scope_member(): void
    {
        $agent = $this->makeAgent();
        $other = $this->makeAgent(); // 不同線
        $outsideMember = $this->newMember($other);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage(__('error_message.1007'));

        $this->service()->create($agent, $this->basePayload([
            'type' => 1,
            'username' => $outsideMember->username,
        ]));
    }

    #[TestDox('create type=2：群組目標取可見群組，agent_id 取自群組擁有者')]
    public function test_create_group_rule_resolves_target(): void
    {
        $agent = $this->makeAgent();
        $down = $this->makeAgent($agent);
        $group = MemberGroup::create(['agent_id' => $down->id, 'name' => 'g-' . uniqid(), 'status' => 1]);

        $rule = $this->service()->create($agent, $this->basePayload([
            'type' => 2,
            'member_group_id' => $group->id,
        ]));

        $this->assertSame('member_group', $rule->target_type);
        $this->assertSame($group->id, (int) $rule->target_id);
        $this->assertSame($down->id, (int) $rule->agent_id);
    }

    #[TestDox('create type=2：線外群組拋 1010')]
    public function test_create_group_rule_rejects_out_of_scope_group(): void
    {
        $agent = $this->makeAgent();
        $other = $this->makeAgent();
        $group = MemberGroup::create(['agent_id' => $other->id, 'name' => 'g-' . uniqid(), 'status' => 1]);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage(__('error_message.1010'));

        $this->service()->create($agent, $this->basePayload([
            'type' => 2,
            'member_group_id' => $group->id,
        ]));
    }

    #[TestDox('同一目標重複建立拋 member_restriction.duplicate')]
    public function test_create_rejects_duplicate_target(): void
    {
        $agent = $this->makeAgent();
        $member = $this->newMember($agent);
        $payload = $this->basePayload(['type' => 1, 'username' => $member->username]);
        $this->service()->create($agent, $payload);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage(__('error_message.member_restriction.duplicate'));

        $this->service()->create($agent, $payload);
    }

    #[TestDox('可見性：B 看得到自己與下線 C 的規則，看不到上級 A 的')]
    public function test_visibility_self_and_descendants_only(): void
    {
        $a = $this->makeAgent();
        $b = $this->makeAgent($a);
        $c = $this->makeAgent($b);

        $ruleA = $this->service()->create($a, $this->basePayload(['type' => 1, 'username' => $this->newMember($a)->username]));
        $ruleB = $this->service()->create($b, $this->basePayload(['type' => 1, 'username' => $this->newMember($b)->username]));
        $ruleC = $this->service()->create($c, $this->basePayload(['type' => 1, 'username' => $this->newMember($c)->username]));

        $ids = $this->service()->paginateForAgent($b, ['per_page' => 100])->pluck('id')->all();

        $this->assertContains($ruleB->id, $ids, 'B 應看得到自己的規則');
        $this->assertContains($ruleC->id, $ids, 'B 應看得到下線 C 的規則');
        $this->assertNotContains($ruleA->id, $ids, 'B 不該看到上級 A 的規則');
    }

    #[TestDox('list 篩選：username 關鍵字同時比對會員帳號與群組名稱')]
    public function test_list_filters_by_target_name_across_morph(): void
    {
        $agent = $this->makeAgent();
        $marker = 'zz' . substr(uniqid(), -6);
        $member = $this->newMember($agent, ['username' => $marker . 'user']);
        $group = MemberGroup::create(['agent_id' => $agent->id, 'name' => $marker . 'grp', 'status' => 1]);

        $memberRule = $this->service()->create($agent, $this->basePayload(['type' => 1, 'username' => $member->username]));
        $groupRule = $this->service()->create($agent, $this->basePayload(['type' => 2, 'member_group_id' => $group->id]));

        $ids = $this->service()->paginateForAgent($agent, ['per_page' => 100, 'username' => $marker])->pluck('id')->all();

        $this->assertContains($memberRule->id, $ids);
        $this->assertContains($groupRule->id, $ids);
    }

    #[TestDox('update：僅允許改 remark/status/is_disabled/withdrawal_locked，目標不可變')]
    public function test_update_changes_flags_only(): void
    {
        $agent = $this->makeAgent();
        $member = $this->newMember($agent);
        $rule = $this->service()->create($agent, $this->basePayload(['type' => 1, 'username' => $member->username]));

        $updated = $this->service()->update($agent, $rule->id, [
            'remark' => 'changed',
            'status' => 0,
            'is_disabled' => 1,
            'withdrawal_locked' => 1,
        ]);

        $this->assertSame('changed', $updated->remark);
        $this->assertSame(0, (int) $updated->status);
        $this->assertSame(1, (int) $updated->is_disabled);
        $this->assertSame(1, (int) $updated->withdrawal_locked);
        $this->assertSame('member', $updated->target_type);
        $this->assertSame($member->id, (int) $updated->target_id);
    }

    #[TestDox('update/delete：非 admin 碰不到上級的規則（拋 1010）')]
    public function test_update_rejects_out_of_scope_rule(): void
    {
        $a = $this->makeAgent();
        $b = $this->makeAgent($a);
        $ruleA = $this->service()->create($a, $this->basePayload(['type' => 1, 'username' => $this->newMember($a)->username]));

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage(__('error_message.1010'));

        $this->service()->update($b, $ruleA->id, $this->basePayload());
    }

    #[TestDox('delete：刪除自己線內的規則')]
    public function test_delete_removes_rule(): void
    {
        $agent = $this->makeAgent();
        $member = $this->newMember($agent);
        $rule = $this->service()->create($agent, $this->basePayload(['type' => 1, 'username' => $member->username]));

        $this->service()->delete($agent, $rule->id);

        $this->assertNull(MemberRestrictionRule::find($rule->id));
    }
}
