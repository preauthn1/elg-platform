<?php

declare(strict_types=1);

use App\Facades\SchemaMonitor;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

return new class extends Migration
{
    public function up(): void
    {
        SchemaMonitor::table('members', function (Blueprint $table) {
            if (!SchemaMonitor::hasColumn('members', 'payment_pwd_fail_count')) {
                $table->integer('payment_pwd_fail_count')->default(0)->after('forget_pwd_count')->comment('支付密碼錯誤次數');
            }
            if (!SchemaMonitor::hasColumn('members', 'validation_code_fail_count')) {
                $table->integer('validation_code_fail_count')->default(0)->after('payment_pwd_fail_count')->comment('驗證碼錯誤次數');
            }
        });
    }

    public function down(): void
    {
        SchemaMonitor::table('members', function (Blueprint $table) {
            if (SchemaMonitor::hasColumn('members', 'payment_pwd_fail_count')) {
                $table->dropColumn('payment_pwd_fail_count');
            }
            if (SchemaMonitor::hasColumn('members', 'validation_code_fail_count')) {
                $table->dropColumn('validation_code_fail_count');
            }
        });
    }
};
