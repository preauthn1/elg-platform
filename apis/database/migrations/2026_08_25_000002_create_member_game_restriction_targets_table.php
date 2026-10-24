<?php

declare(strict_types=1);

use App\Facades\SchemaMonitor;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

/**
 * 會員遊戲限制－受限標的清單 工單4126
 *
 * target_id 依主表的 restrict_type 解讀：1 存 vendors.id、2 存 games.id。
 * 不拆成兩張表：兩者結構相同，且一張限制單只會是其中一種類型。
 *
 * 遊戲商類型刻意「不展開成遊戲」——前台遊戲商層級本就有獨立判定
 * （lobby/vendors 走 getMaintenanceVendorIds），存 vendor_id 即可，
 * 該商日後新增的遊戲也會自動納入限制。
 */
return new class extends Migration
{
    public function up(): void
    {
        SchemaMonitor::create('member_game_restriction_targets', function (Blueprint $table) {
            $table->comment('會員遊戲限制-受限的遊戲商或遊戲 工單4126');
            $table->increments('id')->comment('PK流水號');
            $table->unsignedInteger('restriction_id')->comment('member_game_restrictions.id');
            $table->unsignedInteger('target_id')->comment('受限標的：restrict_type=1 為 vendors.id、=2 為 games.id');
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->comment('更新日期');
            $table->unique(['restriction_id', 'target_id'], 'uk_member_game_restriction_target');
            // 前台以 target_id 反查涵蓋的限制單
            $table->index('target_id', 'idx_member_game_restriction_targets_target');
        });
    }

    public function down(): void
    {
        SchemaMonitor::dropIfExists('member_game_restriction_targets');
    }
};
