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
        if (!SchemaMonitor::hasTable('agent_cash_flows')) {
            // table doesn't exist - nothing to do
            return;
        }

        SchemaMonitor::table('agent_cash_flows', function (Blueprint $table) {
            if (!SchemaMonitor::hasColumn('agent_cash_flows', 'currency_id')) {
                // add unsigned integer currency_id (nullable) with index
                $table->unsignedInteger('currency_id')->nullable()->after('agent_group_id');
                $table->index('currency_id', 'idx_agent_cash_flows_currency_id');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (!SchemaMonitor::hasTable('agent_cash_flows')) {
            return;
        }

        SchemaMonitor::table('agent_cash_flows', function (Blueprint $table) {
            if (SchemaMonitor::hasColumn('agent_cash_flows', 'currency_id')) {
                // Drop index then column
                try {
                    $table->dropIndex('idx_agent_cash_flows_currency_id');
                } catch (Exception $e) {
                    // ignore if index not exists
                }

                $table->dropColumn('currency_id');
            }
        });
    }
};
