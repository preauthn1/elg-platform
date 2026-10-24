<?php

declare(strict_types=1);

namespace Tests\Unit\DB;

use App\Enums\Vendor\VendorIdEnum;
use App\Models\EventVendorInfo;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * event_vendor_infos 資料表的結構守衛（多幣別錢包第二期，與 vendor_infos 技術對齊）。
 *
 * 活動用單一錢包的商戶主檔只有 currency 字串，currency_id 是它正規化後的標準幣別編號，語意與
 * vendor_infos.currency_id 相同（0 = 尚未回填的 sentinel、不用 NULL；索引供依幣別過濾）。四個測試各守一件事：
 * 欄位存在、資料庫層 NOT NULL 且 DEFAULT 0、不帶值建列讀回 0、索引存在。
 *
 * ⚠️ 讀不存在的欄位在本專案不會拋（只開了 preventSilentlyDiscardingAttributes），會靜默回 NULL；
 *    斷言一律不做 (int) 轉型、不用 ?->，否則欄位不存在也會被折成 0 而恆綠。
 *
 * php artisan test tests/Unit/DB/EventVendorInfosTableSchemaTest.php
 */
class EventVendorInfosTableSchemaTest extends TestCase
{
    private const string TABLE = 'event_vendor_infos';

    private const string COLUMN = 'currency_id';

    private const string INDEX_NAME = 'event_vendor_infos_currency_id_index';

    public function test_event_vendor_infos_has_currency_id_column(): void
    {
        $this->assertTrue(
            Schema::hasColumn(self::TABLE, self::COLUMN),
            'event_vendor_infos 缺少 currency_id 欄位。活動商戶主檔只有 currency 自由字串，'
            . '回填、一致性 guard 與稽核都要靠這個欄位才能與 vendor_infos 同一套處理。',
        );
    }

    public function test_currency_id_is_not_null_with_default_zero(): void
    {
        $column = $this->columnDefinition();

        $this->assertFalse($column['nullable'], 'event_vendor_infos.currency_id 必須 NOT NULL：sentinel 用 0 不用 NULL');
        $this->assertSame('0', (string) $column['default'], 'event_vendor_infos.currency_id 的資料庫預設值必須是 0（尚未回填）');
    }

    public function test_row_created_without_currency_id_reads_back_zero(): void
    {
        // 沒有 factory；只給 NOT NULL 且無預設值的欄位，讓 currency_id 走資料庫預設。
        // info 是 array cast，給 [] 讓落庫值與 @property 宣告的型別一致。
        $fresh = EventVendorInfo::query()->create([
            'vendor_id' => VendorIdEnum::PGSSW->value,
            'currency' => 'MYR',
            'info' => [],
            'account_prefix' => 'evtTEST',
        ])->fresh();

        $this->assertNotNull($fresh, 'event_vendor_infos 建列後讀不回來，測試前提不成立');
        $this->assertSame(0, $fresh->currency_id);
    }

    public function test_currency_id_index_exists(): void
    {
        $this->assertTrue(
            Schema::hasIndex(self::TABLE, self::INDEX_NAME),
            sprintf('event_vendor_infos.currency_id 缺少索引 %s，依幣別過濾活動商戶會全表掃描。', self::INDEX_NAME),
        );
    }

    /**
     * 從 information_schema 取 currency_id 的欄位定義。
     *
     * @return array<string, mixed>
     */
    private function columnDefinition(): array
    {
        foreach (Schema::getColumns(self::TABLE) as $column) {
            if ($column['name'] === self::COLUMN) {
                return $column;
            }
        }

        $this->fail(sprintf('%s 沒有 %s 欄位', self::TABLE, self::COLUMN));
    }
}
