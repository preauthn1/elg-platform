<?php

declare(strict_types=1);

use App\Facades\SchemaMonitor;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

return new class extends Migration
{
    private const string TABLE = 'vendor_infos';

    private const string COLUMN = 'currency_id';

    private const string INDEX_NAME = 'vendor_infos_currency_id_index';

    /** rate 加欄前的註解（down() 還原用）。 */
    private const string RATE_COMMENT_BEFORE = '轉換率';

    /** rate 的新註解；VendorInfosTableSchemaTest 也守這段文字。 */
    private const string RATE_COMMENT_AFTER = '對站台／會員幣別的換算率，兼含計價單位（千分、仙位）與跨幣別匯率兩種情形';

    /**
     * vendor_infos 加 currency_id（多幣別錢包第二期 2.2）。
     *
     * 既有的 currency 字串欄位不動——它直接進三方 payload（單一錢包 controller、遊戲商 Driver 與
     * Service、轉帳錢包 controller，處數見 database/data/currency_aliases.php 檔頭），改它等於改三方對接。
     * 新欄位是「正規化後的標準幣別編號」（currencies.id），字串給三方與人看、本欄給程式判讀，兩層解耦。
     *
     * 預設 0 = 尚未回填（sentinel），不用 NULL：0 可以被稽核明確抓出來，NULL 會和「不適用」混在一起
     * （與第一期非貨幣錢包幣別固定填 0 同一慣例）。不建外鍵，只加欄位與索引（設計決策 19）。
     *
     * 🔴 索引不可拿掉：玩家切換幣別後要過濾「支援該幣別的遊戲商」，走的就是這個索引；
     *    「目前沒有查詢在用」不是拿掉它的理由。
     *
     * 六站 vendor_infos 皆數百列（09-07 實查 70～614 列），遠低於 SchemaMonitor 的列數門檻；
     * 不指定 ALGORITHM，交給 MySQL 挑（小表即使重建也無感）。第一期對 member_wallets 這種大熱表才走手寫
     * DDL＋ALGORITHM=INSTANT，兩套工法的分野以表的規模為準。以 hasColumn()／hasIndex() 包住支援重跑。
     */
    public function up(): void
    {
        if (!SchemaMonitor::hasColumn(self::TABLE, self::COLUMN)) {
            SchemaMonitor::table(self::TABLE, function (Blueprint $table) {
                $table->unsignedInteger(self::COLUMN)
                    ->default(0)
                    ->comment('標準幣別編號（currencies.id），0=尚未回填；與 currency 字串並存，字串進三方 payload、本欄供查詢與統計')
                    ->after('currency');
            });
        }

        // 順手把 rate 的註解改對：這個欄位同時承擔計價單位換算（千分制 0.001、仙位 100）與跨幣別匯率
        // （0.00025）兩種語意，原註解「轉換率」看不出來。第零期契約第三節登記了這件事但沒有排進任何 task。
        // 閘門問的是「註解對了沒」，不是「欄位存不存在」——加欄與改註解是兩件不相干的事，共用 hasColumn 閘門會在
        // 「加欄成功、改註解失敗」後永遠套不到；改成讀現行註解，目標已到位就跳過，重跑零 DDL。
        // 型別、預設值、NOT NULL 都照原樣重述（change() 不會保留舊屬性），五站 09-07 實查 rate 皆為
        // decimal(11,5) NOT NULL DEFAULT 1.00000，重述不會截斷精度。
        if ($this->rateComment() !== self::RATE_COMMENT_AFTER) {
            SchemaMonitor::table(self::TABLE, function (Blueprint $table) {
                $table->decimal('rate', 11, 5)
                    ->default(1)
                    ->comment(self::RATE_COMMENT_AFTER)
                    ->change();
            });
        }

        // 索引拆成第二次呼叫：同一個 Blueprint 內先加欄位再對它建索引，部分 driver 會因欄位尚未存在而失敗。
        if (!SchemaMonitor::hasIndex(self::TABLE, self::INDEX_NAME)) {
            SchemaMonitor::table(self::TABLE, function (Blueprint $table) {
                $table->index([self::COLUMN], self::INDEX_NAME);
            });
        }

        // SchemaMonitor::table() 在 production 數列數失敗時會靜默 return 而不拋，Migrator 仍會把本 migration
        // 記成已執行——欄位沒加、也永遠不會重跑。後續回填、guard、稽核都以這兩樣為前提，這裡把靜默降級變成當場失敗。
        // 索引用「欄位」而非索引名查：確認的是 currency_id 上真的有索引，也避免 phpstan 把同參數的第二次呼叫推成恆假；
        // 索引名另由 VendorInfosTableSchemaTest 守。
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
        if (SchemaMonitor::hasColumn(self::TABLE, self::COLUMN)) {
            SchemaMonitor::table(self::TABLE, function (Blueprint $table) {
                if (SchemaMonitor::hasIndex(self::TABLE, self::INDEX_NAME)) {
                    $table->dropIndex(self::INDEX_NAME);
                }

                $table->dropColumn(self::COLUMN);
            });
        }

        // rate 的註解還原成加欄前的寫法，型別與預設值不變；只在現行註解是本 migration 寫上去的那段時才還原，
        // 不覆蓋別人之後改過的內容
        if ($this->rateComment() === self::RATE_COMMENT_AFTER) {
            SchemaMonitor::table(self::TABLE, function (Blueprint $table) {
                $table->decimal('rate', 11, 5)
                    ->default(1)
                    ->comment(self::RATE_COMMENT_BEFORE)
                    ->change();
            });
        }

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

    /**
     * rate 欄位目前的註解（來自 information_schema）；欄位不存在時回 null。
     */
    private function rateComment(): ?string
    {
        foreach (SchemaMonitor::getColumns(self::TABLE) as $column) {
            if ($column['name'] === 'rate') {
                return is_string($column['comment']) ? $column['comment'] : null;
            }
        }

        return null;
    }
};
