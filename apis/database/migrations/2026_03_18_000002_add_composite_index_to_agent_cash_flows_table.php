<?php

declare(strict_types=1);

use App\Facades\SchemaMonitor;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

return new class extends Migration
{
    public function up(): void
    {
        SchemaMonitor::table('agent_cash_flows', function (Blueprint $table) {
            $table->index(
                ['agent_group_id', 'cash_flow_id'],
                'idx_agent_group_cash_flow'
            );
        });
    }

    public function down(): void
    {
        SchemaMonitor::table('agent_cash_flows', function (Blueprint $table) {
            $table->dropIndex('idx_agent_group_cash_flow');
        });
    }
};
