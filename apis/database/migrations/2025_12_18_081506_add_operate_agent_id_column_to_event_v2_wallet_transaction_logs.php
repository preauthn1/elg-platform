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
        SchemaMonitor::table('event_v2_wallet_transaction_logs', function (Blueprint $table) {
            $table->unsignedInteger('operate_agent_id')->nullable()->default(null)->after('event_v2_user_join_id')->comment('操作代理id')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::table('event_v2_wallet_transaction_logs', function (Blueprint $table) {
            $table->dropColumn('operate_agent_id');
        });
    }
};
