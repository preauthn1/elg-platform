<?php

declare(strict_types=1);

namespace Tests\Feature\Olympus;

use App\Enums\AdminOperationEnum;
use App\Enums\UserRoleEnum;
use App\Enums\UserStatusEnum;
use App\Filament\Admin\Pages\Operations\OperationLogs;
use App\Filament\Admin\Pages\Operations\SchemaOperation;
use App\Filament\Admin\Pages\Queries\VendorAccountPool;
use App\Filament\Admin\Resources\System\UserResource\Pages\ListUser;
use App\Models\AdminOperationLog;
use App\Models\User;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

/**
 * Olympus 菜單權限：users.role 決定頁面可否進入（target/tickets#4359 第一期）。
 *
 * php artisan test tests/Feature/Olympus/RoleMenuAccessTest.php --compact
 */
class RoleMenuAccessTest extends TestCase
{
    private function makeUser(UserRoleEnum $role): User
    {
        return User::create([
            'parent' => 0,
            'username' => 'role_' . strtolower($role->name) . '_' . uniqid(),
            'email' => 'role_' . uniqid() . '@example.invalid',
            'password' => 'not-used',
            'status' => UserStatusEnum::NORMAL->value,
            'role' => $role->value,
            'admin' => 0,
        ]);
    }

    #[TestDox('運維進「資料表加索引／加欄位」被擋 403，其他操作頁與查詢頁照常')]
    #[Group('rules/vendor-account-pool#R-21')]
    public function test_ops_is_denied_schema_operation_but_allowed_elsewhere(): void
    {
        $this->actingAs($this->makeUser(UserRoleEnum::OPS), 'admin');

        $this->assertFalse(SchemaOperation::canAccess());
        Livewire::test(SchemaOperation::class)->assertForbidden();

        $this->assertTrue(OperationLogs::canAccess());
        $this->assertTrue(VendorAccountPool::canAccess());
        Livewire::test(VendorAccountPool::class)->assertOk();
    }

    #[TestDox('ADMIN 與 SUPERADMIN 全部頁面可進，含資料表加索引／加欄位')]
    public function test_admin_roles_can_access_everything(): void
    {
        foreach ([UserRoleEnum::ADMIN, UserRoleEnum::SUPERADMIN] as $role) {
            $this->actingAs($this->makeUser($role), 'admin');

            $this->assertTrue(SchemaOperation::canAccess(), $role->name);
            Livewire::test(SchemaOperation::class)->assertOk();
        }
    }

    #[TestDox('用戶管理 RD（ADMIN）與 SUPERADMIN 能看，運維 403（UserPolicy）')]
    public function test_user_resource_hidden_from_ops(): void
    {
        $this->actingAs($this->makeUser(UserRoleEnum::OPS), 'admin');
        Livewire::test(ListUser::class)->assertForbidden();

        $this->actingAs($this->makeUser(UserRoleEnum::ADMIN), 'admin');
        Livewire::test(ListUser::class)->assertOk();

        $this->actingAs($this->makeUser(UserRoleEnum::SUPERADMIN), 'admin');
        Livewire::test(ListUser::class)->assertOk();
    }

    #[TestDox('ADMIN 可把運維升成 ADMIN，但不能把人升成 SUPERADMIN、也不能動 SUPERADMIN 的帳號')]
    public function test_admin_cannot_grant_or_touch_superadmin(): void
    {
        $admin = $this->makeUser(UserRoleEnum::ADMIN);
        $ops = $this->makeUser(UserRoleEnum::OPS);
        $superadmin = $this->makeUser(UserRoleEnum::SUPERADMIN);
        $this->actingAs($admin, 'admin');

        Livewire::test(ListUser::class)
            ->callTableAction('changeRole', $ops, data: ['role' => UserRoleEnum::ADMIN->value])
            ->assertHasNoTableActionErrors();
        $this->assertSame(UserRoleEnum::ADMIN, $ops->fresh()->role);

        // 選項已過濾掉 SUPERADMIN；直接塞 payload 也被 action 內的 canAssign 擋下
        Livewire::test(ListUser::class)
            ->callTableAction('changeRole', $ops, data: ['role' => UserRoleEnum::SUPERADMIN->value]);
        $this->assertSame(UserRoleEnum::ADMIN, $ops->fresh()->role);

        Livewire::test(ListUser::class)->assertTableActionDisabled('changeRole', $superadmin);
        $this->assertSame(UserRoleEnum::SUPERADMIN, $superadmin->fresh()->role);
    }

    #[TestDox('SUPERADMIN 在帳號管理把運維升成 ADMIN：寫入 users.role 並留 ChangeRole 稽核')]
    public function test_superadmin_changes_role_with_audit(): void
    {
        $superadmin = $this->makeUser(UserRoleEnum::SUPERADMIN);
        $target = $this->makeUser(UserRoleEnum::OPS);
        $this->actingAs($superadmin, 'admin');

        Livewire::test(ListUser::class)
            ->callTableAction('changeRole', $target, data: ['role' => UserRoleEnum::ADMIN->value])
            ->assertHasNoTableActionErrors();

        $this->assertSame(UserRoleEnum::ADMIN, $target->fresh()->role);

        $log = AdminOperationLog::where('operation', AdminOperationEnum::ChangeRole->value)->latest('id')->first();
        $this->assertNotNull($log);
        $this->assertSame($superadmin->id, $log->user_id);
        $this->assertSame($target->id, (int) $log->params['target_user_id']);
        $this->assertSame(UserRoleEnum::OPS->value, (int) $log->params['from']);
        $this->assertSame(UserRoleEnum::ADMIN->value, (int) $log->params['to']);
    }

    #[TestDox('SUPERADMIN 不能改自己的角色（動作 disabled），避免把最後一把鑰匙鎖在門外')]
    public function test_superadmin_cannot_change_own_role(): void
    {
        $superadmin = $this->makeUser(UserRoleEnum::SUPERADMIN);
        $this->actingAs($superadmin, 'admin');

        Livewire::test(ListUser::class)->assertTableActionDisabled('changeRole', $superadmin);

        $this->assertSame(UserRoleEnum::SUPERADMIN, $superadmin->fresh()->role);
    }
}
