<?php

declare(strict_types=1);

namespace Tests\Feature\Agent\AgentPermission;

use App\Enums\Agent\RoleEnum;
use App\Models\Agent;
use App\Models\AgentMenu;
use App\Models\AgentOperateLog;
use App\Models\AgentPermission;
use App\Models\AgentPermissionGroup;
use Tests\Feature\Agent\AgentPermission\Concerns\BuildsAgentPermissionFixtures;
use Tests\TestCase;

/**
 * php artisan test tests/Feature/Agent/AgentPermission/AgentPermissionSettingApiTest.php
 *
 * 個人權限存檔與子帳號同步（target/tickets#4422 自 API_Agent 搬入）：差集更新、不能授出自己沒有的權限（op2997 豁免）、
 * 範疇限本人或下線；同步只增不刪、補角色時套 4422 規則。
 */
class AgentPermissionSettingApiTest extends TestCase
{
    use BuildsAgentPermissionFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootAgentApi();
    }

    public function test_line_agent_cannot_grant_menu_it_does_not_own(): void
    {
        $top = $this->topAgent();
        $this->grant($top, 'add_agent', 'report_a');
        $foreign = $this->menu('report_secret');
        $target = $this->downline($top);
        $this->actAs($top);

        $this->callApi('agent-permission/setting', [
            'agent_id' => $target->id,
            'menus' => [(string) $foreign->id => ''],
        ])->assertJsonPath('message', __('error_message.9003'));
        $this->assertFalse(AgentPermission::query()->where('agent_id', $target->id)->exists());

        $own = $this->menu('report_a');
        $this->callApi('agent-permission/setting', [
            'agent_id' => $target->id,
            'menus' => [(string) $own->id => 'view'],
        ])->assertJsonPath('code', 200);
        $this->assertSame('view', AgentPermission::query()->where('agent_id', $target->id)->where('agent_menu_id', $own->id)->value('action'));
    }

    public function test_only_deletes_within_operator_grantable_scope(): void
    {
        $top = $this->topAgent();
        $this->grant($top, 'add_agent', 'report_a');
        $target = $this->downline($top);
        // 目標原本就有操作者沒有的權限（例如 super admin 給的），操作者存檔時不得被刪
        $this->grant($target, 'report_a', 'report_secret');
        $this->actAs($top);

        $this->callApi('agent-permission/setting', ['agent_id' => $target->id, 'menus' => []])
            ->assertJsonPath('code', 200);

        $left = AgentPermission::query()->where('agent_id', $target->id)->pluck('agent_menu_id')->all();
        $this->assertSame([$this->menu('report_secret')->id], array_map('intval', $left), '只刪操作者可授範圍內的');
    }

    public function test_target_outside_scope_is_rejected(): void
    {
        $top = $this->topAgent();
        $this->grant($top, 'add_agent');
        $this->actAs($top);

        $this->callApi('agent-permission/setting', ['agent_id' => $this->topAgent()->id, 'menus' => []])
            ->assertJsonPath('message', __('error_message.9003'));
    }

    /**
     * target/tickets#4486：不能使用會員銀行卡個資的操作者（一般代理 21），選單樹看不到銀行卡管理兩項，存檔送來的 menus 不含它們；
     * 目標既有的這兩項必須保留且 action 不變（即使操作者自己的生效權限裡有這兩項，例如 4486 前給的）。
     */
    public function test_operator_without_member_bank_account_access_keeps_target_bank_menus(): void
    {
        [$operator, $target] = $this->lineAgentOperatorAndTarget();
        [$bank, $bankEdit] = $this->memberBankAccountMenus();
        $this->grant($operator, 'member_bank_account', 'member_bank_account_edit_permission');
        $this->grant($target, 'report_a', 'member_bank_account', 'member_bank_account_edit_permission');
        AgentPermission::query()->where('agent_id', $target->id)->where('agent_menu_id', $bank->id)->update(['action' => 'view']);
        $this->actAs($operator);

        $this->callApi('agent-permission/setting', [
            'agent_id' => $target->id,
            'menus' => [(string) $this->menu('report_a')->id => 'view'],
        ])->assertJsonPath('code', 200);

        $actions = AgentPermission::query()->where('agent_id', $target->id)->pluck('action', 'agent_menu_id')->all();
        $this->assertSame('view', $actions[$bank->id] ?? null, '目標既有的銀行卡管理保留、action 不變');
        $this->assertArrayHasKey($bankEdit->id, $actions, '目標既有的銀行卡編輯權限保留');

        // 就算送來了這兩項，也不在操作者的可增刪範圍內，既有 action 不被改寫
        $this->callApi('agent-permission/setting', [
            'agent_id' => $target->id,
            'menus' => [(string) $this->menu('report_a')->id => 'view', (string) $bank->id => 'view,update'],
        ])->assertJsonPath('code', 200);
        $this->assertSame('view', AgentPermission::query()->where('agent_id', $target->id)->where('agent_menu_id', $bank->id)->value('action'));
        $this->assertTrue(AgentPermission::query()->where('agent_id', $target->id)->where('agent_menu_id', $bankEdit->id)->exists());
    }

    /** target/tickets#4486：不能使用會員銀行卡個資的操作者，不得把銀行卡管理授給目標原本沒有的代理 */
    public function test_operator_without_member_bank_account_access_cannot_grant_bank_menus(): void
    {
        [$operator, $target] = $this->lineAgentOperatorAndTarget();
        [$bank, $bankEdit] = $this->memberBankAccountMenus();
        $this->grant($operator, 'member_bank_account', 'member_bank_account_edit_permission');
        $this->actAs($operator);

        $this->callApi('agent-permission/setting', [
            'agent_id' => $target->id,
            'menus' => [(string) $this->menu('report_a')->id => '', (string) $bank->id => '', (string) $bankEdit->id => ''],
        ])->assertJsonPath('message', __('error_message.9003'));
        $this->assertFalse(AgentPermission::query()->where('agent_id', $target->id)->exists());
    }

    /** target/tickets#4486：能使用會員銀行卡個資的操作者（總代 11）行為不變，送出不含這兩項就照常刪除 */
    public function test_general_agent_operator_deletes_bank_menus_as_usual(): void
    {
        $top = $this->topAgent();
        $this->grant($top, 'add_agent', 'report_a', 'member_bank_account', 'member_bank_account_edit_permission');
        $target = $this->downline($top);
        $this->grant($target, 'report_a', 'member_bank_account', 'member_bank_account_edit_permission');
        $this->actAs($top);

        $this->callApi('agent-permission/setting', [
            'agent_id' => $target->id,
            'menus' => [(string) $this->menu('report_a')->id => ''],
        ])->assertJsonPath('code', 200);

        $left = AgentPermission::query()->where('agent_id', $target->id)->pluck('agent_menu_id')->map(fn ($v) => (int) $v)->all();
        $this->assertSame([$this->menu('report_a')->id], $left);
    }

    public function test_sync_children_copies_personal_permissions_and_fills_missing_role(): void
    {
        $top = $this->topAgent();
        $this->grant($top, 'agent_sync_permissiom', 'report_a', 'report_b');
        $role = $this->role('主帳號角色', $top->id);
        $this->bindRole($top, $role);
        $childWithout = $this->downline($top, ['host_id' => $top->id]);
        $childWith = $this->downline($top, ['host_id' => $top->id]);
        $this->grant($childWith, 'report_a');
        $otherRole = $this->role('已有角色', $top->id);
        $this->bindRole($childWith, $otherRole);
        $this->actAs($top);

        $this->callApi('agent-permission/sync-children')->assertJsonPath('code', 200);

        $codesOf = fn (int $agentId) => AgentPermission::query()
            ->join('agent_menus', 'agent_menus.id', '=', 'agent_permissions.agent_menu_id')
            ->where('agent_id', $agentId)->pluck('agent_menus.code')->sort()->values()->all();
        $this->assertSame(['report_a', 'report_b'], $codesOf($childWithout->id), '同步入口本身不複製');
        $this->assertSame(['report_a', 'report_b'], $codesOf($childWith->id), '只增不重複');
        $this->assertSame($role->id, $childWithout->refresh()->agent_permission_group_id);
        $this->assertSame($otherRole->id, $childWith->refresh()->agent_permission_group_id, '已有角色不覆蓋');
        $this->assertTrue(AgentOperateLog::query()->where('agent_id', $top->id)->where('type', 'agent_permission_sync')->exists());
    }

    public function test_child_accounts_lists_only_own_sub_accounts(): void
    {
        $top = $this->topAgent();
        $mine = $this->downline($top, ['host_id' => $top->id]);
        $this->downline($top); // 一般下線不是子帳號
        $other = $this->topAgent();
        $this->downline($other, ['host_id' => $other->id]);
        $this->actAs($top);

        $data = $this->callApi('agent-permission/child-accounts')->assertJsonPath('code', 200)->json('data');

        $this->assertTrue($data['is_not_child_account']);
        $this->assertSame(1, $data['childCount']);
        $this->assertSame([['id' => $mine->id, 'username' => $mine->username]], $data['childs']);
    }

    public function test_sync_children_rejects_sub_account_and_no_children(): void
    {
        $top = $this->topAgent();
        $this->grant($top, 'agent_sync_permissiom');
        $this->actAs($top);
        $this->callApi('agent-permission/sync-children')
            ->assertJsonPath('message', __('error_message.agent_permission.sync_no_sub_account'));

        $sub = $this->downline($top, ['host_id' => $top->id]);
        $this->grant($sub, 'agent_sync_permissiom');
        $this->actAs($sub);
        $this->callApi('agent-permission/sync-children')
            ->assertJsonPath('message', __('error_message.agent_permission.sync_sub_account_forbidden'));
    }

    public function test_admin_role_is_never_filled_into_non_admin_sub_accounts(): void
    {
        // 4422：主帳號若綁 admin 角色（理論上不該發生），子帳號不是 admin 子帳號，同步時不得補過去
        $top = $this->topAgent();
        $this->grant($top, 'agent_sync_permissiom');
        $this->bindRole($top, $this->role('admin', AgentPermissionGroup::ADMIN_OWNER_ID));
        $child = $this->downline($top, ['host_id' => $top->id]);
        $this->actAs($top);

        $this->callApi('agent-permission/sync-children')->assertJsonPath('code', 200);
        $this->assertNull($child->refresh()->agent_permission_group_id);
    }

    /** 4498：super admin 本人帶 admin 角色同步子帳號時，只補給「已開 2FA 且白名單非空」的子帳號，其餘不補 */
    public function test_sync_children_fills_admin_role_only_to_children_meeting_requirements(): void
    {
        // 只有 super admin（id=1）的子帳號能拿 admin 角色，而子帳號本身不能同步，所以操作者必須是 super admin 本人
        $operator = Agent::query()->find(AgentPermissionGroup::ADMIN_OWNER_ID)
            ?? $this->bareAgent(['id' => AgentPermissionGroup::ADMIN_OWNER_ID, 'role_id' => RoleEnum::ADMIN->value]);
        $this->grant($operator, 'agent_sync_permissiom');
        $admin = $this->role('admin', AgentPermissionGroup::ADMIN_OWNER_ID);
        $this->bindRole($operator, $admin);

        $ready = $this->downline($operator, ['host_id' => $operator->id, 'has_2fa' => 1, 'login_ip_whitelist' => '0.0.0.0']);
        $no2fa = $this->downline($operator, ['host_id' => $operator->id, 'has_2fa' => 0, 'login_ip_whitelist' => '0.0.0.0']);
        $noWhitelist = $this->downline($operator, ['host_id' => $operator->id, 'has_2fa' => 1, 'login_ip_whitelist' => '']);
        $this->actAs($operator);

        $this->callApi('agent-permission/sync-children')->assertJsonPath('code', 200);

        $this->assertSame($admin->id, $ready->refresh()->agent_permission_group_id);
        $this->assertNull($no2fa->refresh()->agent_permission_group_id, '未開 2FA 不補 admin 角色');
        $this->assertNull($noWhitelist->refresh()->agent_permission_group_id, '白名單為空不補 admin 角色');
    }

    /**
     * 一般代理（21）操作者與它的下線：總代 → 一般代理（操作者，有 add_agent、report_a）→ 下線（目標）。
     *
     * @return array{0: Agent, 1: Agent}
     */
    private function lineAgentOperatorAndTarget(): array
    {
        $operator = $this->downline($this->topAgent());
        $this->grant($operator, 'add_agent', 'report_a');
        $target = $this->bareAgent(['role_id' => RoleEnum::AGENT->value]);
        $target->forceFill([
            'parent' => $operator->id,
            'hierarchy_path' => $operator->hierarchy_path . '/' . $target->id,
            'hierarchy' => "[-1,{$target->id},{$operator->id},{$operator->parent},-2]",
        ])->save();

        return [$operator, $target->refresh()];
    }

    /** @return array{0: AgentMenu, 1: AgentMenu} */
    private function memberBankAccountMenus(): array
    {
        return [$this->menu('member_bank_account'), $this->menu('member_bank_account_edit_permission')];
    }
}
