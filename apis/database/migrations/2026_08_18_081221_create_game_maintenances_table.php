<?php

declare(strict_types=1);

use App\Facades\SchemaMonitor;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

/**
 * 遊戲維護主表 工單4120
 *
 * 與 vendor_maintenances（遊戲商整商維護）平行的另一套：粒度到單一遊戲，
 * 一張單可跨遊戲商勾任意一批遊戲（實際清單在 game_maintenance_games 子表）。
 *
 * 生效判定沿用既有模式——前台請求時即時比對
 * status=1 AND start_time <= now < end_time，時間到自動生效／恢復，
 * 不依賴排程改狀態（排程掛掉會卡死狀態且無法自我修復）。
 *
 * 不動 games / vendor_customized_items 的 status：維護狀態是
 * 「代理 × 遊戲 × 時間」的查詢結果，塞不進單一欄位（各線可各自維護）。
 */
return new class extends Migration
{
    public function up(): void
    {
        SchemaMonitor::create('game_maintenances', function (Blueprint $table) {
            $table->comment('遊戲維護主表（粒度到單一遊戲，可跨遊戲商） 工單4120');
            $table->increments('id')->comment('PK流水號');
            // 本表獨立定義，與 vendor_maintenances 的 type 編號不同義（該表 1=遊戲+錢包）
            $table->tinyInteger('type')->default(1)->comment('關閉類型 1:關閉遊戲');
            $table->dateTime('start_time')->comment('開始時間(分鐘粒度)');
            $table->dateTime('end_time')->comment('結束時間(分鐘粒度)');
            $table->integer('agent_id')->comment('維護代理ID(沿 hierarchy 對下線生效)');
            $table->integer('exclude_agent_id')->default(0)->comment('排除代理ID');
            $table->tinyInteger('status')->default(1)->comment('狀態 0:停用 1:啟用');
            $table->tinyInteger('unlock_type')->default(1)->comment('解鎖方式 1:自動(結束時間到自動解鎖) 2:手動(保留欄位，4120先只做自動)');
            $table->text('memo')->nullable()->comment('備註');
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->comment('更新日期');
            $table->integer('admin')->default(0)->comment('更新者ID');

            // 前台掃「生效中」維護單用（子表以 game_id 索引 join 回來）
            $table->index(['status', 'end_time'], 'idx_game_maintenances_status_end');
        });
    }

    public function down(): void
    {
        SchemaMonitor::dropIfExists('game_maintenances');
    }
};
