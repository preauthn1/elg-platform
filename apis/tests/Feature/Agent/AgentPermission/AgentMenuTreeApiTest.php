<?php

declare(strict_types=1);

namespace Tests\Feature\Agent\AgentPermission;

use App\Enums\Agent\RoleEnum;
use App\Models\Agent;
use App\Models\AgentGroup;
use App\Models\AgentGroupPermission;
use App\Models\AgentPermission;
use App\Services\Agent\AgentPermissionScopeService;
use Tests\Feature\Agent\AgentPermission\Concerns\BuildsAgentPermissionFixtures;
use Tests\TestCase;

/**
 * php artisan test tests/Feature/Agent/AgentPermission/AgentMenuTreeApiTest.php
 *
 * 選單樹、權限地圖、組別管理權（target/tickets#4422 自 API_Agent 搬入）：輸出結構與 API_Agent 相同；
 * 選單樹不快取、每次驗範疇（API_Agent 快取命中時不驗權）；組別管理權讀取也驗範疇。
 * 銀行卡管理兩個節點只對不能使用會員銀行卡個資的角色、在「看自己」時濾掉（target/tickets#4486）。
 */
class AgentMenuTreeApiTest extends TestCase
{
    use BuildsAgentPermissionFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootAgentApi();
    }

    public function test_menu_tree_merges_personal_and_role_and_nests_children(): void
    {
        $top = $this->topAgent();
        $root = $this->menu('t_root', ['sort' => 1]);
        $second = $this->menu('t_second', ['parent' => $root->id, 'url' => 'x/index', 'sort' => 1]);
        $page = $this->menu('t_page', ['parent' => $second->id, 'url' => 'x/page', 'sort' => 1]);
        $button = $this->menu('t_button', ['parent' => $second->id, 'sort' => -999]);
        AgentPermission::query()->create(['agent_id' => $top->id, 'agent_menu_id' => $root->id, 'action' => 'view', 'admin' => 0]);
        AgentPermission::query()->create(['agent_id' => $top->id, 'agent_menu_id' => $second->id, 'action' => 'view', 'admin' => 0]);
        $role = $this->role('角色', $top->id, [$second->id, $page->id, $button->id]);
        $role->menus()->where('agent_menu_id', $second->id)->update(['action' => 'view,update']);
        $this->bindRole($top, $role);
        $this->actAs($top);

        $tree = $this->callApi('agent-permission/menu-tree', ['agent_id' => $top->id])->assertJsonPath('code', 200)->json('data');
        $node = collect($tree)->firstWhere('id', $root->id);

        $this->assertNotNull($node);
        $secondNode = collect($node['submenu'])->firstWhere('id', $second->id);
        $this->assertSame('view,update', $secondNode['action'], '個人與角色的 action 取聯集');
        $this->assertTrue($secondNode['is_any_url_submenu']);
        $this->assertEqualsCanonicalizing([$page->id, $button->id], array_column($secondNode['submenu'], 'id'));
        $this->assertFalse(collect($secondNode['submenu'])->firstWhere('id', $button->id)['is_url_menu']);
    }

    public function test_menu_tree_rejects_agent_outside_scope_every_time(): void
    {
        $top = $this->topAgent();
        $this->actAs($top);

        $this->callApi('agent-permission/menu-tree', ['agent_id' => $this->topAgent()->id])
            ->assertJsonPath('message', __('error_message.9003'));
        $this->callApi('agent-permission/menu-tree', ['agent_id' => $this->downline($top)->id])
            ->assertJsonPath('code', 200);
    }

    public function test_code_map_returns_effective_codes(): void
    {
        $top = $this->topAgent();
        $this->grant($top, 'code_a');
        $this->bindRole($top, $this->role('角色', null, [$this->menu('code_b')->id]));
        $this->actAs($top);

        $map = $this->callApi('agent-permission/code-map')->assertJsonPath('code', 200)->json('data');
        $this->assertTrue($map['code_a']);
        $this->assertTrue($map['code_b']);
    }

    public function test_group_permissions_view_requires_scope_and_update_respects_operator_groups(): void
    {
        $top = $this->topAgent();
        $this->grant($top, 'agent', 'update_agent_group_permissions');
        $target = $this->downline($top);
        $mine = AgentGroup::query()->create(['agent_group_type' => 1, 'organization_id' => $top->organization_id, 'name' => 'g1', 'memo' => '', 'is_default' => 0]);
        $notMine = AgentGroup::query()->create(['agent_group_type' => 2, 'organization_id' => $top->organization_id, 'name' => 'g2', 'memo' => '', 'is_default' => 0]);
        AgentGroupPermission::query()->create(['agent_id' => $top->id, 'agent_group_id' => $mine->id, 'status' => 1, 'admin' => 0]);
        $this->actAs($top);

        $this->callApi('agent-permission/group-permissions', ['agent_id' => $this->topAgent()->id])
            ->assertJsonPath('message', __('error_message.9003'));

        $view = $this->callApi('agent-permission/group-permissions', ['agent_id' => $target->id])->assertJsonPath('code', 200)->json('data');
        $this->assertSame([$mine->id], array_column($view['groups'], 'group_id'), '非 super admin 只看自己有權的組別');

        $this->callApi('agent-permission/group-permissions/update', ['agent_id' => $target->id, 'group_permission' => [$notMine->id]])
            ->assertJsonPath('message', __('error_message.9003'));
        $this->callApi('agent-permission/group-permissions/update', ['agent_id' => $target->id, 'group_permission' => [$mine->id]])
            ->assertJsonPath('code', 200);
        $this->assertSame(1, (int) AgentGroupPermission::query()->where('agent_id', $target->id)->where('agent_group_id', $mine->id)->value('status'));
    }

    public function test_member_bank_account_menu_hidden_from_own_tree_and_code_map_for_agent_role(): void
    {
        $agent = $this->downline($this->topAgent());
        $this->grantMemberBankAccountMenus($agent);
        $this->actAs($agent);

        $this->assertMemberBankAccountMenusVisible($agent, false);
    }

    public function test_member_bank_account_menu_visible_for_general_agent(): void
    {
        $top = $this->topAgent();
        $this->grantMemberBankAccountMenus($top);
        $this->actAs($top);

        $this->assertMemberBankAccountMenusVisible($top, true);
    }

    public function test_member_bank_account_menu_visible_for_customer_service(): void
    {
        $cs = $this->bareAgent(['role_id' => RoleEnum::CUSTOMER_SERVICE->value]);
        $this->grantMemberBankAccountMenus($cs);
        $this->actAs($cs);

        $this->assertMemberBankAccountMenusVisible($cs, true);
    }

    public function test_member_bank_account_menu_visible_for_customer_service_sub_account_of_general_agent(): void
    {
        $cs = $this->subAccount($this->topAgent(), RoleEnum::CUSTOMER_SERVICE);
        $this->grantMemberBankAccountMenus($cs);
        $this->actAs($cs);

        $this->assertMemberBankAccountMenusVisible($cs, true);
    }

    public function test_member_bank_account_menu_visible_for_admin_sub_account(): void
    {
        $admin = $this->adminSubAccount();
        $this->grantMemberBankAccountMenus($admin);
        $this->actAs($admin);

        $this->assertMemberBankAccountMenusVisible($admin, true);
    }

    public function test_member_bank_account_menu_kept_when_viewing_other_agent_tree(): void
    {
        $top = $this->topAgent();
        $target = $this->downline($top);
        $this->grantMemberBankAccountMenus($target);
        $this->actAs($top);

        $tree = $this->callApi('agent-permission/menu-tree', ['agent_id' => $target->id])->assertJsonPath('code', 200)->json('data');
        $codes = $this->treeCodes($tree);

        $this->assertContains('member_bank_account', $codes, '查他人選單樹照實回傳，避免權限設定頁存檔時刪掉目標既有權限');
        $this->assertContains('member_bank_account_edit_permission', $codes);
    }

    public function test_can_access_member_bank_account_follows_host_role(): void
    {
        $scope = app(AgentPermissionScopeService::class);
        $top = $this->topAgent();

        $this->assertTrue($scope->canAccessMemberBankAccount($top));
        $this->assertTrue($scope->canAccessMemberBankAccount($this->bareAgent(['role_id' => RoleEnum::ADMIN->value])));
        $this->assertTrue($scope->canAccessMemberBankAccount($this->bareAgent(['role_id' => RoleEnum::CUSTOMER_SERVICE->value])));
        $this->assertTrue($scope->canAccessMemberBankAccount($this->adminSubAccount()));
        $this->assertTrue($scope->canAccessMemberBankAccount($this->subAccount($top, RoleEnum::AGENT)), '子帳號看主帳號角色');

        $this->assertFalse($scope->canAccessMemberBankAccount($this->bareAgent(['role_id' => RoleEnum::FINANCE->value])));
        $this->assertFalse($scope->canAccessMemberBankAccount($this->bareAgent(['role_id' => RoleEnum::SPECIAL_ACCOUNT->value])));
        $this->assertFalse($scope->canAccessMemberBankAccount($this->downline($top)));
        $this->assertFalse(
            $scope->canAccessMemberBankAccount($this->subAccount($this->downline($top), RoleEnum::CUSTOMER_SERVICE)),
            '主帳號是一般代理時，子帳號自己的角色是客服也不允許',
        );
    }

    /** 給代理銀行卡管理兩個節點與一個對照用的一般節點 */
    private function grantMemberBankAccountMenus(Agent $agent): void
    {
        $this->grant($agent, 'member_bank_account', 'member_bank_account_edit_permission', 't_4486_other');
    }

    private function assertMemberBankAccountMenusVisible(Agent $agent, bool $visible): void
    {
        $tree = $this->callApi('agent-permission/menu-tree', ['agent_id' => $agent->id])->assertJsonPath('code', 200)->json('data');
        $codes = $this->treeCodes($tree);
        $map = $this->callApi('agent-permission/code-map')->assertJsonPath('code', 200)->json('data');

        $this->assertContains('t_4486_other', $codes, '其他選單不受影響');
        $this->assertArrayHasKey('t_4486_other', $map);
        foreach (['member_bank_account', 'member_bank_account_edit_permission'] as $code) {
            $this->assertSame($visible, in_array($code, $codes, true), "選單樹 {$code}");
            $this->assertSame($visible, isset($map[$code]), "權限地圖 {$code}");
        }
    }

    /** 某主帳號底下的子帳號（host_id＝主帳號） */
    private function subAccount(Agent $host, RoleEnum $role): Agent
    {
        $agent = $this->bareAgent(['role_id' => $role->value]);
        $agent->forceFill(['host_id' => $host->id])->save();

        return $agent->refresh();
    }

    /**
     * 攤平選單樹的所有 code。
     *
     * @param  array<int, array<string, mixed>>  $tree
     * @return array<int, string>
     */
    private function treeCodes(array $tree): array
    {
        $codes = [];
        foreach ($tree as $node) {
            $codes[] = (string) $node['code'];
            $codes = array_merge($codes, $this->treeCodes($node['submenu'] ?? []));
        }

        return $codes;
    }
}
