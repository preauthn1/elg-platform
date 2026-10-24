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
        SchemaMonitor::table('agent_cash_flows', function (Blueprint $table) {
            $table->unsignedTinyInteger('cash_flow_type')
                ->nullable()
                ->after('cash_flow_id')
                ->comment('支付類別（繼承 cash_flows.type 1:線上支付,2:自助開分,3:代付,4:自助洗分）；便利欄，權威=cash_flows.type；NULL=尚未同步（舊系統寫入），由排程收斂');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::table('agent_cash_flows', function (Blueprint $table) {
            $table->dropColumn('cash_flow_type');
        });
    }
};
