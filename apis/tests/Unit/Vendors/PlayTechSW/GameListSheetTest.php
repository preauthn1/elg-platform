<?php

declare(strict_types=1);

namespace Tests\Unit\Vendors\PlayTechSW;

use App\Enums\Game\GameTypeEnum;
use App\Enums\LanguageEnum;
use App\Exceptions\ApiException;
use App\Vendors\PlayTechSW\GameListSheet;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Options as WriterOptions;
use OpenSpout\Writer\XLSX\Writer;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

/**
 * php artisan test tests/Unit/Vendors/PlayTechSW/GameListSheetTest.php --compact
 */
class GameListSheetTest extends TestCase
{
    private const string SLOT_SHEET = 'Slots 电子';

    private const string CRASH_SHEET = 'Crash 爆点游戏';

    private const string SOURCE_URL = 'https://sharepoint.test/sites/x/_layouts/15/download.aspx?share=test-token';

    /** @var list<string> 測試期間產生的暫存檔，結束逐一刪除 */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        parent::tearDown();
    }

    #[TestDox('解析電子分頁：依表頭名稱取欄，中文表頭列不算一筆遊戲')]
    public function test_reads_slot_games_and_skips_chinese_header_row(): void
    {
        $this->fakeWorkbook([self::SLOT_SHEET => [
            ['SN', 'Released Date', 'English Game Name', 'Game Code', 'New Game Code', 'Chinese Game Name', 'Japanese Game Name', 'Korean Game Name'],
            ['编号', '上线时间', '英文名称', '游戏代码', '新游戏代码', '中文名称', '日文名称', '韩文名称'],
            ['1', '2026-01-01', 'Big Bad Towers', 'gpas_bbt_pop', '-', '邪恶高塔', 'ビッグバッド', '빅 배드'],
            ['2', '2026-01-02', 'Skullapalooza', 'gpas_sklza_pop', 'new_code_x', '骷髅狂欢节', 'スカラパルーザ', '스컬라팔루자'],
        ]]);

        $games = $this->readGames();

        $this->assertSame(['gpas_bbt_pop', 'gpas_sklza_pop', 'crash_a'], array_keys($games));
        $this->assertSame('Big Bad Towers', $games['gpas_bbt_pop']->gameName);
        $this->assertSame(GameTypeEnum::SLOTS->value, $games['gpas_bbt_pop']->gameType);
        $this->assertSame(1, $games['gpas_bbt_pop']->status);
    }

    #[TestDox('爆點分頁的遊戲歸街機類型，且沒有 New Game Code 欄不影響解析')]
    public function test_crash_sheet_games_are_arcade_type(): void
    {
        $this->fakeWorkbook([self::CRASH_SHEET => [
            ['SN', 'Released Date', 'English Game Name', 'Game Code', 'Chinese Game Name', 'Japanese Game Name', 'Korean Game Name', 'Game Type'],
            ['编号', '上线时间', '英文名称', '游戏代码', '中文名称', '日文名称', '韩文名称', '游戏类型'],
            ['1', '2026-09-01', 'Irish Click-A-Win', 'gpas_iluckcw_pop', '爱尔兰点击赢大奖', 'アイリッシュ', '아이리시', 'POP Arcade'],
            ['2', '2026-08-04', 'Cluck It', 'gpas_chkjmp_pop', '疯狂小鸡', 'クラック', '꼬끼오', 'POP Crash Games'],
        ]]);

        $games = $this->readGames();

        // PT 在爆點分頁混放 Arcade 與 Crash Games 兩種分類，我方一律歸街機
        $this->assertSame(GameTypeEnum::ARCADE->value, $games['gpas_iluckcw_pop']->gameType);
        $this->assertSame(GameTypeEnum::ARCADE->value, $games['gpas_chkjmp_pop']->gameType);
        $this->assertSame('疯狂小鸡', $games['gpas_chkjmp_pop']->gameNames[LanguageEnum::ZH_TW->value]);
        // 該分頁無 New Game Code 欄，取不到即視為無值
        $this->assertNull($games['gpas_iluckcw_pop']->remark);
    }

    #[TestDox('電子分頁的「已下架」「維護中」區塊不納入清單')]
    public function test_stops_at_removed_and_maintenance_blocks(): void
    {
        $this->fakeWorkbook([self::SLOT_SHEET => [
            ['SN', 'Released Date', 'English Game Name', 'Game Code', 'New Game Code', 'Chinese Game Name', 'Japanese Game Name', 'Korean Game Name'],
            ['编号', '上线时间', '英文名称', '游戏代码', '新游戏代码', '中文名称', '日文名称', '韩文名称'],
            ['1', '2026-01-01', 'Live Game', 'code_live', '-', '在架遊戲', '日文', '한국어'],
            ['', 'Games Maintance', '', '', '', '', '', ''],
            ['1', '2026-04-07', 'Songkran', 'code_maintenance', '-', '泼水节', '日文', '한국어'],
            ['', 'Removed Games', '', '', '', '', '', ''],
            // 下架區塊少了日／韓文名稱兩欄，照主清單欄位位置讀會把 RTP 寫進韓文名稱
            ['1', '2024-02-01', 'Virtual! Dog Racing', 'code_removed', '-', '虚拟！赛狗', 'POP Virtual Sports', '0.945'],
        ]]);

        $games = $this->readGames();

        $this->assertArrayHasKey('code_live', $games);
        $this->assertArrayNotHasKey('code_maintenance', $games);
        $this->assertArrayNotHasKey('code_removed', $games);
    }

    #[TestDox('欄位順序改變時仍取到正確的值（依表頭名稱而非固定索引）')]
    public function test_locates_columns_by_header_name_not_index(): void
    {
        // 相對於正式格式，把 Game Code 與英文名稱對調，並在最前面插一欄
        $this->fakeWorkbook([self::SLOT_SHEET => [
            ['Extra', 'Game Code', 'English Game Name', 'Chinese Game Name', 'Korean Game Name'],
            ['插入欄', '游戏代码', '英文名称', '中文名称', '韩文名称'],
            ['x', 'gpas_bbt_pop', 'Big Bad Towers', '邪恶高塔', '빅 배드'],
        ]]);

        $games = $this->readGames();

        $this->assertArrayHasKey('gpas_bbt_pop', $games);
        $this->assertSame('Big Bad Towers', $games['gpas_bbt_pop']->gameName);
    }

    #[TestDox('多語系名稱寫入 gameNames：中文同時給繁簡，日文因 LanguageEnum 無此語系而略過')]
    public function test_maps_multi_language_names(): void
    {
        $this->fakeWorkbook([self::SLOT_SHEET => [
            ['Game Code', 'English Game Name', 'Chinese Game Name', 'Japanese Game Name', 'Korean Game Name'],
            ['游戏代码', '英文名称', '中文名称', '日文名称', '韩文名称'],
            ['gpas_bbt_pop', 'Big Bad Towers', '邪恶高塔', 'ビッグバッド', '빅 배드'],
        ]]);

        $names = $this->readGames()['gpas_bbt_pop']->gameNames;

        $this->assertSame([
            LanguageEnum::EN->value => 'Big Bad Towers',
            LanguageEnum::ZH_TW->value => '邪恶高塔',
            LanguageEnum::ZH_CN->value => '邪恶高塔',
            LanguageEnum::KO->value => '빅 배드',
        ], $names);
    }

    #[TestDox('New Game Code 存入 remark 供對照，佔位符 - 視為無值')]
    public function test_new_game_code_goes_to_remark(): void
    {
        $this->fakeWorkbook([self::SLOT_SHEET => [
            ['Game Code', 'English Game Name', 'New Game Code'],
            ['游戏代码', '英文名称', '新游戏代码'],
            ['code_a', 'Game A', '-'],
            ['code_b', 'Game B', 'new_code_b'],
        ]]);

        $games = $this->readGames();

        $this->assertNull($games['code_a']->remark);
        $this->assertSame('new_code_b', $games['code_b']->remark);
    }

    #[TestDox('圖片網址一律留空：活頁簿沒有圖片欄，不按代碼推導我方 CDN 路徑')]
    public function test_image_url_is_left_empty(): void
    {
        $this->fakeWorkbook([self::SLOT_SHEET => [
            ['Game Code', 'English Game Name'],
            ['游戏代码', '英文名称'],
            ['code_a', 'Game A'],
        ]]);

        // 推導 <代碼>.png 會把死連結寫進 games.image 且看起來像有值——圖是人工上傳的，
        // 新遊戲多半還沒放；留空讓客服在後台一眼看出要補圖
        $this->assertSame('', $this->readGames()['code_a']->imageUrl);
    }

    #[TestDox('英文名稱缺漏時以遊戲代碼遞補，不留空白的 games.name')]
    public function test_falls_back_to_game_code_when_english_name_is_blank(): void
    {
        $this->fakeWorkbook([self::SLOT_SHEET => [
            ['Game Code', 'English Game Name'],
            ['游戏代码', '英文名称'],
            ['code_a', ''],
        ]]);

        $this->assertSame('code_a', $this->readGames()['code_a']->gameName);
    }

    #[TestDox('來源網址是空字串時拋出，且不發出請求')]
    public function test_throws_when_source_url_is_empty(): void
    {
        Http::fake();

        try {
            (new GameListSheet(' '))->readGames();
            $this->fail('空的來源網址應拋出 ApiException');
        } catch (ApiException $e) {
            $this->assertStringContainsString('source url is empty', $e->getMessage());
        }

        Http::assertNothingSent();
    }

    #[TestDox('下載打的是傳入的來源網址，不從 config 或 env 取值')]
    public function test_downloads_from_the_given_source_url(): void
    {
        $this->fakeWorkbook([]);

        (new GameListSheet(self::SOURCE_URL))->readGames();

        Http::assertSent(fn (Request $request): bool => $request->url() === self::SOURCE_URL);
    }

    #[TestDox('PT 的共用連結自動改寫成下載直鏈，操作者不必手動組網址')]
    public function test_rewrites_share_link_to_download_url(): void
    {
        $this->fakeWorkbook([]);

        // 客服拿到的就是這種連結（在瀏覽器會開 Excel Online）；直接抓只會拿到網頁
        (new GameListSheet('https://example.invalid/:x:/s/GamesVIPLevels/EZsXtoken'))->readGames();

        Http::assertSent(fn (Request $request): bool => $request->url()
            === 'https://example.invalid/sites/GamesVIPLevels/_layouts/15/download.aspx?share=EZsXtoken');
    }

    #[TestDox('已經是下載直鏈時不改寫，原樣送出')]
    public function test_leaves_download_url_untouched(): void
    {
        $this->fakeWorkbook([]);

        $direct = 'https://example.invalid/sites/GamesVIPLevels/_layouts/15/download.aspx?share=EZsXtoken';
        (new GameListSheet($direct))->readGames();

        Http::assertSent(fn (Request $request): bool => $request->url() === $direct);
    }

    #[TestDox('共用連結的 query 一併丟棄（rtime 等頁面參數對下載無用）')]
    public function test_drops_query_string_of_the_share_link(): void
    {
        $this->fakeWorkbook([]);

        (new GameListSheet('https://example.invalid/:x:/s/GamesVIPLevels/EZsXtoken?rtime=WwRXWZgI30g'))->readGames();

        Http::assertSent(fn (Request $request): bool => $request->url()
            === 'https://example.invalid/sites/GamesVIPLevels/_layouts/15/download.aspx?share=EZsXtoken');
    }

    #[TestDox('認不出的網址形式原樣送出，不在這裡猜格式')]
    public function test_leaves_unrecognised_url_untouched(): void
    {
        $this->fakeWorkbook([]);

        // 可能是新形式或別的來源；讓 SharePoint 自己回應，拿不到 xlsx 仍有 assertXlsx 攔
        $other = 'https://example.test/some/other/path?x=1';
        (new GameListSheet($other))->readGames();

        Http::assertSent(fn (Request $request): bool => $request->url() === $other);
    }

    #[TestDox('回 200 但內容是 HTML 時拋出並帶上 Content-Type 與內容開頭，不讓 openspout 的 Not a zip archive 蓋掉原因')]
    public function test_throws_with_diagnostics_when_response_is_html(): void
    {
        // SharePoint 在連結需登入或來源不被允許時，常以 200 回一頁 HTML 而非 4xx
        Http::fake(['*' => Http::response(
            '<!DOCTYPE html><html><head><title>Sign in to your account</title></head><body>...</body></html>',
            200,
            ['Content-Type' => 'text/html; charset=utf-8'],
        )]);

        try {
            $this->readGames();
            $this->fail('非 xlsx 內容應拋出 ApiException');
        } catch (ApiException $e) {
            $this->assertStringContainsString('not an xlsx', $e->getMessage());
            $this->assertStringContainsString('text/html', $e->getMessage());
            // 內容開頭要進得了訊息，才不必翻 log 就知道拿到的是登入頁
            $this->assertStringContainsString('Sign in to your account', $e->getMessage());
        }
    }

    #[TestDox('連結失效（HTTP 403）時拋出並指明可能已被撤銷，不把錯誤頁丟給 xlsx 解析')]
    public function test_throws_when_share_link_is_revoked(): void
    {
        Http::fake(['*' => Http::response('Forbidden', 403)]);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessageMatches('/HTTP 403/');

        $this->readGames();
    }

    #[TestDox('活頁簿缺任一目標分頁時拋出並指名分頁，不靜默少一種類型')]
    public function test_throws_when_a_target_sheet_is_missing(): void
    {
        $this->writeWorkbook([self::SLOT_SHEET => [
            ['Game Code', 'English Game Name'],
            ['游戏代码', '英文名称'],
            ['code_a', 'Game A'],
        ]]);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessageMatches('/sheet "Crash 爆点游戏" not found/');

        $this->readGames();
    }

    #[TestDox('分頁存在但沒有任何遊戲列時拋出，避免同步拿到空清單')]
    public function test_throws_when_sheet_has_no_game_rows(): void
    {
        $this->fakeWorkbook([self::SLOT_SHEET => [
            ['Game Code', 'English Game Name'],
            ['游戏代码', '英文名称'],
        ]]);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessageMatches('/no game rows/');

        $this->readGames();
    }

    /**
     * @return array<string, \App\Data\GameInfoData>
     */
    private function readGames(): array
    {
        return (new GameListSheet(self::SOURCE_URL))->readGames();
    }

    /**
     * 以指定分頁內容產生活頁簿並 fake 成下載回應；未指定的目標分頁補一份最小內容，
     * 使個別測試只需描述自己關心的那一頁。
     *
     * @param  array<string, list<list<string>>>  $sheets
     */
    private function fakeWorkbook(array $sheets): void
    {
        $defaults = [
            self::SLOT_SHEET => [
                ['Game Code', 'English Game Name'],
                ['游戏代码', '英文名称'],
                ['slot_a', 'Slot A'],
            ],
            self::CRASH_SHEET => [
                ['Game Code', 'English Game Name'],
                ['游戏代码', '英文名称'],
                ['crash_a', 'Crash A'],
            ],
        ];

        $this->writeWorkbook($sheets + array_diff_key($defaults, $sheets));
    }

    /**
     * @param  array<string, list<list<string>>>  $sheets
     */
    private function writeWorkbook(array $sheets): void
    {
        $path = tempnam(sys_get_temp_dir(), 'ptsw_test_') . '.xlsx';
        $this->tempFiles[] = $path;

        $writer = new Writer(new WriterOptions);
        $writer->openToFile($path);

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

        $body = (string) file_get_contents($path);

        // sink() 走 Guzzle 串流寫檔，Http::fake 的回應體同樣會被寫入 sink 目標
        Http::fake(fn (Request $request) => Http::response($body, 200));
    }
}
