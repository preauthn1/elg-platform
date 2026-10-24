<?php

declare(strict_types=1);

use App\Facades\SchemaMonitor;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

/**
 * agents 掛代理群組（權限角色）target/tickets#4080
 *
 * NULL = 不選擇角色（＝現行行為，只吃個人權限），為相容既有代理與舊後台建立的帳號。
 * 與現役 agents.role_id（1:admin 2:特殊 11:白標管理層 12:客服）語意無關、互不影響。
 * 子帳號（host_id != 0）亦適用本欄位，與主帳號一視同仁。
 */
return new class extends Migration
{
    public function up(): void
    {
        SchemaMonitor::table('agents', function (Blueprint $table) {
            if (!SchemaMonitor::hasColumn('agents', 'agent_permission_group_id')) {
                $table->unsignedBigInteger('agent_permission_group_id')
                    ->nullable()
                    ->default(null)
                    ->after('role_id')
                    ->comment('代理群組ID (agent_permission_groups.id)，NULL=不選擇角色 工單4080');

                $table->index('agent_permission_group_id', 'idx_agents_agent_permission_group_id');
            }
        });
    }

    public function down(): void
    {
        SchemaMonitor::table('agents', function (Blueprint $table) {
            if (SchemaMonitor::hasColumn('agents', 'agent_permission_group_id')) {
                $table->dropIndex('idx_agents_agent_permission_group_id');
                $table->dropColumn('agent_permission_group_id');
            }
        });
    }
};
