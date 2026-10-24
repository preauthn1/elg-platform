<?php

declare(strict_types=1);

namespace Tests\Unit\Vendors\PlayTechSW;

use App\Data\Vendor\GetGameListParamsData;
use App\Enums\Vendor\VendorIdEnum;
use App\Exceptions\ApiException;
use App\Models\Vendor;
use App\Models\VendorInfo;
use App\Vendors\Contracts\HasEntryGames;
use App\Vendors\Contracts\HasGameList;
use App\Vendors\Contracts\NeedsGameListSourceUrl;
use App\Vendors\Contracts\ShouldSyncGameList;
use App\Vendors\PlayTechSW\Driver;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Options as WriterOptions;
use OpenSpout\Writer\XLSX\Writer;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

/**
 * php artisan test tests/Unit/Vendors/PlayTechSW/DriverGameListTest.php --compact
 */
class DriverGameListTest extends TestCase
{
    private const string SOURCE_URL = 'https://sharepoint.test/sites/x/_layouts/15/download.aspx?share=test-token';

    private ?string $tempFile = null;

    protected function setUp(): void
    {
        parent::setUp();

        if (!Vendor::find(VendorIdEnum::PTSW->value)) {
            Vendor::factory()->create(['id' => VendorIdEnum::PTSW->value, 'name' => 'PlayTech SW']);
        }
    }

    protected function tearDown(): void
    {
        if ($this->tempFile !== null && is_file($this->tempFile)) {
            unlink($this->tempFile);
        }

        parent::tearDown();
    }

    #[TestDox('只實作 HasGameList、不標 ShouldSyncGameList：可手動觸發但不進 hourly 排程')]
    public function test_is_manual_only_and_not_scheduled(): void
    {
        $this->assertTrue(is_a(Driver::class, HasGameList::class, true));
        $this->assertFalse(
            is_a(Driver::class, ShouldSyncGameList::class, true),
            'PT 的清單來源是人工維護的活頁簿，標了就會被 update_game_list 排程逐小時拉',
        );
    }

    #[TestDox('宣告真人大廳入口代碼，同步時整批排除、不被判成三方未提供')]
    public function test_declares_live_lobby_as_entry_game(): void
    {
        $this->assertTrue(is_a(Driver::class, HasEntryGames::class, true));
        $this->assertSame(['ubal'], $this->driver()->entryGameCodes());
    }

    #[TestDox('逐商戶呼叫只下載並解析一次（清單與商戶無關）')]
    public function test_downloads_once_across_merchants(): void
    {
        $this->fakeWorkbook();

        $driver = $this->driver();
        $driver->setGameListSourceUrl(self::SOURCE_URL);
        $params = GetGameListParamsData::from(['vendorInfo' => $this->vendorInfo()]);

        $first = $driver->getGameList($params);
        $second = $driver->getGameList(GetGameListParamsData::from(['vendorInfo' => $this->vendorInfo()]));

        $this->assertSame(['slot_a', 'crash_a'], array_keys($first->gameList));
        $this->assertEquals($first->gameList, $second->gameList);
        Http::assertSentCount(1);
    }

    #[TestDox('未帶入清單來源網址即拋出，不靜默回空清單')]
    public function test_throws_when_source_url_was_not_injected(): void
    {
        $this->fakeWorkbook();

        // 靜默回空清單會讓 GameListSyncService 誤判「三方未提供」而跳過偵測
        $this->expectException(ApiException::class);
        $this->expectExceptionMessageMatches('/--sheet-url/');

        $this->driver()->getGameList(GetGameListParamsData::from(['vendorInfo' => $this->vendorInfo()]));
    }

    #[TestDox('宣告清單來源網址須由觸發者提供（不落 config／env）')]
    public function test_declares_source_url_is_injected(): void
    {
        $this->assertTrue(is_a(Driver::class, NeedsGameListSourceUrl::class, true));
    }

    private function driver(): Driver
    {
        return new Driver(VendorIdEnum::PTSW->value, null);
    }

    private function vendorInfo(): VendorInfo
    {
        return VendorInfo::factory()->create([
            'vendor_id' => VendorIdEnum::PTSW->value,
            'status' => 1,
        ]);
    }

    private function fakeWorkbook(): void
    {
        $this->tempFile = tempnam(sys_get_temp_dir(), 'ptsw_driver_') . '.xlsx';

        $sheets = [
            'Slots 电子' => [['Game Code', 'English Game Name'], ['游戏代码', '英文名称'], ['slot_a', 'Slot A']],
            'Crash 爆点游戏' => [['Game Code', 'English Game Name'], ['游戏代码', '英文名称'], ['crash_a', 'Crash A']],
        ];

        $writer = new Writer(new WriterOptions);
        $writer->openToFile($this->tempFile);

        $first = true;

        foreach ($sheets as $name => $rows) {
            $sheet = $first ? $writer->getCurrentSheet() : $writer->addNewSheetAndMakeItCurrent();
            $sheet->setName($name);
            $first = false;

            foreach ($rows as $row) {
                $writer->addRow(Row::fromValues($row));
            }
        }

        $writer->close();

        $body = (string) file_get_contents($this->tempFile);
        Http::fake(fn (Request $request) => Http::response($body, 200));
    }
}
