<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Exceptions\ApiException;
use App\Models\Agent;
use App\Models\MemberGroup;
use App\Services\MemberGroupService;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

/**
 * MemberGroupService::defaultGroupIdForAgent 測試（會員註冊時自動帶入該代理所屬線「最近」的預設群組）。
 *
 * php artisan test tests/Unit/Services/MemberGroupServiceTest.php
 */
class MemberGroupServiceTest extends TestCase
{
    private function service(): MemberGroupService
    {
        return app(MemberGroupService::class);
    }

    private function createGroup(int $agentId, array $overrides = []): MemberGroup
    {
        return MemberGroup::create(array_merge([
            'agent_id' => $agentId,
            'name' => 'grp-' . uniqid('', true),
            'status' => 1,
            'default_setting' => 0,
        ], $overrides));
    }

    /**
     * 建代理並設好 hierarchy_path（可掛在某父代理下形成階層）；用 factory 不建 domain 避免唯一鍵衝突。
     */
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

    #[TestDox('代理有啟用中的預設群組時回傳該群組 id')]
    public function test_returns_enabled_default_group_id(): void
    {
        $agent = $this->createAgentAndDomain();
        $default = $this->createGroup($agent->id, ['default_setting' => 1]);
        $this->createGroup($agent->id); // 非預設群組

        $this->assertSame($default->id, $this->service()->defaultGroupIdForAgent($agent));
    }

    #[TestDox('代理無預設群組時回傳 null')]
    public function test_returns_null_without_default_group(): void
    {
        $agent = $this->createAgentAndDomain();
        $this->createGroup($agent->id); // 只有非預設群組

        $this->assertNull($this->service()->defaultGroupIdForAgent($agent));
    }

    #[TestDox('預設群組已停用時回傳 null')]
    public function test_returns_null_when_default_group_disabled(): void
    {
        $agent = $this->createAgentAndDomain();
        $this->createGroup($agent->id, ['default_setting' => 1, 'status' => 0]);

        $this->assertNull($this->service()->defaultGroupIdForAgent($agent));
    }

    #[TestDox('不會取到線外(非本人/上線)代理的預設群組')]
    public function test_ignores_other_agent_default_group(): void
    {
        $agent = $this->createAgentAndDomain();
        $this->createGroup($agent->id + 999_999, ['default_setting' => 1]); // 線外代理擁有

        $this->assertNull($this->service()->defaultGroupIdForAgent($agent));
    }

    #[TestDox('沿上線往上取最近預設群組（上級 A 有預設、中間 B/C 無 → B、C 底下都取 A）')]
    public function test_default_group_walks_up_to_nearest_ancestor(): void
    {
        $agentA = $this->makeAgent();
        $agentB = $this->makeAgent($agentA);
        $agentC = $this->makeAgent($agentB);
        $aDefault = $this->createGroup($agentA->id, ['default_setting' => 1]);

        $this->assertSame($aDefault->id, $this->service()->defaultGroupIdForAgent($agentC));
        $this->assertSame($aDefault->id, $this->service()->defaultGroupIdForAgent($agentB));
    }

    #[TestDox('較近上級的預設優先於較遠（B 有預設時 C 取 B 而非 A）')]
    public function test_default_group_nearer_ancestor_wins(): void
    {
        $agentA = $this->makeAgent();
        $agentB = $this->makeAgent($agentA);
        $agentC = $this->makeAgent($agentB);
        $this->createGroup($agentA->id, ['default_setting' => 1]);
        $bDefault = $this->createGroup($agentB->id, ['default_setting' => 1]);

        $this->assertSame($bDefault->id, $this->service()->defaultGroupIdForAgent($agentC));
    }

    // ==================== dropdown（列表篩選 / 會員指派）====================

    #[TestDox('dropdown 不帶 memberId：admin 可見別條線的群組（列表篩選用，比照群組列表頁）')]
    public function test_dropdown_admin_sees_all_groups(): void
    {
        // isAdmin() 判定為 id==1 || host_id==1；測試庫不保證有 agent 1，改以 host_id 造 admin
        $admin = $this->makeAgent();
        $admin->host_id = 1;
        $admin->save();

        $other = $this->makeAgent();               // 與 admin 不同線的代理
        $group = $this->createGroup($other->id);   // 群組掛在該代理下

        $ids = $this->service()->dropdown($admin->refresh())->pluck('id')->all();

        $this->assertContains($group->id, $ids);
    }

    #[TestDox('dropdown 不帶 memberId：非 admin 看不到別條線的群組')]
    public function test_dropdown_non_admin_scoped_to_own_line(): void
    {
        $mine = $this->makeAgent();
        $other = $this->makeAgent();
        $myGroup = $this->createGroup($mine->id);
        $otherGroup = $this->createGroup($other->id);

        $ids = $this->service()->dropdown($mine)->pluck('id')->all();

        $this->assertContains($myGroup->id, $ids);
        $this->assertNotContains($otherGroup->id, $ids);
    }

