<?php

declare(strict_types=1);

use App\Facades\SchemaMonitor;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 單錢包遊戲商注單表補 bet_time 索引（未結算注單報表 WHERE bet_time 區間查詢用，
 * target/tickets#4295）。
 *
 * 冪等與大表防線：已有 bet_time 獨立索引（按欄位比對、不論名稱）即跳過；生產環境
 * 表超過 50 萬列且缺索引時，SchemaMonitor 會取消操作並發 TG 通知轉人工——改走
 * Olympus 後台「資料表加索引／加欄位」（schema_operation）的鏡像表回填＋原子換表
 * 流程（SchemaOperationService）。索引名 {table}_bet_time_index 與該流程換表轉正
 * 後的索引名一致，兩條路徑建出的結果相同，先後執行互不重複。
 *
 * 刻意排除 fumasw / uuslotsw / wudisw 三張注單表（2026-09-17 決議）：TPower／
 * Yes2Win 站數百萬～5 千萬列，SchemaMonitor 防線會取消建立但 migration 仍記為已
 * 執行，等於把「未建」固化成假象；且三家的未結算掃描皆不靠 bet_time 索引——
 * fumasw／wudisw 掃描窗改用既有 payout_time 索引（下注當下即寫入、結算前不變），
 * uuslotsw 判定直接走 status='bet' 索引（bet_time 僅作窗過濾條件）。三家維持
 * 站間漂移（已手動建的站保留），現況紀錄於 單錢包注單表_bet_time索引盤點.xlsx。
 */
return new class extends Migration
{
    private const array TABLES = [
        'besoftsw_bet_records',
        'bolesw_bet_records',
        'clotlssw_bet_records',
        'clotp2psw_bet_records',
        'clotsw_bet_records',
        'crsw_bet_records',
        'dstplaysw_bet_records',
        'epic_sw_bet_records',
        'fffsw_bet_records',
        'impsw_bet_records',
        'megawinsw_bet_records',
        'ptsw_bet_records',
        'sexyslotsw_bet_records',
        'slot4dsw_bet_records',
        'spadegamingsw_bet_records',
        'tfgamingsw_bet_records',
        'vplussw_bet_records',
        'vpowersw_bet_records',
        'wcasinosw_bet_records',
        'wesw_bet_records',
        'wmlivesw_bet_records',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $tableName) {
            if (!Schema::hasTable($tableName) || Schema::hasIndex($tableName, ['bet_time'])) {
                continue;
            }

            SchemaMonitor::table($tableName, function (Blueprint $table) use ($tableName) {
                $table->index('bet_time', $tableName . '_bet_time_index');
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $tableName) {
            if (!Schema::hasTable($tableName) || !Schema::hasIndex($tableName, $tableName . '_bet_time_index')) {
                continue;
            }

            SchemaMonitor::table($tableName, function (Blueprint $table) use ($tableName) {
                $table->dropIndex($tableName . '_bet_time_index');
            });
        }
    }
};
