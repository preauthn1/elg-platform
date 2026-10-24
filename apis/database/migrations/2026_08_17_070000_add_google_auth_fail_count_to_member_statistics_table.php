<?php

declare(strict_types=1);

use App\Facades\SchemaMonitor;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

/**
 * 會員 Google 2FA 驗證錯誤次數 target/tickets#4146
 *
 * 與既有的 login_fail_count / payment_pwd_fail_count / validation_code_fail_count 同表同慣例：
 * 驗證失敗累加、驗證成功歸零，達代理設定的上限即鎖定帳號並登出。
 */
return new class extends Migration
{
    public function up(): void
    {
        SchemaMonitor::table('member_statistics', function (Blueprint $table) {
            if (!SchemaMonitor::hasColumn('member_statistics', 'google_auth_fail_count')) {
                $table->integer('google_auth_fail_count')
                    ->nullable()
                    ->default(0)
                    ->after('validation_code_fail_count')
                    ->comment('Google驗證錯誤次數');
            }
        });
    }

    public function down(): void
    {
        SchemaMonitor::table('member_statistics', function (Blueprint $table) {
            if (SchemaMonitor::hasColumn('member_statistics', 'google_auth_fail_count')) {
                $table->dropColumn('google_auth_fail_count');
            }
        });
    }
};
