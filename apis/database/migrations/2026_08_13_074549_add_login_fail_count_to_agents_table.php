<?php

declare(strict_types=1);

use App\Facades\SchemaMonitor;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        SchemaMonitor::table('agents', function (Blueprint $table) {
            $table->unsignedSmallInteger('login_fail_count')->default(0)->after('sys_lock')->comment('連續登入密碼錯誤次數；登入成功或解除限制時歸零');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::table('agents', function (Blueprint $table) {
            $table->dropColumn('login_fail_count');
        });
    }
};
