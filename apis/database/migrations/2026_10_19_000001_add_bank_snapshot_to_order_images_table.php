<?php

declare(strict_types=1);

use App\Facades\SchemaMonitor;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `order_images` 記下「這張自助上分訂單建立當下的銀行戶口」target/tickets#4545。
 *
 * 現況是審核頁即時 join `bank_accounts` / `order_types` 顯示銀行資訊，戶口被改過之後
 * 所有舊訂單顯示的戶名與帳號跟著變，查不到會員當時轉到哪個帳戶。
 *
 * 快照用單一 JSON 欄位而不是五個獨立欄位：bank_name / bank_code / account_nickname /
 * account_name / account_no 全是顯示欄位，沒有一個要 join、聚合或排序，拆開換不到查詢能力，
 * 卻要對熱表下五次 DDL。BSB 不另存，它就在 `account_no` 的 `BSB__帳號` 前綴裡，
 * 顯示端照舊 explode。
 *
 * 舊訂單一律 NULL（工單明訂不回補），讀取端 NULL 時 fallback 回原本的 join。
 *
 * SchemaMonitor 對超過列數門檻的表是**靜默跳過、不拋錯**（migration 仍記為已執行），
 * 所以操作後面有 `Schema::hasColumn` 斷言，避免「跑過了但欄位沒建成」無人察覺。本表列數遠低於
 * 門檻（`RenameBankCommand` 實測 TPower 29,312 列），走一般流程即可；JSON 欄位 nullable、
 * 無 DEFAULT，MySQL 8.0 下是 INSTANT。
 */
return new class extends Migration
{
    private const string TABLE = 'order_images';

    private const string COLUMN = 'bank_snapshot';

    public function up(): void
    {
        if (!Schema::hasColumn(self::TABLE, self::COLUMN)) {
            SchemaMonitor::table(self::TABLE, function (Blueprint $table): void {
                $table->json(self::COLUMN)->nullable()->comment('建單當下的銀行戶口快照, 舊單為 NULL');
            });
        }

        if (!Schema::hasColumn(self::TABLE, self::COLUMN)) {
            throw new RuntimeException(sprintf(
                '%s.%s 未生效（SchemaMonitor 可能因列數門檻靜默跳過，且正式站尚未以後台工具補上），中止',
                self::TABLE,
                self::COLUMN,
            ));
        }
    }

    public function down(): void
    {
        SchemaMonitor::table(self::TABLE, function (Blueprint $table): void {
            $table->dropColumn(self::COLUMN);
        });
    }
};
