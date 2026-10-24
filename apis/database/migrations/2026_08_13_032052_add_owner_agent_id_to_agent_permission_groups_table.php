<?php

declare(strict_types=1);

use App\Facades\SchemaMonitor;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

/**
 * 代理群組（權限角色）掛所屬總代 target/tickets#4080
 *
 * 角色改為分線管理：只有同一條線路（同總代）底下的代理才能套用該角色，
 * 避免跨線誤用。總代以 roles 表的「總代理」（role_id=11）判定，沿
 * agents.hierarchy_path 由近而遠取最近的祖先——不可用 path 深度，
 * 各站深度不一（TPower 深度 2、VWorld 深度 3）且 VWorld 有巢狀總代。
 *
 * NULL = 全域角色，不限線路。升級時建立的「預設角色」即為此類，
 * 供各線在尚未建立自己的角色前沿用；Admin 與其子帳號不受線路限制。
 */
return new class extends Migration
{
    public function up(): void
    {
        SchemaMonitor::table('agent_permission_groups', function (Blueprint $table) {
            if (!SchemaMonitor::hasColumn('agent_permission_groups', 'owner_agent_id')) {
                $table->unsignedBigInteger('owner_agent_id')
                    ->nullable()
                    ->default(null)
                    ->after('name')
                    ->comment('所屬總代 agents.id，NULL=全域角色不限線路 工單4080');

                $table->index('owner_agent_id', 'idx_agent_permission_groups_owner');
            }
        });
    }

    public function down(): void
    {
        SchemaMonitor::table('agent_permission_groups', function (Blueprint $table) {
            if (SchemaMonitor::hasColumn('agent_permission_groups', 'owner_agent_id')) {
                $table->dropIndex('idx_agent_permission_groups_owner');
                $table->dropColumn('owner_agent_id');
            }
        });
    }
};
