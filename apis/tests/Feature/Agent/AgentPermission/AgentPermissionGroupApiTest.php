<?php

declare(strict_types=1);

namespace Tests\Feature\Agent\AgentPermission;

use App\Models\Agent;
use App\Models\AgentPermissionGroup;
use App\Models\AgentPermissionGroupMenu;
use Illuminate\Support\Str;
use Tests\Feature\Agent\AgentPermission\Concerns\BuildsAgentPermissionFixtures;
use Tests\TestCase;

/**
 * php artisan test tests/Feature/Agent/AgentPermission/AgentPermissionGroupApiTest.php
 *
 * 代理角色管理（target/tickets#4422 自 API_Agent 搬入）：持有者（4543，取代 4080 分線）、每持有者一個預設（op3168）、
 * admin預設角色（4422）的規則與 API_Agent 版等價；角色下拉依操作者身份與目標帳號給角色。
 *
 * 4543 持有者規則：owner NULL＝admin 持有（只有 admin 能套、可套給任何人）；總代／一般代理建的 owner 固定寫自己；
 * 總代列表看自己＋下線建的（下線建的只能檢視）、一般代理只看自己建的；
 * 下拉：admin 給 NULL（目標是 admin 子帳號再加 1）、總代給自己建的、一般代理給所屬總代預設＋自己建的。
 */
