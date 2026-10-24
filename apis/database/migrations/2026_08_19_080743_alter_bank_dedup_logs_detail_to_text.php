<?php

declare(strict_types=1);

use App\Facades\SchemaMonitor;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

/**
 * bank_dedup_logs.detail 由 varchar(255) 改為 text。
 *
 * 255 裝不下逐列審計所需的完整 before/after manifest：實測完整 envelope 在
 * bank_name 含 50 個需 escape 字元時達 272 字元、含控制字元時達 472 字元
 * （withdrawal_orders.bank_name 上限 50、bank_code 上限 16、id 可達 10 位）。
 * 現行 audit() 超限即拋例外，等於讓合法收斂無法執行。
 */
return new class extends Migration
{
    public function up(): void
    {
        SchemaMonitor::table('bank_dedup_logs', function (Blueprint $table): void {
            $table->text('detail')->nullable()->comment('審計明細 JSON（含逐列 before/after manifest）')->change();
        });

        $this->assertDetailType('text');
    }

    /**
     * 只有在現有資料都塞得回 varchar(255) 時才還原欄型。
     *
     * 有任一列超過 255 就中止：MySQL 的 DDL 不進交易，一旦縮短欄型即就地截斷 JSON，
     * 得到解不開又救不回的審計列。此時的正確處置是人工評估後手動處理。
     */
    public function down(): void
    {
        $maxLength = (int) (DB::selectOne('SELECT MAX(CHAR_LENGTH(detail)) AS max_length FROM bank_dedup_logs')->max_length ?? 0);

        if ($maxLength > 255) {
            throw new RuntimeException(sprintf(
                'bank_dedup_logs.detail 最長為 %d 字元，縮回 varchar(255) 會截斷審計 manifest；請人工評估後處理。',
                $maxLength
            ));
        }

        SchemaMonitor::table('bank_dedup_logs', function (Blueprint $table): void {
            $table->string('detail', 255)->nullable()->comment('補充說明（如同步出款訂單筆數）')->change();
        });

        $this->assertDetailType('varchar');
    }

    /**
     * 確認欄型真的改了。
     *
     * SchemaMonitor::table() 在 production 遇到列數超限或列數查詢失敗時會直接 return，
     * 而 Laravel 仍會把這支 migration 記為已執行——欄位留在原型別、程式卻已假設新型別，
     * 寫入時才在 DB 層炸開（Data too long）。寧可 migration 當場失敗讓人處理。
     */
    private function assertDetailType(string $expected): void
    {
        $actual = (string) (DB::selectOne(
            'SELECT DATA_TYPE FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            ['bank_dedup_logs', 'detail']
        )->DATA_TYPE ?? '');

        if ($actual !== $expected) {
            throw new RuntimeException(sprintf(
                'bank_dedup_logs.detail 型別為 %s、預期 %s：ALTER 未實際生效（可能被 SchemaMonitor 跳過），請人工處理。',
                $actual === '' ? '(查不到)' : $actual,
                $expected
            ));
        }
    }
};