    #[TestDox('dropdown 只回啟用中的群組')]
    public function test_dropdown_excludes_disabled_groups(): void
    {
        $agent = $this->makeAgent();
        $enabled = $this->createGroup($agent->id);
        $disabled = $this->createGroup($agent->id, ['status' => 0]);

        $ids = $this->service()->dropdown($agent)->pluck('id')->all();

        $this->assertContains($enabled->id, $ids);
        $this->assertNotContains($disabled->id, $ids);
    }

    // ==================== 可見性軸：自己＋下線（不含上級）====================

    /** 本次 makeChain 產生的群組名稱共同前綴，供分頁測試以 keyword 過濾掉其他測試殘留的資料 */
    private string $chainMarker = '';

    /**
     * 建三層鏈 A → B → C，並各自建一個群組（名稱帶共同 marker）。
     *
     * @return array{0: Agent, 1: Agent, 2: Agent, 3: MemberGroup, 4: MemberGroup, 5: MemberGroup}
     */
    private function makeChain(): array
    {
        $a = $this->makeAgent();
        $b = $this->makeAgent($a);
        $c = $this->makeAgent($b);

        $this->chainMarker = 'chain-' . uniqid('', true);

        return [
            $a, $b, $c,
            $this->createGroup($a->id, ['name' => $this->chainMarker . '-A']),
            $this->createGroup($b->id, ['name' => $this->chainMarker . '-B']),
            $this->createGroup($c->id, ['name' => $this->chainMarker . '-C']),
        ];
    }

    #[TestDox('可見性：B 看得到自己(B)與下線(C)的群組，看不到上級(A)的')]
    public function test_visibility_self_and_descendants_only(): void
    {
        [, $b, , $ga, $gb, $gc] = $this->makeChain();

        $ids = $this->service()->dropdown($b)->pluck('id')->all();

        $this->assertContains($gb->id, $ids, 'B 應看得到自己的群組');
        $this->assertContains($gc->id, $ids, 'B 應看得到下線 C 的群組');
        $this->assertNotContains($ga->id, $ids, 'B 不該看到上級 A 的群組');
    }

    #[TestDox('列表：B 的分頁結果只含自己與下線的群組')]
    public function test_paginate_excludes_ancestor_groups(): void
    {
        [, $b, , $ga, $gb, $gc] = $this->makeChain();

        // 以 keyword 過濾，避免其他測試殘留的群組把本次資料擠出分頁
        $ids = collect($this->service()->paginateForAgent($b, ['per_page' => 50, 'keyword' => $this->chainMarker])->items())
            ->pluck('id')
            ->all();

        $this->assertContains($gb->id, $ids);
        $this->assertContains($gc->id, $ids);
        $this->assertNotContains($ga->id, $ids);
    }

    #[TestDox('update 守衛：B 可取下線(C)的群組，取上級(A)的群組拋例外')]
    public function test_find_owned_allows_descendants_blocks_ancestors(): void
    {
        [, $b, , $ga, , $gc] = $this->makeChain();

        $this->assertSame($gc->id, $this->service()->findOwnedForAgent($b, $gc->id)->id);

        $this->expectException(ApiException::class);
        $this->service()->findOwnedForAgent($b, $ga->id);
    }

    #[TestDox('檢視：C 取上級(A)的群組拋 1010')]
    public function test_find_visible_blocks_ancestor_group(): void
    {
        [, , $c, $ga] = $this->makeChain();

        $this->expectException(ApiException::class);
        $this->service()->findVisibleForAgent($c, $ga->id);
    }

    #[TestDox('適用性軸不跟著翻：C 底下註冊的會員仍自動帶入上級(A)的預設群組')]
    public function test_applicability_still_walks_up_after_visibility_flip(): void
    {
        $a = $this->makeAgent();
        $b = $this->makeAgent($a);
        $c = $this->makeAgent($b);
        $aDefault = $this->createGroup($a->id, ['default_setting' => 1]);

        $this->assertNotContains($aDefault->id, $this->service()->dropdown($c)->pluck('id')->all());
        $this->assertSame($aDefault->id, $this->service()->defaultGroupIdForAgent($c));
    }

    #[TestDox('前綴碰撞防護：/1/23 與 /1/234 互不可見')]
    public function test_visibility_no_prefix_collision(): void
    {
        $a = $this->makeAgent();
        $b = $this->makeAgent();
        // 刻意造出前綴相同但不同線的兩條路徑
        $a->hierarchy_path = '/1/23';
        $a->save();
        $b->hierarchy_path = '/1/234';
        $b->save();

        $groupB = $this->createGroup($b->id);

        $ids = $this->service()->dropdown($a->refresh())->pluck('id')->all();

        $this->assertNotContains($groupB->id, $ids, '/1/234 不該被 /1/23 當成下線');
    }

    #[TestDox('篩選來源：可見群組的擁有者都落在自己＋下線內，故沿用既有代理下拉即可')]
    public function test_visible_owners_within_self_and_descendants(): void
    {
        [$a, $b, $c] = $this->makeChain();

        // 鎖住「篩選下拉可沿用 API_Agent /api/agent/dropdownList（自己＋下線）」的前提
        $owners = collect($this->service()->paginateForAgent($b, ['per_page' => 50, 'keyword' => $this->chainMarker])->items())
            ->pluck('agent_id')
            ->unique()
            ->all();

        $this->assertContains($b->id, $owners);
        $this->assertContains($c->id, $owners);
        $this->assertNotContains($a->id, $owners, '上級不該出現在可見群組的擁有者中');
    }

