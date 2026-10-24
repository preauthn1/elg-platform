<?php

declare(strict_types=1);

use App\Facades\SchemaMonitor;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 漂移比對基準：某個監測對象的「上次看到的內容」，供下次比對用。
 *
 * 一列 ＝ 某個監測對象（subject_key）在某個範圍（scope_key）下的一份已知內容。
 * 用途是抓「有東西被改掉而沒人發現」——三方在不通知的情況下改設定、我方設定被手動動過，
 * 都只有比對前後兩份才看得出來。
 *
 * 通用表，不綁任何單一功能。首個監測對象是 FPay 代付銀行清單，日後別的三方設定或我方設定
 * 要做同樣的守護，新增一個 DriftSubjectEnum case 即可接入，不必再開一張表。
 *
 * 只在內容與上一列不同時才新增一列，內容沒變的日子不寫入；所以列數反映的是
 * 「改過幾次」而不是「跑過幾次」。
 *
 * subject_key／scope_key 用 utf8mb4_bin：判斷邏輯是「SQL 撈列 → PHP 端比對內容」兩段式，
 * 預設 utf8mb4_unicode_ci 的 PAD SPACE（尾空白視為相等）與大小寫不敏感會讓兩段對同一列
 * 給出相反答案（DB 撈得到、PHP 判不相等），bin 讓 SQL 相等嚴格等於 PHP 相等。
 */
return new class extends Migration
{
    private const TABLE = 'drift_baselines';

    public function up(): void
    {
        SchemaMonitor::create(self::TABLE, function (Blueprint $table) {
            $table->comment('漂移比對基準（某監測對象的已知內容，供下次比對）');
            $table->increments('id')->comment('流水號');
            $table->string('subject_key', 50)->collation('utf8mb4_bin')->comment('監測對象(DriftSubjectEnum)；須為 canonical 字串、無前後空白');
            $table->string('scope_key', 50)->collation('utf8mb4_bin')->comment('範圍鍵(語意由各監測對象自訂，FPay 銀行清單=代付設定 id)；須為 canonical 字串、無前後空白');
            $table->string('scope_label', 50)->nullable()->comment('範圍的人可讀標籤，只供閱讀、不參與比對');
            $table->unsignedInteger('item_count')->nullable()->comment('內容筆數；內容不是清單時為 NULL');
            $table->json('payload')->comment('已知內容本身');
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            // 查詢形狀固定是「某對象某範圍的最新一列」，三欄合起來即 covering，不必回表
            $table->index(['subject_key', 'scope_key', 'id'], 'idx_drift_baselines_subject_scope');
        });

        // SchemaMonitor 的保護是靜默跳過、不拋錯（表已存在即 return），migration 仍會被記為已執行。
        // 沒有這段斷言，就會出現「migration 顯示已跑、表卻不存在」而無人察覺。
        if (!Schema::hasTable(self::TABLE)) {
            throw new RuntimeException(sprintf('%s 建表未生效，請檢查 SchemaMonitor 是否靜默跳過', self::TABLE));
        }
    }

    public function down(): void
    {
        SchemaMonitor::dropIfExists(self::TABLE);
    }
};
