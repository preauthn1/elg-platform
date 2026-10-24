<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 為 SP_calc_event_progressive_jackpot_bet_records（target/op_tickets#3282）建立
 * summary_control_tables 的水位控制列（vendor_id=30001，功能代碼，非真實遊戲商編號；
 * 比照 SP_calc_flow_limit 用 20001 的既有慣例）。
 *
 * stat_end_id 種在「這支 migration 實際執行當下的 total_bet_records 最大 id」，不是 0 或 NULL：
 * 如果種成 0，第一次正式排程執行時會把 total_bet_records 的全部歷史流水（可能長達數年）一次性
 * 套用給所有目前有進行中/等待解鎖檔位的會員——這些會員多數是最近才參加成長彩金計劃，不該把
 * 加入活動之前的歷史投注也回頭算進解鎖量。改用「migration 執行當下」當起點，第一次排程只會
 * 處理之後新產生的注單，行為上等同於過去每小時排程「只看未來，不回頭」的既有假設。
 *
 * 這個起點選擇的代價：這支 migration 執行之前就已經漏算的舊資料（例如 op_tickets#3282 本身這筆）
 * 不會被這支自動排程追上，需要另外的手動補算機制處理，本次改動未包含。
 */
return new class extends Migration
{
    private const int CONTROL_VENDOR_ID = 30001;

    public function up(): void
    {
        if (DB::table('summary_control_tables')->where('vendor_id', self::CONTROL_VENDOR_ID)->exists()) {
            return;
        }

        DB::table('summary_control_tables')->insert([
            'vendor_id' => self::CONTROL_VENDOR_ID,
            'vendor_code' => 'progressive_jackpot_bet_records',
            'stat_end_id' => DB::table('total_bet_records')->max('id'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('summary_control_tables')->where('vendor_id', self::CONTROL_VENDOR_ID)->delete();
    }
};
