<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\UserRoleEnum;
use App\Enums\UserStatusEnum;
use App\Models\User;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * 建立 Olympus 後台 superadmin 帳號
 *
 * 帳號密碼由環境變數 SUPERADMIN_USERNAME / SUPERADMIN_PASSWORD 提供
 * （config/custom.php superadmin 區塊），未設定時直接失敗不寫入。
 * 可重複執行：以 username 為鍵 upsert，重跑會將密碼重設回 env 設定值。
 *
 * php artisan db:seed --class=SuperadminUserSeeder
 */
class SuperadminUserSeeder extends Seeder
{
    public function run(): void
    {
        $username = config('custom.superadmin.username');
        $password = [REDACTED]'custom.superadmin.password');

        if (blank($username) || blank($password)) {
            throw new RuntimeException('SUPERADMIN_USERNAME / SUPERADMIN_PASSWORD is not set, abort seeding');
        }

        User::updateOrCreate(
            ['username' => $username],
            [
                'parent' => 1,
                'nickname' => 'target Admin',
                'email' => "{$username}@example.invalid",
                'password' => $password,
                'status' => UserStatusEnum::NORMAL->value,
                'role' => UserRoleEnum::SUPERADMIN,
                'admin' => 1,
            ],
        );
    }
}
