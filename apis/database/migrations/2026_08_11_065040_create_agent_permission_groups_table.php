<?php

declare(strict_types=1);

use App\Facades\SchemaMonitor;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

/**
 * 代理群組（權限角色）主檔 target/tickets#4080
 *
 * 管理權限由選單控制（僅 admin／總代可管理）。
 * 代理最終權限 = 個人權限(agent_permissions) ∪ 角色權限(agent_permission_group_menus)。
 *
 * 角色的適用線路見後續 migration 加入的 owner_agent_id 欄位。
 */
return new class extends Migration
{
    public function up(): void
    {
        SchemaMonitor::create('agent_permission_groups', function (Blueprint $table) {
            $table->bigIncrements('id')->comment('流水號');
            $table->string('name', 50)->comment('角色名稱');
            $table->string('remark', 191)->nullable()->comment('備註');
            $table->tinyInteger('status')->default(1)->comment('狀態 0:關閉 1:啟用');
            $table->tinyInteger('default_setting')->default(0)->comment('是否預設群組 0:否 1:是（建立代理時下拉預選，非強制）');
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->comment('更新日期');
            $table->integer('admin')->default(0)->comment('更新者ID');

            $table->index('status', 'idx_agent_permission_groups_status');
        });
    }

    public function down(): void
    {
        SchemaMonitor::dropIfExists('agent_permission_groups');
    }
};
