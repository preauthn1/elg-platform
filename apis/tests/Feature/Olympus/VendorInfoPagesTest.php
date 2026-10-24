<?php

declare(strict_types=1);

namespace Tests\Feature\Olympus;

use App\Enums\UserRoleEnum;
use App\Enums\UserStatusEnum;
use App\Models\User;
use App\Models\VendorInfo;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

/**
 * Olympus 遊戲商商戶設定（vendor_infos）頁面：列表、新增、編輯頁不註冊路由（target/tickets#4517）。
 *
 * php artisan test tests/Feature/Olympus/VendorInfoPagesTest.php --compact
 */
class VendorInfoPagesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::create([
            'parent' => 0,
            'username' => 'vendor_info_admin_' . uniqid(),
            'email' => 'vendor_info_admin_' . uniqid() . '@example.invalid',
            'password' => 'not-used',
            'status' => UserStatusEnum::NORMAL->value,
            'role' => UserRoleEnum::ADMIN->value,
            'admin' => 0,
        ]), 'admin');
    }

    #[TestDox('遊戲商商戶設定的列表、新增、編輯頁不開放：直接開網址都回 404')]
    public function test_vendor_info_pages_are_not_routed(): void
    {
        $vendorInfo = VendorInfo::factory()->create();
        $base = '/olympus/vendor-management/vendor-infos';

        $this->get($base)->assertNotFound();
        $this->get($base . '/create')->assertNotFound();
        $this->get($base . '/' . $vendorInfo->getRouteKey() . '/edit')->assertNotFound();
    }
}
