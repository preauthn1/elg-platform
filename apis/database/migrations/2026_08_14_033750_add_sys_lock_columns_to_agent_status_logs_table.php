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
        SchemaMonitor::table('agent_status_logs', function (Blueprint $table) {
            $table->tinyInteger('before_sys_lock')->nullable()->after('after_status')->comment('限制登入原始狀態; 1:限制, 0:未限制; NULL:舊資料未記錄（含會員列）');
            $table->tinyInteger('after_sys_lock')->nullable()->after('before_sys_lock')->comment('限制登入變更後; 1:限制, 0:未限制; NULL:舊資料未記錄（含會員列）');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::table('agent_status_logs', function (Blueprint $table) {
            $table->dropColumn(['before_sys_lock', 'after_sys_lock']);
        });
    }
};
