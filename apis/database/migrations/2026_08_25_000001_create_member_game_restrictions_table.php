<?php

declare(strict_types=1);

use App\Facades\SchemaMonitor;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

/**
 * 會員遊戲限制主表 工單4126
 *
 * 與 4120 的 game_maintenances（代理層級的遊戲維護）平行但語意不同：
 * 4120 是「這款遊戲整條線暫時維護」，本表是「這些會員永久不能玩這些遊戲」。
 * 兩者可同時生效，前台判定各自併入 VendorService 既有的兩支查詢
 * （遊戲商層級 getMaintenanceVendorIds、遊戲層級 getMaintenanceGameMap），
 * 前端六個皮膚零改動。
 *
 * 刻意不設 start_time / end_time / unlock_type：本表為永久限制，
 * 解除即停用或刪除該筆。前台回傳的 maintenance_end_time 為 null，
 * 皮膚的 v-if="isMaintenance && maintenanceEndTime" 會只灰化、不顯示恢復時間。
 */
return new class extends Migration
{
    public function up(): void
    {
        SchemaMonitor::create('member_game_restrictions', function (Blueprint $table) {
            $table->comment('會員遊戲限制主表（永久限制，粒度到會員 × 遊戲商/遊戲） 工單4126');
            $table->increments('id')->comment('PK流水號');
            $table->tinyInteger('restrict_type')->comment('限制類型 1:遊戲商 2:遊戲');
            $table->tinyInteger('target_scope')->comment('對象範圍 1:指定會員 2:會員群組');
            $table->integer('agent_id')->comment('建立此限制的代理ID(沿 hierarchy 對下線生效)');
            $table->tinyInteger('status')->default(1)->comment('狀態 0:停用 1:啟用');
            $table->text('memo')->nullable()->comment('備註');
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->comment('更新日期');
            $table->integer('admin')->default(0)->comment('更新者ID');
            // 前台每次請求都要查啟用中的限制，以狀態與代理為主要過濾條件
            $table->index(['status', 'agent_id'], 'idx_member_game_restrictions_status_agent');
        });
    }

    public function down(): void
    {
        SchemaMonitor::dropIfExists('member_game_restrictions');
    }
};
