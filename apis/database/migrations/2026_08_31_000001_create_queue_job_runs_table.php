<?php

declare(strict_types=1);

use App\Facades\SchemaMonitor;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

/**
 * Queue job 工作單位完成標記表 工單4257
 *
 * 起源：2026-08-28 節點汰換使整點排程被跳過、summary_bet_records 漏整個
 * hour bucket 且全程靜默。彙總改為 Queue 化後，需要「該工作單位已成功完成」
 * 的明確標記作為回看補洞的判定依據——不能以業務表是否有列判定：該小時
 * 無注單時本來就沒有列。
 *
 * 設計為通用表（MR !2080 審查意見）：job_key 區分 job、work_key 與
 * bucket_time 的語意由各 job 自行定義（SBR：work_key = vendor_id、
 * bucket_time = UTC 整點）。接入的 job 必須把標記寫在與業務資料同一個
 * DB 交易內，rollback（含 dry-run）時標記一併消失，不會出現「有標記無資料」。
 * 首個使用者：SummarizeBetRecordHour（SBR 整點彙總）。
 *
 * job_key／work_key 用 utf8mb4_bin：判缺是「SQL 撈列 → PHP 字串比對」兩段式，
 * 預設 utf8mb4_unicode_ci 的 PAD SPACE（尾空白視為相等）與大小寫不敏感會讓
 * 兩段給出相反答案（DB 判已完成、PHP 判缺格 → 永久誤報且不自癒）；bin 讓
 * SQL 相等嚴格等於 PHP 相等（byte 比對），整類問題結構性消失。
 */
return new class extends Migration
{
    public function up(): void
    {
        SchemaMonitor::create('queue_job_runs', function (Blueprint $table) {
            $table->comment('Queue job 工作單位完成標記（回看補洞/稽核判定依據，job_key 區分）');
            $table->increments('id')->comment('PK流水號');
            $table->string('job_key', 50)->collation('utf8mb4_bin')->comment('job 識別鍵(QueueJobKeyEnum，SBR=summary_bet_record)');
            $table->string('work_key', 50)->collation('utf8mb4_bin')->comment('工作單位鍵(語意由各 job 定義，SBR=vendor_id；必須為 canonical 字串、無前後空白)');
            $table->dateTime('bucket_time')->comment('工作單位時間格起點(UTC)');
            $table->string('source', 20)->comment('觸發來源 schedule:整點排程 backfill:回看補洞 manual:CLI/後台手動 preseed:部署預塞');
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->comment('更新日期');

            $table->unique(['job_key', 'work_key', 'bucket_time'], 'uk_job_work_bucket');
            // 回看／稽核的判缺查詢（job 等值 + bucket_time 範圍 + work_key IN）的 covering index；
            // 「只帶 bucket 範圍不帶 work_key」的形狀也由此索引服務（uk 的欄序吃不到）
            $table->index(['job_key', 'bucket_time', 'work_key'], 'idx_job_bucket_work');
        });
    }

    public function down(): void
    {
        SchemaMonitor::dropIfExists('queue_job_runs');
    }
};
