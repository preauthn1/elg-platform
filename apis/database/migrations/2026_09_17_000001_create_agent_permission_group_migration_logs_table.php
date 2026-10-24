<?php

declare(strict_types=1);

use App\Facades\SchemaMonitor;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 預設角色分線遷移（target/op_tickets#3168）的異動備份表。
//
// 補丁 patch:split-default-agent-permission-group 會把代理的 agent_permission_group_id
// 從「全庫唯一的全域預設角色」改掛到「所屬總代自己的預設角色」。該欄位是單一 int，
// 改掉就沒有任何地方留著原值——沒有備份就無法回滾，也無從稽核當初是從哪一個角色搬過來的。
//
// 本表只在補丁執行時寫入，一列對應一個被改掛的代理；補丁的 --rollback 以它為唯一依據還原。
// 不做外鍵：回滾要讀的是「當時的原值」，即使該角色之後被刪除，這列仍須保留可讀。
return new class extends Migration
{
    public function up(): void
    {
        SchemaMonitor::create('agent_permission_group_migration_logs', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('batch', 64)->comment('執行批次代碼，同一次補丁共用；回滾以它為單位');
            $table->integer('agent_id')->comment('被改掛的代理 agents.id');
            $table->integer('from_group_id')->nullable()->comment('異動前的 agent_permission_group_id，NULL=原本未掛角色');
            $table->integer('to_group_id')->nullable()->comment('異動後的 agent_permission_group_id');
            $table->integer('top_agent_id')->comment('判定出的所屬總代 agents.id（由近而遠取最近的 role_id=11）');
            $table->tinyInteger('rolled_back')->default(0)->comment('是否已回滾 0:否 1:是');
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->comment('更新日期');

            // 回滾：以批次撈出該次所有異動
            $table->index(['batch', 'rolled_back'], 'idx_batch_rolled_back');
            // 稽核：反查某個代理的異動歷程
            $table->index('agent_id', 'idx_agent_id');
        });

        // SchemaMonitor 靜默跳過防護：建表未生效就拋錯，不讓 migration 被記為完成
        if (!Schema::hasTable('agent_permission_group_migration_logs')) {
            throw new RuntimeException('agent_permission_group_migration_logs 未建立（SchemaMonitor 可能靜默跳過），中止');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_permission_group_migration_logs');
    }
};
