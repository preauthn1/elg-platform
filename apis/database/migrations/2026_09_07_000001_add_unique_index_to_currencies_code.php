<?php

declare(strict_types=1);

use App\Facades\SchemaMonitor;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

return new class extends Migration
{
    private const string INDEX_NAME = 'currencies_code_unique';

    /**
     * currencies.code 加唯一鍵（多幣別錢包第二期 2.1）。
     *
     * SyncCurrenciesSeeder 以 code 當 uniqueKey 做同步，但該欄一直只有 PRIMARY(id)、
     * 沒有任何索引——「code 唯一」是程式假設，不是資料庫保證。第二期要把
     * vendor_infos.currency_id 回填成主檔 id，主檔本身先得可信。
     *
     * 主檔六站皆 21 列、code 零重複（2026-09-07 六站實查），遠低於 SchemaMonitor 的列數門檻；
     * 仍以 hasIndex() 包住支援重跑。索引名固定為 currencies_code_unique，
     * CurrenciesTableSchemaTest 與幣別一致性稽核排程都引用它，不可改名。
     */
    public function up(): void
    {
        if (SchemaMonitor::hasIndex('currencies', self::INDEX_NAME)) {
            return;
        }

        SchemaMonitor::table('currencies', function (Blueprint $table) {
            $table->unique('code', self::INDEX_NAME);
        });

        // SchemaMonitor::table() 在 production 數列數失敗時會靜默 return 而不拋，
        // Migrator 仍會把本 migration 記成已執行——索引沒建、也永遠不會重跑。
        // 本期後續（currency_id 回填、稽核排程）都以這個索引為前提，所以這裡破例把靜默降級變成當場失敗。
        // 用「欄位＋類型」而非索引名查：確認的是 code 上真的有唯一鍵，也避免 phpstan 把同參數的第二次呼叫推成恆假。
        if (!SchemaMonitor::hasIndex('currencies', ['code'], 'unique')) {
            throw new RuntimeException(sprintf(
                '唯一鍵 %s 未建立：SchemaMonitor 略過了 currencies 的變更（多半是列數檢查的連線失敗），請查明後重跑本 migration。',
                self::INDEX_NAME,
            ));
        }
    }

    public function down(): void
    {
        if (!SchemaMonitor::hasIndex('currencies', self::INDEX_NAME)) {
            return;
        }

        SchemaMonitor::table('currencies', function (Blueprint $table) {
            $table->dropUnique(self::INDEX_NAME);
        });
    }
};
