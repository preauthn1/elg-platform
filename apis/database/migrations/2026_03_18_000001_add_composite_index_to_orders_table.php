<?php

declare(strict_types=1);

use App\Facades\SchemaMonitor;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

return new class extends Migration
{
    public function up(): void
    {
        SchemaMonitor::table('orders', function (Blueprint $table) {
            $table->index(
                ['member_id', 'agent_cash_flow_id', 'status', 'id'],
                'idx_member_agent_status_id'
            );
        });
    }

    public function down(): void
    {
        SchemaMonitor::table('orders', function (Blueprint $table) {
            $table->dropIndex('idx_member_agent_status_id');
        });
    }
};
