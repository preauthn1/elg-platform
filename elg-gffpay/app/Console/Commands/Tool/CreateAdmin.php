<?php

declare(strict_types=1);

namespace App\Console\Commands\Tool;

use App\Models\Admin;
use Illuminate\Console\Command;

/**
 * 在DB admins 建立 管理員
 */
class CreateAdmin extends Command
{
    /**
     * php artisan tool:create_admin
     */
    protected $signature = 'tool:create_admin';

    public function handle(): void
    {

        $admin = Admin::where('id', 1)->first();
        if ($admin) {
            $admin->password = [REDACTED]';
            $admin->save();
            $this->info('Ton_log, Admin already exists, password updated');

            return;
        }

        $admin = new Admin;
        $admin->username = 'admin';
        $admin->password = [REDACTED]';
        $admin->status = 1;
        $admin->save();
        $this->info('Ton_log, Create admin success');
    }
}