class AgentPermissionGroupApiTest extends TestCase
{
    use BuildsAgentPermissionFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootAgentApi();
    }

    /** 4543：總代列表＝自己持有的＋下線一般代理建的（只能檢視）；admin 持有、admin預設角色、別的總代都不列 */
    public function test_top_agent_lists_own_and_downline_created_roles_only(): void
    {
        $top = $this->topAgent();
        $otherTop = $this->topAgent();
        $downline = $this->downline($top);
        $this->grant($top, 'permission_group_management');
        $adminOwned = $this->role('admin持有', null);
        $mine = $this->role('自己建', $top->id);
        $byDownline = $this->role('下線建', $downline->id);
        $other = $this->role('他總代', $otherTop->id);
        $admin = $this->role('admin預設', AgentPermissionGroup::ADMIN_OWNER_ID);
        $this->actAs($top);

        $items = collect($this->callApi('agent-permission-group/list', ['per_page' => 100])
            ->assertJsonPath('code', 200)
            ->json('data.items'))->keyBy('id');

        $this->assertTrue($items->has($mine->id));
        $this->assertTrue($items[$mine->id]['can_edit']);
        $this->assertTrue($items->has($byDownline->id), '總代看得到下線建的');
        $this->assertFalse($items[$byDownline->id]['can_edit'], '下線建的只能檢視');
        $this->assertFalse($items->has($adminOwned->id), '總代看不到 admin 持有的角色');
        $this->assertFalse($items->has($other->id), '看不到別的總代');
        $this->assertFalse($items->has($admin->id), '看不到 admin預設角色');
    }

    /** 4543：總代可依所屬代理篩（自己＋有建角色的下線），篩不出範圍外的 */
    public function test_top_agent_filters_list_by_owner_within_scope(): void
    {
        $top = $this->topAgent();
        $downline = $this->downline($top);
        $quiet = $this->downline($top);
        $otherTop = $this->topAgent();
        $this->grant($top, 'permission_group_management');
        $mine = $this->role('自己建', $top->id);
        $byDownline = $this->role('下線建', $downline->id);
        $other = $this->role('他總代', $otherTop->id);
        $this->actAs($top);

        $owners = array_column($this->callApi('agent-permission-group/owner-dropdown')->json('data.owners'), 'id');
        $this->assertEqualsCanonicalizing([$top->id, $downline->id], $owners, '沒建角色的下線不列');
        $this->assertNotContains($quiet->id, $owners);

        $ids = fn (array $f) => collect($this->callApi('agent-permission-group/list', $f + ['per_page' => 100])
            ->assertJsonPath('code', 200)->json('data.items'))->pluck('id')->all();
        $this->assertSame([$byDownline->id], $ids(['owner_agent_id' => $downline->id]));
        $this->assertSame([$mine->id], $ids(['owner_agent_id' => $top->id]));
        $this->assertSame([], $ids(['owner_agent_id' => $otherTop->id]), '範圍外的持有者篩不出來');
        $this->assertNotContains($other->id, $ids([]));
    }

    /** 4543：一般代理列表只列自己建的，連所屬總代的都不列 */
    public function test_general_agent_lists_only_own_created_roles(): void
    {
        $top = $this->topAgent();
        $operator = $this->downline($top);
        $sibling = $this->downline($top);
        $this->grant($operator, 'permission_group_management');
        $own = $this->role('自己建', $operator->id);
        $topRole = $this->role('總代建', $top->id, default: 1);
        $siblingRole = $this->role('同層建', $sibling->id);
        $adminOwned = $this->role('admin持有', null);
        $this->actAs($operator);

        $ids = collect($this->callApi('agent-permission-group/list', ['per_page' => 100])
            ->assertJsonPath('code', 200)
            ->json('data.items'))->pluck('id');

        $this->assertSame([$own->id], $ids->all());
        $this->assertFalse($ids->contains($topRole->id));
        $this->assertFalse($ids->contains($siblingRole->id));
        $this->assertFalse($ids->contains($adminOwned->id));
    }

    public function test_super_admin_filters_admin_roles_and_owner_label_is_admin(): void
    {
        $operator = $this->adminSubAccount();
        $admin = $this->role('admin', AgentPermissionGroup::ADMIN_OWNER_ID);
        $line = $this->role('線路', $this->topAgent()->id);
        $this->actAs($operator);

        $items = collect($this->callApi('agent-permission-group/list', ['owner_agent_id' => 1, 'per_page' => 100])
            ->assertJsonPath('code', 200)
            ->json('data.items'));

        $this->assertTrue($items->pluck('id')->contains($admin->id));
        $this->assertFalse($items->pluck('id')->contains($line->id));
        $this->assertSame('admin', $items->firstWhere('id', $admin->id)['owner_username']);
    }

    /** 4543：列表依所屬代理分組（admin預設角色 → admin 持有 → 各持有者），組內預設在前、再依 id 新到舊 */
    public function test_list_groups_by_owner_with_default_first(): void
    {
        $operator = $this->adminSubAccount();
        $topA = $this->topAgent();
        $topB = $this->topAgent();
        $prefix = 'srt_' . Str::random(6) . '_';
        $bOther = $this->role($prefix . 'b', $topB->id);
        $aOther = $this->role($prefix . 'a', $topA->id);
        $aDefault = $this->role($prefix . 'a預設', $topA->id, default: 1);
        $nullOld = $this->role($prefix . 'null舊', null);
        $adminOther = $this->role($prefix . 'admin其他', AgentPermissionGroup::ADMIN_OWNER_ID);
        $nullDefault = $this->role($prefix . 'null預設', null, default: 1);
        $nullNew = $this->role($prefix . 'null新', null);
        $adminDefault = $this->role($prefix . 'admin預設', AgentPermissionGroup::ADMIN_OWNER_ID, default: 1);
        $this->actAs($operator);

        $ids = collect($this->callApi('agent-permission-group/list', ['name' => $prefix, 'per_page' => 100])
            ->assertJsonPath('code', 200)
            ->json('data.items'))->pluck('id')->all();

        $this->assertSame([
            $adminDefault->id, $adminOther->id,
            $nullDefault->id, $nullNew->id, $nullOld->id,
            $aDefault->id, $aOther->id,
            $bOther->id,
        ], $ids);
    }

    public function test_route_requires_permission_group_management(): void
    {
        $top = $this->topAgent();
        $this->menu('permission_group_management');
        $this->actAs($top);

        $this->callApi('agent-permission-group/list')->assertJsonPath('message', __('error_message.9003'));
    }

    public function test_route_permission_ignores_disabled_role(): void
    {
        // 全站權限閘與選單樹同一套規則：角色停用就不採計角色權限（照 API_Agent）
        $top = $this->topAgent();
        $role = $this->role('角色', $top->id, [$this->menu('permission_group_management')->id], status: 0);
        $this->bindRole($top, $role);
        $this->actAs($top);

        $this->callApi('agent-permission-group/list')->assertJsonPath('message', __('error_message.9003'));

        $role->update(['status' => 1]);
        $this->callApi('agent-permission-group/list')->assertJsonPath('code', 200);
    }

    /** 4543：非 super admin 建角色，持有者固定寫自己的主帳號；請求帶別人（含所屬總代）一律 9003 */
    public function test_non_super_admin_create_forces_owner_to_self(): void
    {
        $top = $this->topAgent();
        $downline = $this->downline($top);
        $this->grant($top, 'permission_group_management');
        $this->grant($downline, 'permission_group_management');

        $this->actAs($top);
        $this->callApi('agent-permission-group/create', [
            'name' => 'x', 'owner_agent_id' => $this->topAgent()->id, 'status' => 1, 'menus' => [],
        ])->assertJsonPath('message', __('error_message.9003'));
        $id = $this->callApi('agent-permission-group/create', ['name' => '總代建', 'status' => 1, 'menus' => []])
            ->assertJsonPath('code', 200)->json('data.id');
        $this->assertSame($top->id, AgentPermissionGroup::query()->find($id)->owner_agent_id);

        $this->actAs($downline);
        $this->callApi('agent-permission-group/create', [
            'name' => 'y', 'owner_agent_id' => $top->id, 'status' => 1, 'menus' => [],
        ])->assertJsonPath('message', __('error_message.9003'), '一般代理不能把角色掛到總代名下（4543 前會記成總代）');
        $this->callApi('agent-permission-group/create', [
            'name' => 'z', 'owner_agent_id' => null, 'status' => 1, 'menus' => [],
        ])->assertJsonPath('code', 200);
        $id = $this->callApi('agent-permission-group/create', ['name' => '一般代理建', 'status' => 1, 'menus' => []])
            ->assertJsonPath('code', 200)->json('data.id');
        $this->assertSame($downline->id, AgentPermissionGroup::query()->find($id)->owner_agent_id, '一般代理建的記自己');

        // 編輯時帶別人的 owner 同樣 9003；沒帶就維持自己
        $this->callApi('agent-permission-group/update', [
            'id' => $id, 'name' => '改', 'owner_agent_id' => $top->id, 'status' => 1, 'menus' => [],
        ])->assertJsonPath('message', __('error_message.9003'));
        $this->callApi('agent-permission-group/update', ['id' => $id, 'name' => '改', 'status' => 1, 'menus' => []])
            ->assertJsonPath('code', 200);
        $this->assertSame($downline->id, AgentPermissionGroup::query()->find($id)->owner_agent_id);
    }

    /** 4543：誰建的就記誰——admin（含子帳號）建的一律 admin 持有，不能替總代建、也不能再新建 admin預設角色（1） */
    public function test_super_admin_creates_admin_held_role_only(): void
    {
        $this->actAs($this->adminSubAccount());
        $id = $this->callApi('agent-permission-group/create', ['name' => 'admin建', 'status' => 1, 'menus' => []])
            ->assertJsonPath('code', 200)->json('data.id');
        $this->assertNull(AgentPermissionGroup::query()->find($id)->owner_agent_id);

        foreach ([AgentPermissionGroup::ADMIN_OWNER_ID, $this->topAgent()->id] as $owner) {
            $this->callApi('agent-permission-group/create', ['name' => 'x', 'owner_agent_id' => $owner, 'status' => 1, 'menus' => []])
                ->assertJsonPath('message', __('error_message.9003'));
        }
    }

    /**
     * target/tickets#4486：不能使用會員銀行卡個資的操作者（一般代理 21）改角色，選單樹看不到銀行卡管理兩項、送來的 menus 不含它們；
     * 角色既有的這兩項必須保留且 action 不變（否則所有套用者一起被拿掉）。
     */
    public function test_operator_without_member_bank_account_access_keeps_role_bank_menus_on_update(): void
    {
        $top = $this->topAgent();
        $operator = $this->downline($top);
        $this->grant($operator, 'permission_group_management');
        $reportId = $this->menu('report_a')->id;
        [$bankId, $bankEditId] = $this->memberBankAccountMenuIds();
        $group = $this->role('自己建', $operator->id, [$reportId, $bankId, $bankEditId]);
        AgentPermissionGroupMenu::query()->where('agent_permission_group_id', $group->id)->where('agent_menu_id', $bankId)->update(['action' => 'view']);
        $this->actAs($operator);

        $this->callApi('agent-permission-group/update', [
            'id' => $group->id, 'name' => $group->name, 'owner_agent_id' => $operator->id, 'status' => 1, 'menus' => [$reportId],
        ])->assertJsonPath('code', 200);

        $actions = AgentPermissionGroupMenu::query()->where('agent_permission_group_id', $group->id)->pluck('action', 'agent_menu_id')->all();
        $this->assertEqualsCanonicalizing([$reportId, $bankId, $bankEditId], array_keys($actions), '角色既有的銀行卡管理兩項保留');
        $this->assertSame('view', $actions[$bankId], 'action 不變');

        // 拿掉一般選單照常生效，銀行卡兩項仍保留
        $this->callApi('agent-permission-group/update', [
            'id' => $group->id, 'name' => $group->name, 'owner_agent_id' => $operator->id, 'status' => 1, 'menus' => [],
        ])->assertJsonPath('code', 200);
        $this->assertEqualsCanonicalizing([$bankId, $bankEditId], $this->roleMenuIds($group->id));
    }

    /** target/tickets#4486：能使用會員銀行卡個資的操作者（總代 11）改角色，行為不變，送出不含這兩項就照常刪除 */
    public function test_general_agent_operator_deletes_role_bank_menus_on_update_as_usual(): void
    {
        $top = $this->topAgent();
        $this->grant($top, 'permission_group_management');
        $reportId = $this->menu('report_a')->id;
        [$bankId, $bankEditId] = $this->memberBankAccountMenuIds();
        $group = $this->role('本線', $top->id, [$reportId, $bankId, $bankEditId]);
        $this->actAs($top);

        $this->callApi('agent-permission-group/update', [
            'id' => $group->id, 'name' => $group->name, 'owner_agent_id' => $top->id, 'status' => 1, 'menus' => [$reportId],
        ])->assertJsonPath('code', 200);

        $this->assertSame([$reportId], $this->roleMenuIds($group->id));
    }

    /** target/tickets#4486：不能使用會員銀行卡個資的操作者新增角色，送來的銀行卡管理兩項一律剔除 */
    public function test_operator_without_member_bank_account_access_cannot_create_role_with_bank_menus(): void
    {
        $top = $this->topAgent();
        $operator = $this->downline($top);
        // 4543 起角色選單須在操作者權限內，report_a 要先給操作者
        $this->grant($operator, 'permission_group_management', 'report_a');
        $reportId = $this->menu('report_a')->id;
        [$bankId, $bankEditId] = $this->memberBankAccountMenuIds();
        $this->actAs($operator);

        $id = $this->callApi('agent-permission-group/create', [
            'name' => '新角色', 'status' => 1, 'menus' => [$reportId, $bankId, $bankEditId],
        ])->assertJsonPath('code', 200)->json('data.id');

        $this->assertSame([$reportId], $this->roleMenuIds((int) $id));
    }

    /** 4543：所屬代理建立後不可改；原樣送回或不送才放行 */
    public function test_owner_cannot_be_changed_after_creation(): void
    {
        $this->actAs($this->adminSubAccount());
        $top = $this->topAgent();
        $shared = $this->role('admin持有', null);
        $adminRole = $this->role('admin', AgentPermissionGroup::ADMIN_OWNER_ID);

        foreach ([[$shared, $top->id], [$shared, AgentPermissionGroup::ADMIN_OWNER_ID], [$adminRole, $top->id], [$adminRole, null]] as [$group, $owner]) {
            $this->callApi('agent-permission-group/update', [
                'id' => $group->id, 'name' => $group->name, 'owner_agent_id' => $owner, 'status' => 1, 'menus' => [],
            ])->assertJsonPath('message', __('error_message.9003'));
        }
        $this->assertNull($shared->refresh()->owner_agent_id);
        $this->assertSame(AgentPermissionGroup::ADMIN_OWNER_ID, $adminRole->refresh()->owner_agent_id);

        // 原樣送回、或不送：照常修改
        $this->callApi('agent-permission-group/update', [
            'id' => $shared->id, 'name' => '改名', 'owner_agent_id' => null, 'status' => 1, 'menus' => [],
        ])->assertJsonPath('code', 200);
        $this->callApi('agent-permission-group/update', [
            'id' => $adminRole->id, 'name' => '改名', 'owner_agent_id' => AgentPermissionGroup::ADMIN_OWNER_ID, 'status' => 1, 'menus' => [],
        ])->assertJsonPath('code', 200);
        $this->callApi('agent-permission-group/update', ['id' => $shared->id, 'name' => '再改', 'status' => 1, 'menus' => []])
            ->assertJsonPath('code', 200);
        $this->assertSame('再改', $shared->refresh()->name);
    }

    /** 4543：角色只有持有者能改——admin（含子帳號）對代理持有的角色只能檢視，不能修改或停用（Max 2026-10-20） */
    public function test_super_admin_can_only_view_agent_owned_roles(): void
    {
        $top = $this->topAgent();
        $topRole = $this->role('總代建', $top->id);
        $shared = $this->role('admin持有', null);
        $adminRole = $this->role('admin預設', AgentPermissionGroup::ADMIN_OWNER_ID);
        $this->actAs($this->adminSubAccount());

        $items = collect($this->callApi('agent-permission-group/list', ['per_page' => 100])->json('data.items'))->keyBy('id');
        $this->assertFalse($items[$topRole->id]['can_edit'], '代理持有的只能檢視');
        $this->assertTrue($items[$shared->id]['can_edit']);
        $this->assertTrue($items[$adminRole->id]['can_edit']);

        $this->callApi('agent-permission-group/view', ['id' => $topRole->id])
            ->assertJsonPath('code', 200)->assertJsonPath('data.can_edit', false);
        $this->callApi('agent-permission-group/status', ['id' => $topRole->id, 'status' => 0])
            ->assertJsonPath('message', __('error_message.9003'));
        $this->callApi('agent-permission-group/update', [
            'id' => $topRole->id, 'name' => '改', 'owner_agent_id' => $top->id, 'status' => 1, 'menus' => [],
        ])->assertJsonPath('message', __('error_message.9003'));
        $this->assertSame(1, (int) $topRole->refresh()->status);
    }

    public function test_default_role_rules(): void
    {
        $top = $this->topAgent();
        $this->grant($top, 'permission_group_management');
        $existing = $this->role('既有預設', $top->id, default: 1);
        $this->actAs($top);

        // 同一持有者第二個預設擋下，不自動頂替
        $this->callApi('agent-permission-group/create', [
            'name' => 'y', 'status' => 1, 'default_setting' => 1, 'menus' => [],
        ])->assertJsonPath('message', __('error_message.agent_permission_group.default_exists', ['name' => $existing->name]));

        // 預設角色不得停用；狀態沒變視為失敗
        $this->callApi('agent-permission-group/status', ['id' => $existing->id, 'status' => 0])
            ->assertJsonPath('message', __('error_message.9003'));
        $this->callApi('agent-permission-group/status', ['id' => $existing->id, 'status' => 1])
            ->assertJsonPath('message', __('error_message.agent_permission_group.status_unchanged'));

        // admin 持有的預設（共用預設角色）不得取消
        $sharedDefault = $this->role('共用預設', null, default: 1);
        $this->actAs($this->adminSubAccount());
        $this->callApi('agent-permission-group/update', [
            'id' => $sharedDefault->id, 'name' => $sharedDefault->name, 'status' => 1, 'default_setting' => 0, 'menus' => [],
        ])->assertJsonPath('message', __('error_message.9003'));
        $this->assertSame(1, (int) $sharedDefault->refresh()->default_setting);
    }

    public function test_dropdown_gives_admin_roles_only_to_admin_sub_account_targets(): void
    {
        $operator = $this->adminSubAccount();
        $this->actAs($operator);
        $shared = $this->role('共用', null);
        $lineTop = $this->topAgent();
        $line = $this->role('線路', $lineTop->id);
        $admin = $this->role('admin', AgentPermissionGroup::ADMIN_OWNER_ID);

        $forAdminSub = collect($this->callApi('agent-permission-group/dropdown', ['is_sub_account' => 1, 'parent' => 1])
            ->assertJsonPath('code', 200)->json('data'))->pluck('id');
        $this->assertTrue($forAdminSub->contains($admin->id));
        $this->assertTrue($forAdminSub->contains($shared->id));
        $this->assertFalse($forAdminSub->contains($line->id), 'admin 子帳號不給線路角色');

        $forNormal = collect($this->callApi('agent-permission-group/dropdown', ['is_sub_account' => 0, 'parent' => $lineTop->id])
            ->json('data'))->pluck('id');
        $this->assertFalse($forNormal->contains($admin->id), '其他帳號不給 admin 角色');
        $this->assertTrue($forNormal->contains($shared->id));
        $this->assertFalse($forNormal->contains($line->id), '4543：admin 只能套 admin 持有的角色，總代持有的不給');

        $noTarget = collect($this->callApi('agent-permission-group/dropdown')->json('data'))->pluck('id');
        $this->assertFalse($noTarget->contains($admin->id), '沒帶目標＝一般帳號');
    }

    /** 4543：admin（含子帳號）幫任何人選角色，只給 admin 持有的；總代持有的一律不給，不論目標在哪條線 */
    public function test_super_admin_dropdown_gives_admin_owned_roles_only(): void
    {
        $this->actAs($this->adminSubAccount());
        $frank = $this->topAgent();
        $other = $this->topAgent();
        $shared = $this->role('admin持有', null);
        $frankRole = $this->role('frank建', $frank->id);
        $otherMenuId = $this->menu('report_other_line')->id;
        $otherRole = $this->role('他線', $other->id, [$otherMenuId]);
        $ids = fn (array $payload) => collect($this->callApi('agent-permission-group/dropdown', $payload)
            ->assertJsonPath('code', 200)->json('data'))->where('status', 1)->pluck('id');

        foreach ([
            '新增下線' => ['is_sub_account' => 0, 'parent' => $frank->id],
            '新增子帳號' => ['is_sub_account' => 1, 'parent' => $frank->id],
            '編輯下線' => ['agent_id' => $this->underAdmin($this->downline($frank))->id],
            '新總代' => ['is_sub_account' => 0, 'parent' => 1],
        ] as $case => $payload) {
            $usable = $ids($payload);
            $this->assertTrue($usable->contains($shared->id), $case);
            $this->assertFalse($usable->contains($frankRole->id), $case . '：總代持有的 admin 套不到');
            $this->assertFalse($usable->contains($otherRole->id), $case);
        }

        // 已綁總代持有角色的帳號：照樣列出既有綁定，但標成不可選
        $crossBound = $this->underAdmin($this->downline($frank));
        $this->bindRole($crossBound, $otherRole);
        $row = collect($this->callApi('agent-permission-group/dropdown', ['agent_id' => $crossBound->id])->json('data'))
            ->firstWhere('id', $otherRole->id);
        $this->assertSame(1, $row['status'], '角色本身啟用中：不是停用，只是操作者套不到');
        $this->assertSame([$otherMenuId], $row['menus'], '不可用的既有綁定 menus 照實回，前端存檔時才扣得掉角色給的那份');
        $this->assertFalse($row['assignable']);
        $this->assertSame(__('error_message.agent_permission_group.unusable_not_owned'), $row['unassignable_reason']);

        // 角色本身停用：不提供權限，menus 回空，否則前端會把代理個人權限裡重疊的項目當角色給的扣掉
        $disabled = $this->role('停用', null, [$otherMenuId], status: 0);
        $disabledBound = $this->underAdmin($this->downline($frank));
        $this->bindRole($disabledBound, $disabled);
        $row = collect($this->callApi('agent-permission-group/dropdown', ['agent_id' => $disabledBound->id])->json('data'))
            ->firstWhere('id', $disabled->id);
        $this->assertSame(0, $row['status'], '只有角色本身停用才回 0');
        $this->assertSame([], $row['menus'], '停用角色的 menus 回空');
        $this->assertSame(__('error_message.agent_permission_group.unusable_disabled'), $row['unassignable_reason']);
    }

    /** 4543：總代自己持有、但權限超出總代本人的角色（admin 替它建的），綁在下線身上時下拉標「權限超出」 */
    public function test_dropdown_explains_menus_exceed_for_bound_role(): void
    {
        $top = $this->topAgent();
        $downline = $this->downline($top);
        $this->grant($top, 'add_agent');
        $beyond = $this->role('超出', $top->id, [$this->menu('report_beyond')->id]);
        $this->bindRole($downline, $beyond);
        $this->actAs($top);

        $row = collect($this->callApi('agent-permission-group/dropdown', ['agent_id' => $downline->id])
            ->assertJsonPath('code', 200)->json('data'))->firstWhere('id', $beyond->id);
        $this->assertSame(1, $row['status'], '權限超出不是停用，前端要顯示原因而不是「（停用）」');
        $this->assertFalse($row['assignable']);
        $this->assertSame(__('error_message.agent_permission_group.unusable_menus_exceed'), $row['unassignable_reason']);
    }

    /** 正式資料的 hierarchy 都含 super admin（1）；fixture 預設沒有，admin 子帳號的線下範疇判斷需要補上 */
    private function underAdmin(Agent $agent): Agent
    {
        $agent->forceFill(['hierarchy' => str_replace(',-2]', ',1,-2]', (string) $agent->hierarchy)])->save();

        return $agent->refresh();
    }

    public function test_dropdown_rejects_agent_outside_scope(): void
    {
        $top = $this->topAgent();
        $this->grant($top, 'add_agent');
        $this->actAs($top);

        $this->callApi('agent-permission-group/dropdown', ['agent_id' => $this->topAgent()->id])
            ->assertJsonPath('message', __('error_message.9003'));
        // 新增時的上層也要在範疇內（4543 補）：拿別條線的 parent 來探角色一樣 9003
        $this->callApi('agent-permission-group/dropdown', ['is_sub_account' => 0, 'parent' => $this->topAgent()->id])
            ->assertJsonPath('message', __('error_message.9003'));
        $this->callApi('agent-permission-group/dropdown', ['agent_id' => $this->downline($top)->id])
            ->assertJsonPath('code', 200);
    }

    /** 4543：總代的下拉只給自己建的（含自己的預設）；admin 持有、別的總代、下線建的、admin預設角色都不給 */
    public function test_top_agent_dropdown_lists_own_roles_only(): void
    {
        $top = $this->topAgent();
        $downline = $this->downline($top);
        $this->grant($top, 'add_agent');
        $own = $this->role('自己建', $top->id);
        $ownDefault = $this->role('自己預設', $top->id, default: 1);
        $adminOwned = $this->role('admin持有', null);
        $other = $this->role('他總代', $this->topAgent()->id);
        $byDownline = $this->role('下線建', $downline->id);
        $admin = $this->role('admin預設', AgentPermissionGroup::ADMIN_OWNER_ID);
        $this->actAs($top);

        $ids = collect($this->callApi('agent-permission-group/dropdown', ['agent_id' => $downline->id])
            ->assertJsonPath('code', 200)->json('data'))->where('status', 1)->pluck('id');

        $this->assertEqualsCanonicalizing([$own->id, $ownDefault->id], $ids->all());
        foreach ([$adminOwned, $other, $byDownline, $admin] as $notMine) {
            $this->assertFalse($ids->contains($notMine->id));
        }
    }

    /** 4543：一般代理的下拉＝所屬總代的預設角色（只有那一個）＋自己建的；總代的其他角色、admin 持有、同層建的都不給 */
    public function test_general_agent_dropdown_lists_top_default_and_own_roles(): void
    {
        $top = $this->topAgent();
        $operator = $this->downline($top);
        $sibling = $this->downline($top);
        $target = $this->downline($operator);
        $this->grant($operator, 'add_agent');
        $topDefault = $this->role('總代預設', $top->id, default: 1);
        $topOther = $this->role('總代其他', $top->id);
        $own = $this->role('自己建', $operator->id);
        $siblingRole = $this->role('同層建', $sibling->id);
        $adminOwned = $this->role('admin持有', null);
        $this->actAs($operator);

        $ids = collect($this->callApi('agent-permission-group/dropdown', ['agent_id' => $target->id])
            ->assertJsonPath('code', 200)->json('data'))->where('status', 1)->pluck('id');

        $this->assertEqualsCanonicalizing([$topDefault->id, $own->id], $ids->all());
        foreach ([$topOther, $siblingRole, $adminOwned] as $notMine) {
            $this->assertFalse($ids->contains($notMine->id));
        }
    }

    /** 4543：一般代理看不到也改不了總代的角色；總代對下線建的只能檢視，改與停用都 9003 */
    public function test_view_and_edit_scope_between_top_and_downline(): void
    {
        $top = $this->topAgent();
        $downline = $this->downline($top);
        $this->grant($top, 'permission_group_management');
        $this->grant($downline, 'permission_group_management');
        $topRole = $this->role('總代建', $top->id);
        $downlineRole = $this->role('下線建', $downline->id);

        $this->actAs($downline);
        foreach (['view' => ['id' => $topRole->id], 'status' => ['id' => $topRole->id, 'status' => 0],
            'update' => ['id' => $topRole->id, 'name' => 'x', 'status' => 1, 'menus' => []]] as $action => $payload) {
            $this->callApi('agent-permission-group/' . $action, $payload)
                ->assertJsonPath('message', __('error_message.9003'), "一般代理 {$action} 總代的角色");
        }
        $this->callApi('agent-permission-group/view', ['id' => $downlineRole->id])
            ->assertJsonPath('code', 200)->assertJsonPath('data.can_edit', true);

        $this->actAs($top);
        $this->callApi('agent-permission-group/view', ['id' => $downlineRole->id])
            ->assertJsonPath('code', 200)->assertJsonPath('data.can_edit', false);
        $this->callApi('agent-permission-group/agents', ['id' => $downlineRole->id])->assertJsonPath('code', 200);
        $this->callApi('agent-permission-group/status', ['id' => $downlineRole->id, 'status' => 0])
            ->assertJsonPath('message', __('error_message.9003'), '總代不能停用下線建的');
        $this->callApi('agent-permission-group/update', ['id' => $downlineRole->id, 'name' => 'x', 'status' => 1, 'menus' => []])
            ->assertJsonPath('message', __('error_message.9003'), '總代不能修改下線建的');
        $this->assertSame(1, $downlineRole->refresh()->status);
    }

    public function test_dropdown_accepts_same_codes_as_setting(): void
    {
        // 只有「添加股東」的人從頂部「設定」編輯自己，也要拿得到下拉（與個人權限存檔同一組權限碼）
        $top = $this->topAgent();
        $this->menu('add_agent');
        $this->grant($top, 'cj7_add_user');
        $this->actAs($top);

        $this->callApi('agent-permission-group/dropdown', ['agent_id' => $top->id])->assertJsonPath('code', 200);
    }

    public function test_owner_options_put_admin_first_for_super_admin_only(): void
    {
        $top = $this->topAgent();
        $this->actAs($this->adminSubAccount());
        $owners = $this->callApi('agent-permission-group/owner-dropdown')->assertJsonPath('code', 200)->json('data');
        $this->assertFalse($owners['can_select'], '4543：建立／編輯時持有者一律不可選，選項只給列表篩選用');
        $this->assertTrue($owners['can_filter'], 'admin 列表可依所屬代理篩選');
        $this->assertFalse($owners['can_set_default'], 'admin 建的角色是 admin 持有，不能設預設');
        $this->assertSame(['id' => 1, 'username' => 'admin', 'is_admin' => true], $owners['owners'][0]);

        $this->grant($top, 'permission_group_management');
        $this->actAs($top);
        $mine = $this->callApi('agent-permission-group/owner-dropdown')->json('data');
        $this->assertFalse($mine['can_select']);
        $this->assertTrue($mine['can_filter'], '總代看得到自己＋下線建的，可依所屬代理篩');
        $this->assertTrue($mine['can_set_default'], '只有總代能設預設');
        $this->assertSame([$top->id], array_column($mine['owners'], 'id'), '下線還沒建角色時只有自己');

        // 4543：一般代理固定是自己，不是所屬總代
        $downline = $this->downline($top);
        $this->grant($downline, 'permission_group_management');
        $this->actAs($downline);
        $own = $this->callApi('agent-permission-group/owner-dropdown')->json('data');
        $this->assertFalse($own['can_select']);
        $this->assertFalse($own['can_filter']);
        $this->assertFalse($own['can_set_default'], '一般代理不能設預設');
        $this->assertSame([$downline->id], array_column($own['owners'], 'id'));
    }

    /** 4498：建立新帳號時 admin 角色一律不可選（新帳號不可能已開 2FA），共用角色不受影響 */
    public function test_dropdown_marks_admin_role_unassignable_for_new_account(): void
    {
        $this->actAs($this->adminSubAccount());
        $admin = $this->role('admin', AgentPermissionGroup::ADMIN_OWNER_ID);
        $shared = $this->role('共用', null);

        $rows = collect($this->callApi('agent-permission-group/dropdown', ['is_sub_account' => 1, 'parent' => 1])
            ->assertJsonPath('code', 200)->json('data'))->keyBy('id');

        $this->assertFalse($rows[$admin->id]['assignable']);
        $this->assertSame(__('error_message.agent_permission_group.admin_requires_new_account'), $rows[$admin->id]['unassignable_reason']);
        $this->assertSame(1, $rows[$admin->id]['status'], '只是不可選，不是停用');
        $this->assertTrue($rows[$shared->id]['assignable']);
        $this->assertNull($rows[$shared->id]['unassignable_reason']);
    }

    /** 4498：編輯既有 admin 子帳號時，缺 2FA 或白名單就反灰並列出缺項，兩項都齊才可選 */
    public function test_dropdown_marks_admin_role_assignable_only_with_2fa_and_login_ip_whitelist(): void
    {
        $this->actAs($this->adminSubAccount());
        $admin = $this->role('admin', AgentPermissionGroup::ADMIN_OWNER_ID);
        $target = $this->adminSubAccount();

        $reasonOf = function () use ($admin, $target): array {
            $row = collect($this->callApi('agent-permission-group/dropdown', ['agent_id' => $target->id])
                ->assertJsonPath('code', 200)->json('data'))->firstWhere('id', $admin->id);

            return [$row['assignable'], $row['unassignable_reason']];
        };
        // 回應文字依請求語系翻譯，期望值要在呼叫之後才用 __() 組（此時 app locale 已是請求的語系）
        $twoFactor = fn (): string => __('error_message.agent_permission_group.admin_requires_two_factor');
        $whitelist = fn (): string => __('error_message.agent_permission_group.admin_requires_login_ip_whitelist');

        $actual = $reasonOf();
        $this->assertSame([false, $twoFactor() . '／' . $whitelist()], $actual, '兩項都缺');

        $target->forceFill(['has_2fa' => 1])->save();
        $actual = $reasonOf();
        $this->assertSame([false, $whitelist()], $actual, '只缺白名單');

        $target->forceFill(['login_ip_whitelist' => '0.0.0.0, 2001:db8::1'])->save();
        $this->assertSame([true, null], $reasonOf(), '兩項都齊');

        $target->forceFill(['has_2fa' => 0])->save();
        $actual = $reasonOf();
        $this->assertSame([false, $twoFactor()], $actual, '只缺 2FA');
    }

    /** 4543：角色的選單必須在操作者自己的生效權限內（直接打 API 塞別的選單 id 要擋）；角色既有的照舊保留 */
    public function test_role_menus_must_be_within_operator_permissions(): void
    {
        $top = $this->topAgent();
        $this->grant($top, 'permission_group_management', 'report_a');
        $reportId = $this->menu('report_a')->id;
        $foreignId = $this->menu('report_z_not_mine')->id;
        $this->actAs($top);

        $this->callApi('agent-permission-group/create', ['name' => 'x', 'status' => 1, 'menus' => [$reportId, $foreignId]])
            ->assertJsonPath('message', __('error_message.agent_permission_group.menu_out_of_scope'));
        $id = $this->callApi('agent-permission-group/create', ['name' => 'x', 'status' => 1, 'menus' => [$reportId]])
            ->assertJsonPath('code', 200)->json('data.id');
        $this->callApi('agent-permission-group/update', ['id' => $id, 'name' => 'x', 'status' => 1, 'menus' => [$reportId, $foreignId]])
            ->assertJsonPath('message', __('error_message.agent_permission_group.menu_out_of_scope'));
        $this->assertSame([$reportId], $this->roleMenuIds((int) $id));

        // 角色既有、操作者沒有的選單（例如 admin 建的）送回來不算新增，照舊保留
        $existing = $this->role('既有', $top->id, [$reportId, $foreignId]);
        $this->callApi('agent-permission-group/update', ['id' => $existing->id, 'name' => 'y', 'status' => 1, 'menus' => [$reportId, $foreignId]])
            ->assertJsonPath('code', 200);

        // super admin 不限
        $this->actAs($this->adminSubAccount());
        $this->callApi('agent-permission-group/create', ['name' => 'z', 'status' => 1, 'menus' => [$foreignId]])
            ->assertJsonPath('code', 200);
    }

    /** 4543：只有總代（與 admin預設角色）能設預設角色；一般代理設預設回 9003 */
    public function test_only_top_agent_can_set_default_role(): void
    {
        $top = $this->topAgent();
        $downline = $this->downline($top);
        $this->grant($top, 'permission_group_management');
        $this->grant($downline, 'permission_group_management');

        $this->actAs($downline);
        $this->callApi('agent-permission-group/create', ['name' => 'd', 'status' => 1, 'default_setting' => 1, 'menus' => []])
            ->assertJsonPath('message', __('error_message.9003'));
        $own = $this->role('自己建', $downline->id);
        $this->callApi('agent-permission-group/update', ['id' => $own->id, 'name' => 'd', 'status' => 1, 'default_setting' => 1, 'menus' => []])
            ->assertJsonPath('message', __('error_message.9003'));

        $this->actAs($top);
        $this->callApi('agent-permission-group/create', ['name' => 't', 'status' => 1, 'default_setting' => 1, 'menus' => []])
            ->assertJsonPath('code', 200);
    }

    /** @return array{0: int, 1: int} 銀行卡管理、銀行卡編輯權限的選單 id */
    private function memberBankAccountMenuIds(): array
    {
        return [$this->menu('member_bank_account')->id, $this->menu('member_bank_account_edit_permission')->id];
    }

    /** @return array<int, int> */
    private function roleMenuIds(int $groupId): array
    {
        return AgentPermissionGroupMenu::query()->where('agent_permission_group_id', $groupId)
            ->orderBy('agent_menu_id')->pluck('agent_menu_id')->map(fn ($v) => (int) $v)->all();
    }
}
