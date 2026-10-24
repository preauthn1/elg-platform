<?php

declare(strict_types=1);

use App\Facades\SchemaMonitor;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

return new class extends Migration
{
    private const string TABLE = 'event_vendor_infos';

    private const string COLUMN = 'currency_id';

    private const string INDEX_NAME = 'event_vendor_infos_currency_id_index';

    /**
     * event_vendor_infos 加 currency_id（多幣別錢包第二期，與 vendor_infos 技術對齊；決議⑲）。
     *
     * 活動用單一錢包的商戶主檔，形狀與 vendor_infos 相同、只有 currency 字串（varchar(191)）。
     * 本欄與 vendor_infos.currency_id 同語意：正規化後的標準幣別編號（currencies.id），預設 0 = 尚未回填，
     * 不建外鍵、只加欄位與索引。回填走同一套對照（currency_aliases.php ＋ Enum∩主檔），
     * 一致性 guard 與稽核同步納入。「活動注單要不要分幣別」是業務決策，本 migration 只讓主檔具備能力。
     *
     * 六站 event_vendor_infos 各 1～6 列（2026-09-07 實查，幣別清一色 MYR），遠低於 SchemaMonitor 的列數門檻；
     * 小表不指定 ALGORITHM。
     */
    public function up(): void
    {
        if (!SchemaMonitor::hasColumn(self::TABLE, self::COLUMN)) {
            SchemaMonitor::table(self::TABLE, function (Blueprint $table) {
                $table->unsignedInteger(self::COLUMN)
                    ->default(0)
                    ->comment('標準幣別編號（currencies.id），0=尚未回填；與 currency 字串並存，語意同 vendor_infos.currency_id')
                    ->after('currency');
            });
        }

        // 索引拆成第二次呼叫：同一個 Blueprint 內先加欄位再對它建索引，部分 driver 會因欄位尚未存在而失敗。
        if (!SchemaMonitor::hasIndex(self::TABLE, self::INDEX_NAME)) {
            SchemaMonitor::table(self::TABLE, function (Blueprint $table) {
                $table->index([self::COLUMN], self::INDEX_NAME);
            });
        }

        // SchemaMonitor::table() 在 production 數列數失敗時會靜默 return 而不拋，Migrator 仍會把本 migration
        // 記成已執行；後續回填、guard、稽核都以欄位與索引為前提，這裡把靜默降級變成當場失敗。
        // 索引用「欄位」而非索引名查：確認的是 currency_id 上真的有索引，也避免 phpstan 把同參數的第二次呼叫推成恆假；
        // 索引名另由 EventVendorInfosTableSchemaTest 守。
        if (!SchemaMonitor::hasColumn(self::TABLE, self::COLUMN) || !SchemaMonitor::hasIndex(self::TABLE, [self::COLUMN])) {
            throw new RuntimeException(sprintf(
                '%s.%s 未建立，或該欄位上沒有索引（預期 %s）：SchemaMonitor 略過了變更（多半是列數檢查的連線失敗），請查明後重跑本 migration。',
                self::TABLE,
                self::COLUMN,
                self::INDEX_NAME,
            ));
        }
    }

    public function down(): void
    {
        if (!SchemaMonitor::hasColumn(self::TABLE, self::COLUMN)) {
            return;
        }

        SchemaMonitor::table(self::TABLE, function (Blueprint $table) {
            if (SchemaMonitor::hasIndex(self::TABLE, self::INDEX_NAME)) {
                $table->dropIndex(self::INDEX_NAME);
            }

            $table->dropColumn(self::COLUMN);
        });

        // 與 up() 對稱：靜默略過時 Migrator 仍會刪掉記錄，讓 migrate:status 說「未執行」但欄位還在。
        // 用 hasColumns() 而非再呼叫一次 hasColumn()：phpstan 把 facade 靜態呼叫視為純函式，同參數的第二次呼叫會被推成恆真。
        if (SchemaMonitor::hasColumns(self::TABLE, [self::COLUMN])) {
            throw new RuntimeException(sprintf(
                '%s.%s 仍存在：SchemaMonitor 略過了回滾（多半是列數檢查的連線失敗），請查明後重跑 rollback。',
                self::TABLE,
                self::COLUMN,
            ));
        }
    }
};
