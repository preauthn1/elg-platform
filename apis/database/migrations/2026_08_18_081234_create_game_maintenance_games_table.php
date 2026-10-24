<?php

declare(strict_types=1);

use App\Facades\SchemaMonitor;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

/**
 * 遊戲維護涵蓋的遊戲清單（子表） 工單4120
 *
 * 只存 game_id：games.id 全域唯一，遊戲商與類型由 join games 推導，
 * 不另存 vendor_id / game_type_id 以免與 games 不一致。
 * 後台表單的「遊戲商／類型」勾選只是縮小遊戲清單的過濾器，不落地。
 */
return new class extends Migration
{
    public function up(): void
    {
        SchemaMonitor::create('game_maintenance_games', function (Blueprint $table) {
            $table->comment('遊戲維護-涵蓋遊戲清單 工單4120');
            $table->increments('id')->comment('PK流水號');
            $table->unsignedInteger('game_maintenance_id')->comment('game_maintenances.id');
            $table->unsignedInteger('game_id')->comment('games.id');
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->comment('更新日期');

            $table->unique(['game_maintenance_id', 'game_id'], 'uk_game_maintenance_game');
            // 前台以當頁 game_id 反查生效維護用
            $table->index('game_id', 'idx_game_maintenance_games_game');
        });
    }

    public function down(): void
    {
        SchemaMonitor::dropIfExists('game_maintenance_games');
    }
};
