<?php

declare(strict_types=1);

use App\Facades\SchemaMonitor;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 金流訂單的三方往返紀錄表（工單 target/tickets#4446、target/tickets#4449）。
 *
 * 要解決兩件事：
 * 1. `orders` 沒有欄位可以存三方往返——`extra_content` 會原封不動回傳給會員 App（TngPin 還拿
 *    `extra_content->pin` 當查詢條件），`remark` 由共用 trait 統一寫、一筆訂單只有一個欄位；
 *    而 `orders` 本身是千萬列等級的熱表，不該為了日誌加欄位。
 * 2. 回調被防線擋下時訂單上看不出來——依工單需求 1，被擋一律不動訂單狀態、金額、點數
 *    （不可信的輸入不該動得了狀態），所以訂單停在 CREATED，跟「玩家根本沒付」長得一樣；
 *    對不到訂單的回調連訂單都沒有，更是沒地方留。
 *
 * 🔴 **append-only**：只 insert，禁止 update／delete（清理一律整批刪）。日誌被改過就失去證據價值。
 * 🔴 **存原文、不遮罩**：出事時要能證明我方送了什麼、三方回了什麼，遮了就失去意義。
 *    代價是本表含**明文商戶金鑰**與回調 `details` 裡的玩家轉帳憑證連結，故
 *    **禁止經任何 API／後台頁面暴露、禁止匯出**。CLAUDE.md 的遮罩紅線講的是 log channel
 *    （理由寫明「log channel 比 DB 廣」），DB 不在其射程內。
 * 🔴 **寫入在交易外**：寫在交易內的話，回調被拒絕或 rollback 會連紀錄一起沒，剛好丟掉最想留的那筆。
 *
 * 🔴 **本表只收金流往返（代收 `orders`、代付 `withdrawal_payout_records`），禁止寫入遊戲商往返。**
 * 遊戲商的注單回調量比金流高好幾個數量級，混進來會同時拖垮這張表的查詢與保留期；
 * `orderable` 雖然是多型關聯，但 morph alias 僅限 `deposit_order` / `payout_record`
 * （定義在 AppServiceProvider），要記遊戲商往返一律另開表。
 *
 * 保留期與清理排程本次不做（目前只有 Ap8Pay 寫入，量體極小）；擴及其他金流商前要先補。
 */
return new class extends Migration
{
    public function up(): void
    {
        SchemaMonitor::create('order_request_logs', function (Blueprint $table): void {
            $table->bigIncrements('id');

            // 多型關聯：代收指 orders、代付指 withdrawal_payout_records（代付掛 payout record
            // 而非提款單，因為一張提款單可能有多次代付請求，一次往返對應其中一次）。
            // 🔴 **兩張表的 id 空間重疊**（實測 TPower：orders 到 2030 萬、payout record 到 186 萬），
            // 所以查詢一律連 type 一起比，只靠 id 會互相撈到對方的紀錄。
            // 🔴 type 一律有值（對不到訂單時由 action 推導），只有 id 可以是 NULL：
            // 查詢會先比型別再比三個識別碼，type 留空的話被擋的回調會被整個濾掉。
            $table->string('orderable_type')
                ->comment('morph alias：deposit_order / payout_record');

            $table->unsignedBigInteger('orderable_id')
                ->nullable()
                ->comment('對應 orders.id 或 withdrawal_payout_records.id；回調對不到時為 NULL');

            $table->string('order_no')
                ->nullable()
                ->comment('我方單號：代收＝orders.order_no，代付＝withdrawal_payout_records.transaction_no');

            $table->string('third_party_order_no')
                ->nullable()
                ->comment('三方訂單號：代收＝orders.third_party_order_no，代付＝withdrawal_payout_records.third_party_transaction_no');

            $table->string('action')
                ->comment('往返類型，見 OrderRequestActionEnum：deposit_* / withdrawal_*');

            $table->string('url')
                ->comment('出站＝我方打的三方端點；入站＝三方打的我方網址');

            $table->string('source_ip')
                ->nullable()
                ->comment('對端 IP：入站＝三方打來的來源 IP、出站＝三方伺服器 IP');

            $table->smallInteger('http_status')
                ->nullable()
                ->comment('HTTP 狀態：出站＝三方回我方的、入站＝我方回三方的');

            $table->text('request_headers')->nullable()->comment('請求標頭原文（不遮罩）');
            $table->text('request_body')->nullable()->comment('請求內容原文（不遮罩）');
            $table->text('response_body')->nullable()->comment('響應內容原文（不遮罩）');

            $table->string('result')
                ->nullable()
                ->comment('我方對這次往返的判定：ok 或 failed:<原因>');

            $table->timestamps();

            // 複合索引：查某張訂單的往返一律帶 type，單獨用 id 查會跨型別撈錯
            $table->index(['orderable_type', 'orderable_id']);
            $table->index('order_no');
            $table->index('third_party_order_no');
            $table->index('created_at');
        });

        // SchemaMonitor 靜默跳過防護：斷言建表真的生效，否則拋錯不讓 migration 被記為完成
        if (!Schema::hasColumn('order_request_logs', 'result')) {
            throw new RuntimeException('order_request_logs 未建立（SchemaMonitor 可能靜默跳過），中止');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('order_request_logs');
    }
};
