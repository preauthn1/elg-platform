<?php

declare(strict_types=1);

namespace Tests\Unit\Enums;

use App\Enums\UserRoleEnum;
use App\Filament\Admin\Pages\Auth\EditProfile;
use App\Filament\Admin\Pages\Operations\FetchBetRecord;
use App\Filament\Admin\Pages\Operations\OperationLogs;
use App\Filament\Admin\Pages\Operations\SchemaOperation;
use App\Filament\Admin\Pages\Operations\TransferMoneyBack;
use App\Filament\Admin\Pages\Queries\BetRecordCorrection;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * php artisan test tests/Unit/Enums/UserRoleEnumTest.php --compact
 */
class UserRoleEnumTest extends TestCase
{
    #[TestDox('ADMIN／SUPERADMIN 全部頁面可進；OPS 只能進接受清單，未列的頁面（含資料表加索引）一律不給')]
    public function test_can_access_page_matrix(): void
    {
        foreach ([UserRoleEnum::ADMIN, UserRoleEnum::SUPERADMIN] as $role) {
            $this->assertTrue($role->canAccessPage(SchemaOperation::class), $role->name);
            $this->assertTrue($role->canAccessPage(TransferMoneyBack::class), $role->name);
            $this->assertTrue($role->canAccessPage(EditProfile::class), $role->name);
        }

        $this->assertTrue(UserRoleEnum::OPS->canAccessPage(TransferMoneyBack::class));
        $this->assertTrue(UserRoleEnum::OPS->canAccessPage(FetchBetRecord::class));
        $this->assertTrue(UserRoleEnum::OPS->canAccessPage(OperationLogs::class));
        $this->assertTrue(UserRoleEnum::OPS->canAccessPage(BetRecordCorrection::class));

        $this->assertFalse(UserRoleEnum::OPS->canAccessPage(SchemaOperation::class));
        // 接受清單：沒被列進去的頁面預設對運維關閉（EditProfile 只當探針，它本身沒掛 AuthorizesByUserRole）
        $this->assertFalse(UserRoleEnum::OPS->canAccessPage(EditProfile::class));
    }

    #[TestDox('可指派角色：SUPERADMIN 全部、ADMIN 只有 ADMIN／OPS、OPS 無')]
    public function test_assignable_roles(): void
    {
        $this->assertSame(UserRoleEnum::cases(), UserRoleEnum::SUPERADMIN->assignableRoles());
        $this->assertSame([UserRoleEnum::ADMIN, UserRoleEnum::OPS], UserRoleEnum::ADMIN->assignableRoles());
        $this->assertSame([], UserRoleEnum::OPS->assignableRoles());
        $this->assertSame([1 => 'Admin', 3 => '運維'], UserRoleEnum::ADMIN->assignableOptions());
    }

    public function test_options_lists_every_case_with_label(): void
    {
        $this->assertSame([1 => 'Admin', 2 => 'Super Admin', 3 => '運維'], UserRoleEnum::options());
    }
}
