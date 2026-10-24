<?php

declare(strict_types=1);

use App\Facades\SchemaMonitor;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

/**
 * 代理群組（權限角色）× 選單權限明細 target/tickets#4080
 *
 * 結構對齊既有 agent_permissions（agent_menu_id + action），
 * 差異僅在主體由 agent_id 換成 agent_permission_group_id。
 * action 與個人權限衝突時取聯集（例：個人 view + 角色 view,update → view,update）。
 */
return new class extends Migration
{
    public function up(): void
    {
        SchemaMonitor::create('agent_permission_group_menus', function (Blueprint $table) {
            $table->bigIncrements('id')->comment('流水號');
            $table->unsignedBigInteger('agent_permission_group_id')->comment('代理群組ID (agent_permission_groups.id)');
            $table->integer('agent_menu_id')->comment('選單ID (agent_menus.id)');
            $table->string('action', 64)->default('')->comment('操作權限 view/update/delete，空字串=不限');
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->comment('更新日期');
            $table->integer('admin')->default(0)->comment('更新者ID');

            $table->unique(['agent_permission_group_id', 'agent_menu_id'], 'uk_group_menu');
            $table->index('agent_menu_id', 'idx_agent_permission_group_menus_menu_id');
        });
    }

    public function down(): void
    {
        SchemaMonitor::dropIfExists('agent_permission_group_menus');
    }
};
