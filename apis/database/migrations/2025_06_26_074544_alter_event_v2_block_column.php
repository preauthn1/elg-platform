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
        // 請使用 SchemaMonitor 操作避免線上環境炸掉
        SchemaMonitor::table('event_v2_details', function (Blueprint $table) {
            if (!SchemaMonitor::hasColumn('event_v2_details', 'ip_block')) {
                $table->boolean('ip_block')->default(false)->after('withdraw_config')->comment('是否阻擋重複IP');
            }
            if (!SchemaMonitor::hasColumn('event_v2_details', 'phone_block')) {
                $table->boolean('phone_block')->default(false)->after('ip_block')->comment('是否阻擋重複手機號');
            }
            if (!SchemaMonitor::hasColumn('event_v2_details', 'account_block')) {
                $table->boolean('account_block')->default(false)->after('phone_block')->comment('是否阻擋重複真實姓名');
            }
        });

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // 請使用 SchemaMonitor 操作避免線上環境炸掉
        SchemaMonitor::table('event_v2_details', function (Blueprint $table) {
            if (SchemaMonitor::hasColumn('event_v2_details', 'ip_block')) {
                $table->dropColumn('ip_block');
            }
            if (SchemaMonitor::hasColumn('event_v2_details', 'phone_block')) {
                $table->dropColumn('phone_block');
            }
            if (SchemaMonitor::hasColumn('event_v2_details', 'account_block')) {
                $table->dropColumn('account_block');
            }
        });
    }
};
