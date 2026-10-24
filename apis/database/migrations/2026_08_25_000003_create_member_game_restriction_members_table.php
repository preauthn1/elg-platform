<?php

declare(strict_types=1);

use App\Facades\SchemaMonitor;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

/**
 * 會員遊戲限制－受限對象 工單4126
 *
 * 依主表 target_scope 擇一使用：
 *   1 指定會員 → member_id 有值、member_group_id 為 0
 *   2 會員群組 → member_group_id 有值、member_id 為 0
 *
 * 會員只會屬於單一群組（members.member_group_id 為單一欄位，非多對多），
 * 故群組比對是相等判斷，不需展開成員清單；群組成員異動即時生效。
 */
return new class extends Migration
{
    public function up(): void
    {
        SchemaMonitor::create('member_game_restriction_members', function (Blueprint $table) {
            $table->comment('會員遊戲限制-受限會員或會員群組 工單4126');
            $table->increments('id')->comment('PK流水號');
            $table->unsignedInteger('restriction_id')->comment('member_game_restrictions.id');
            $table->unsignedInteger('member_id')->default(0)->comment('members.id(target_scope=1 時使用)');
            $table->unsignedInteger('member_group_id')->default(0)->comment('member_groups.id(target_scope=2 時使用)');
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->comment('更新日期');
            $table->unique(['restriction_id', 'member_id', 'member_group_id'], 'uk_member_game_restriction_member');
            // 前台以登入會員 id / 其群組 id 反查適用的限制單
            $table->index('member_id', 'idx_member_game_restriction_members_member');
            $table->index('member_group_id', 'idx_member_game_restriction_members_group');
        });
    }

    public function down(): void
    {
        SchemaMonitor::dropIfExists('member_game_restriction_members');
    }
};
