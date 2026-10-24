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
        SchemaMonitor::table('agent_cash_flow_order_types', function (Blueprint $table) {
            if (!SchemaMonitor::hasColumn('agent_cash_flow_order_types', 'is_recommend')) {
                $table->boolean('is_recommend')->default(0)->comment('是否推薦')->after('backend_display');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::table('agent_cash_flow_order_types', function (Blueprint $table) {
            if (SchemaMonitor::hasColumn('agent_cash_flow_order_types', 'is_recommend')) {
                $table->dropColumn('is_recommend');
            }
        });
    }
};