    #[TestDox('hierarchy_path 為空時 fail-closed：只看得到自己的群組')]
    public function test_visibility_fail_closed_on_empty_path(): void
    {
        $orphan = $this->makeAgent();
        $orphan->hierarchy_path = '';
        $orphan->save();
        $own = $this->createGroup($orphan->id);

        $other = $this->makeAgent();
        $otherGroup = $this->createGroup($other->id);

        $ids = $this->service()->dropdown($orphan->refresh())->pluck('id')->all();

        $this->assertContains($own->id, $ids, '仍應看得到自己的群組');
        $this->assertNotContains($otherGroup->id, $ids, '不可因空 path 變成看到全站');
    }

    // ==================== 平台手續費階梯（withdraw_fee_tiers）target/tickets#4353 ====================

    #[TestDox('create 帶階梯：亂序輸入依 from 升冪存回、轉型後往返一致')]
    public function test_create_with_fee_tiers_round_trip(): void
    {
        $agent = $this->makeAgent();

        $group = $this->service()->create($agent, [
            'name' => 'fee-' . uniqid('', true),
            'status' => 1,
            'default_setting' => 0,
            'withdraw_fee_tiers' => [
                ['from' => '11', 'type' => '0', 'value' => 50],
                ['from' => 4, 'type' => 1, 'value' => '2.5'],
            ],
        ], null);

        $expected = [
            ['from' => 4, 'type' => 1, 'value' => '2.50000'],
            ['from' => 11, 'type' => 0, 'value' => '50.00000'],
        ];
        $fresh = MemberGroup::find($group->id);
        $this->assertSame($expected, $fresh->withdrawFeeTiers());
        $this->assertTrue($fresh->hasWithdrawFeeTiers());
    }

    #[TestDox('create 帶空陣列階梯：存成 null（未啟用）')]
    public function test_create_with_empty_fee_tiers_stores_null(): void
    {
        $agent = $this->makeAgent();

        $group = $this->service()->create($agent, [
            'name' => 'fee-' . uniqid('', true),
            'status' => 1,
            'default_setting' => 0,
            'withdraw_fee_tiers' => [],
        ], null);

        $fresh = MemberGroup::find($group->id);
        $this->assertNull($fresh->getRawOriginal('withdraw_fee_tiers'));
        $this->assertSame([], $fresh->withdrawFeeTiers());
        $this->assertFalse($fresh->hasWithdrawFeeTiers());
    }

    #[TestDox('update 帶階梯往返一致；再送空陣列即清成 null')]
    public function test_update_fee_tiers_then_clear(): void
    {
        $agent = $this->makeAgent();
        $group = $this->createGroup($agent->id);

        $this->service()->update($agent, $group, [
            'withdraw_fee_tiers' => [['from' => 1, 'type' => 0, 'value' => 10]],
        ], null);
        $this->assertSame(
            [['from' => 1, 'type' => 0, 'value' => '10.00000']],
            MemberGroup::find($group->id)->withdrawFeeTiers(),
        );

        $this->service()->update($agent, MemberGroup::find($group->id), ['withdraw_fee_tiers' => []], null);
        $this->assertNull(MemberGroup::find($group->id)->getRawOriginal('withdraw_fee_tiers'));
    }

    #[TestDox('update 未帶 withdraw_fee_tiers 欄位：不動既有階梯')]
    public function test_update_without_fee_tiers_key_keeps_existing(): void
    {
        $agent = $this->makeAgent();
        $group = $this->createGroup($agent->id, [
            'withdraw_fee_tiers' => [['from' => 3, 'type' => 1, 'value' => '5']],
        ]);

        $this->service()->update($agent, $group, ['remark' => 'x'], null);

        $this->assertSame(
            [['from' => 3, 'type' => 1, 'value' => '5.00000']],
            MemberGroup::find($group->id)->withdrawFeeTiers(),
        );
    }

    #[TestDox('create 帶階梯：value 存進 DB 即為 5 位正規化字串（JSON float／整數皆同），bcmath 可直接讀')]
    public function test_create_stores_fee_tier_value_as_normalized_five_decimals(): void
    {
        $agent = $this->makeAgent();

        $group = $this->service()->create($agent, [
            'name' => 'fee-' . uniqid('', true),
            'status' => 1,
            'default_setting' => 0,
            'withdraw_fee_tiers' => [
                ['from' => 1, 'type' => 0, 'value' => 3],
                ['from' => 4, 'type' => 1, 'value' => 0.00001],
            ],
        ], null);

        $raw = json_decode((string) MemberGroup::find($group->id)->getRawOriginal('withdraw_fee_tiers'), true);
        $this->assertSame(['3.00000', '0.00001'], array_column($raw, 'value'));
    }
}
