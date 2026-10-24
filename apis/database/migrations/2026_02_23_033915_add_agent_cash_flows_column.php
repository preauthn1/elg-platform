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
            if (!SchemaMonitor::hasColumn('agent_cash_flows', 'backend_display')) {
                $table->boolean('backend_display')->default(1)->comment('後台顯示 0:隱藏, 1:顯示')->after('status');
            }
        });

        SchemaMonitor::table('agent_cash_flow_order_types', function (Blueprint $table) {
            if (!SchemaMonitor::hasColumn('agent_cash_flow_order_types', 'backend_display')) {
                $table->boolean('backend_display')->default(1)->comment('後台顯示 0:隱藏, 1:顯示')->after('status');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::table('agent_cash_flows', function (Blueprint $table) {
            if (SchemaMonitor::hasColumn('agent_cash_flows', 'backend_display')) {
                $table->dropColumn('backend_display');
            }
        });

        SchemaMonitor::table('agent_cash_flow_order_types', function (Blueprint $table) {
            if (SchemaMonitor::hasColumn('agent_cash_flow_order_types', 'backend_display')) {
                $table->dropColumn('backend_display');
            }
        });
    }
};
