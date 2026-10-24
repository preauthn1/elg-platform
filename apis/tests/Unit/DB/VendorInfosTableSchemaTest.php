<?php

declare(strict_types=1);

namespace Tests\Unit\DB;

use App\Models\VendorInfo;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * vendor_infos 資料表的結構守衛（多幣別錢包第二期／階段 2.2）。
 *
 * vendor_infos.currency 是自由字串（varchar(5)），同時裝了標準幣別、單位變體與主檔查無的代碼，
 * 無法直接當幣別維度使用；currency_id 是它正規化後的標準幣別編號。五個測試各守一件事：
 * - 欄位存在：後續回填、guard、稽核都以它為前提。
 * - 資料庫層定義：NOT NULL 且 DEFAULT 0。0 是「尚未回填」的 sentinel、不用 NULL——0 能被稽核明確抓出來，
 *   NULL 會和「不適用」混在一起。直接讀 information_schema，改成 nullable 或拿掉預設值都會紅。
 * - 建列行為：不帶 currency_id 建列，讀回來就是 0（走資料庫預設，不靠 factory 補值）。
 * - 索引存在：玩家切換幣別後要過濾「支援該幣別的遊戲商」，走的就是這個索引，不可拿掉。
 * - rate 註解：同一顆 migration 順手改對的語意說明（兼含單位換算與匯率），守它不被改回「轉換率」。
 *
 * ⚠️ 讀不存在的欄位在本專案不會拋（AppServiceProvider 只開了 preventSilentlyDiscardingAttributes），
 *    會靜默回 NULL；斷言一律不做 (int) 轉型、不用 ?->，否則欄位不存在也會被折成 0 而恆綠。
 *
 * php artisan test tests/Unit/DB/VendorInfosTableSchemaTest.php
 */
class VendorInfosTableSchemaTest extends TestCase
{
    private const string TABLE = 'vendor_infos';

    private const string COLUMN = 'currency_id';

    private const string INDEX_NAME = 'vendor_infos_currency_id_index';

    public function test_vendor_infos_has_currency_id_column(): void
    {
        $this->assertTrue(
            Schema::hasColumn(self::TABLE, self::COLUMN),
            'vendor_infos 缺少 currency_id 欄位。vendor_infos.currency 是自由字串（varchar(5)），'
            . '同時裝了標準幣別、單位變體與主檔查無的代碼，無法直接當幣別維度使用。',
        );
    }

    public function test_currency_id_is_not_null_with_default_zero(): void
    {
        $column = $this->columnDefinition(self::COLUMN);

        $this->assertFalse($column['nullable'], 'vendor_infos.currency_id 必須 NOT NULL：sentinel 用 0 不用 NULL');
        $this->assertSame('0', (string) $column['default'], 'vendor_infos.currency_id 的資料庫預設值必須是 0（尚未回填）');
    }

    public function test_rate_comment_names_both_meanings(): void
    {
        // rate 同時承擔計價單位換算與跨幣別匯率（第零期契約第三節）；註解由加 currency_id 的 migration 順手改對，
        // 且以「現行註解」當閘門。這裡守的是註解本身，讓日後有人改回「轉換率」或 migration 的閘門失效時會紅。
        $this->assertSame(
            '對站台／會員幣別的換算率，兼含計價單位（千分、仙位）與跨幣別匯率兩種情形',
            $this->columnDefinition('rate')['comment'],
        );
    }

    public function test_row_created_without_currency_id_reads_back_zero(): void
    {
        // 拿掉 factory 的 currency_id => 0，讓值來自資料庫預設。欄位本身可 mass assign（$guarded 只擋 id 與時間戳）。
        $attributes = Arr::except(VendorInfo::factory()->raw(), [self::COLUMN]);
        $fresh = VendorInfo::query()->create($attributes)->fresh();

        $this->assertNotNull($fresh, 'vendor_infos 建列後讀不回來，測試前提不成立');
        $this->assertSame(0, $fresh->currency_id);
    }

    public function test_currency_id_index_exists(): void
    {
        $this->assertTrue(
            Schema::hasIndex(self::TABLE, self::INDEX_NAME),
            sprintf(
                'vendor_infos.currency_id 缺少索引 %s。玩家切換幣別後要過濾「支援該幣別的遊戲商」，'
                . '走的就是這個索引；沒有它每次切換都會全表掃描。「目前沒有查詢在用」不是拿掉它的理由。',
                self::INDEX_NAME,
            ),
        );
    }

    /**
     * 從 information_schema 取某欄位的定義。
     *
     * @return array<string, mixed>
     */
    private function columnDefinition(string $name): array
    {
        foreach (Schema::getColumns(self::TABLE) as $column) {
            if ($column['name'] === $name) {
                return $column;
            }
        }

        $this->fail(sprintf('%s 沒有 %s 欄位', self::TABLE, $name));
    }
}
