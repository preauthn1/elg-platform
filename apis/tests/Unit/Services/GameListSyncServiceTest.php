<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Data\GameInfoData;
use App\Data\Vendor\GetGameListParamsData;
use App\Data\Vendor\GetGameListResultData;
use App\Enums\Game\GameTypeEnum;
use App\Enums\Vendor\VendorIdEnum;
use App\Models\AgentGame;
use App\Models\Game;
use App\Models\Vendor;
use App\Models\VendorInfo;
use App\Services\GameListSyncService;
use App\Vendors\Contracts\HasEntryGames;
use App\Vendors\Contracts\HasGameList;
use App\Vendors\Ia\Driver as IaDriver;
use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use RuntimeException;
use Tests\TestCase;

/**
 * 遊戲清單同步服務測試
 *
 * 核心守護：同步不得自動停用遊戲（2026-08-20 VWorld PGSSW 多商戶互踩全滅事故），
 * 「三方未提供」僅由 findGamesMissingFromUnion() 以全商戶聯集偵測、提示人工關閉。
 *
 * php artisan test tests/Unit/Services/GameListSyncServiceTest.php
 */
class GameListSyncServiceTest extends TestCase
{
    private GameListSyncService $syncService;

    private Vendor $vendor;

    private VendorInfo $vendorInfo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->syncService = app(GameListSyncService::class);
        $this->vendor = Vendor::factory()->create();
        $this->vendorInfo = $this->vendor->vendorInfos()->save(
            VendorInfo::factory()->make(['admin' => 0])
        );
    }

    private function createGame(string $gameType, int $status = 1, ?string $name = null, int $typeId = 0): false|Model
    {
        return $this->vendor->games()->save(new Game([
            'game_type' => $gameType,
            'name' => $name ?? "game {$gameType}",
            'type_id' => $typeId,
            'status' => $status,
            'open_window' => 0,
            'image' => 'https://example.invalid/img.png',
            'admin' => 0,
        ]));
    }

    private function makeGameInfo(string $gameCode): GameInfoData
    {
        return new GameInfoData(
            gameCode: $gameCode,
            gameName: "game {$gameCode}",
            imageUrl: 'https://example.invalid/img.png',
            gameType: 0,
            status: 1,
        );
    }

    #[TestDox('同步不得停用單一商戶清單缺席的遊戲（多商戶目錄互斥防互踩）')]
    public function test_sync_never_disables_games_missing_from_merchant_list(): void
    {
        // 多商戶互斥目錄場景：OTHER_MERCHANT_GAME 屬其他商戶專屬，本商戶清單不會回傳
        $ownGame = $this->createGame('OWN_GAME');
        $otherMerchantGame = $this->createGame('OTHER_MERCHANT_GAME');

        $this->syncService->sync(
            gameInfos: [$this->makeGameInfo('OWN_GAME')],
            vendor: $this->vendor,
            vendorInfo: $this->vendorInfo,
        );

        $this->assertEquals(1, $ownGame->refresh()->status);
        $this->assertEquals(1, $otherMerchantGame->refresh()->status);
    }

    #[TestDox('三方有、DB 無的遊戲會被新增')]
    public function test_sync_inserts_games_missing_from_db(): void
    {
        $result = $this->syncService->sync(
            gameInfos: [$this->makeGameInfo('NEW_GAME')],
            vendor: $this->vendor,
            vendorInfo: $this->vendorInfo,
        );

        $this->assertEquals(1, $result->gamesInserted);
        // admin = 0 標記系統同步寫入，後台人工異動會蓋上操作者 id
        $this->assertDatabaseHas('games', [
            'vendor_id' => $this->vendor->id,
            'game_type' => 'NEW_GAME',
            'status' => 1,
            'admin' => 0,
        ]);
    }

    #[Group('ticket-4405')]
    #[Group('ac-5 [人工&自動] 重複同步不會再新增，既有遊戲的名稱、圖片、狀態、備註不變')]
    #[TestDox('同步純新增：既有遊戲的任何欄位不得被三方回傳值覆蓋（target/op_tickets#2871）')]
    public function test_sync_never_updates_existing_game(): void
    {
        $game = $this->vendor->games()->save(new Game([
            'game_type' => 'EXISTING_GAME',
            'name' => '舊名稱',
            'type_id' => 2,
            'image' => 'https://cdn.test/old.png',
            // 客服／營運人工下架與備註
            'status' => 0,
            'memo' => '人工備註',
            'open_window' => 0,
            'admin' => 99,
        ]));

        $this->syncService->sync(
            gameInfos: [$this->makeVendorGameInfo('EXISTING_GAME')],
            vendor: $this->vendor,
            vendorInfo: $this->vendorInfo,
        );

        $game->refresh();

        // 既有欄位一律不更新，由客服後台手動維護
        $this->assertSame('舊名稱', $game->name);
        $this->assertSame('https://cdn.test/old.png', $game->image);
        $this->assertSame(0, (int) $game->status);
        $this->assertSame('人工備註', $game->memo);
    }

    #[TestDox('同步不得動到既有的 agent_games 列（target/op_tickets#2871）')]
    public function test_sync_never_touches_existing_agent_game(): void
    {
        $game = $this->createGame('EXISTING_GAME');

        $agentGame = AgentGame::query()->create([
            'agent_id' => 1,
            'game_id' => $game->id,
            // 人工開啟
            'status' => 1,
            'admin' => 99,
        ]);

        $this->syncService->sync(
            gameInfos: [$this->makeVendorGameInfo('EXISTING_GAME')],
            vendor: $this->vendor,
            vendorInfo: $this->vendorInfo,
        );

        $agentGame->refresh();

        // 既有列完全不動：人工開啟的 status 與人工標記的 admin 都保留
        $this->assertSame(1, (int) $agentGame->status);
        $this->assertSame(99, (int) $agentGame->admin);
    }

    #[TestDox('新增遊戲時仍寫入三方帶回的 status 與 memo')]
    public function test_insert_writes_status_and_memo_from_vendor(): void
    {
        $this->syncService->sync(
            gameInfos: [$this->makeVendorGameInfo('BRAND_NEW_GAME')],
            vendor: $this->vendor,
            vendorInfo: $this->vendorInfo,
        );

        $game = Game::query()
            ->where('vendor_id', $this->vendor->id)
            ->where('game_type', 'BRAND_NEW_GAME')
            ->firstOrFail();

        $this->assertSame('新名稱', $game->name);
        $this->assertSame(1, (int) $game->status);
        $this->assertSame('isPrePaidMode', $game->memo);
    }

    /**
     * 模擬三方回傳帶完整欄位（新名稱／新圖／memo）的遊戲，供「不更新既有欄位」對照
     */
    private function makeVendorGameInfo(string $gameCode): GameInfoData
    {
        return GameInfoData::from([
            'gameCode' => $gameCode,
            'gameName' => '新名稱',
            'imageUrl' => 'https://cdn.test/new.png',
            'gameType' => 2,
            'status' => 1,
            'memo' => 'isPrePaidMode',
        ]);
    }

    #[TestDox('聯集偵測只回報「聯集外且仍啟用」的遊戲')]
    public function test_find_games_missing_from_union_reports_only_enabled_games_outside_union(): void
    {
        $this->createGame('MERCHANT_A_GAME');
        $this->createGame('MERCHANT_B_GAME');
        $this->createGame('VENDOR_REMOVED_GAME');
        $this->createGame('ALREADY_DISABLED_GAME', status: 0);

        // 兩商戶目錄互斥，聯集涵蓋雙方；只有三方真正下架的遊戲該被回報
        $missing = $this->syncService->findGamesMissingFromUnion(
            vendor: $this->vendor,
            unionGameCodes: ['MERCHANT_A_GAME', 'MERCHANT_B_GAME'],
        );

        $this->assertEquals(['VENDOR_REMOVED_GAME' => 'game VENDOR_REMOVED_GAME'], $missing);
    }

    #[TestDox('聯集偵測排除自建入口遊戲')]
    public function test_find_games_missing_from_union_excludes_entry_games(): void
    {
        $this->createGame('ENTRY_GAME');
        $this->createGame('NORMAL_GAME');

        $missing = $this->syncService->findGamesMissingFromUnion(
            vendor: $this->vendor,
            unionGameCodes: ['NORMAL_GAME'],
            entryGameCodes: ['ENTRY_GAME'],
        );

        $this->assertSame([], $missing);
    }

    #[TestDox('後台異動過的遊戲（admin > 0）視為已人工處理，不列入「三方未提供」回報')]
    public function test_find_games_missing_from_union_excludes_manually_handled_games(): void
    {
        $this->vendor->games()->save(new Game([
            'game_type' => 'MANUALLY_HANDLED_GAME',
            'name' => '人工處理過',
            'type_id' => 0,
            'status' => 1,
            'open_window' => 0,
            'image' => 'https://example.invalid/img.png',
            // 後台異動會蓋上操作者 id
            'admin' => 99,
        ]));
        $this->createGame('UNHANDLED_GAME');

        $missing = $this->syncService->findGamesMissingFromUnion(
            vendor: $this->vendor,
            unionGameCodes: ['SOME_VENDOR_GAME'],
        );

        $this->assertArrayHasKey('UNHANDLED_GAME', $missing);
        $this->assertArrayNotHasKey('MANUALLY_HANDLED_GAME', $missing);
    }

    #[TestDox('空聯集直接不比對、不回報')]
    public function test_find_games_missing_from_union_refuses_empty_union(): void
    {
        $this->createGame('SOME_GAME');

        $this->assertSame([], $this->syncService->findGamesMissingFromUnion(
            vendor: $this->vendor,
            unionGameCodes: [],
        ));
    }

    /**
     * 建立回傳固定清單的 fake driver（$listsByVendorInfoId：vendor_info_id => 遊戲代碼清單或 Throwable）
     *
     * @param  array<int, list<string>|RuntimeException>  $listsByVendorInfoId
     * @param  list<string>  $entryCodes
     */
    private function makeDriver(array $listsByVendorInfoId, array $entryCodes = []): HasGameList
    {
        return new class($listsByVendorInfoId, $entryCodes, $this) implements HasEntryGames, HasGameList
        {
            public function __construct(
                private readonly array $listsByVendorInfoId,
                private readonly array $entryCodes,
                private readonly GameListSyncServiceTest $test,
            ) {}

            public function getGameList(GetGameListParamsData $params): GetGameListResultData
            {
                $list = $this->listsByVendorInfoId[$params->vendorInfo->id] ?? [];

                if ($list instanceof RuntimeException) {
                    throw $list;
                }

                return new GetGameListResultData(
                    gameList: array_map(fn (string $code) => $this->test->makeGameInfoFor($code), $list),
                );
            }

            public function entryGameCodes(): array
            {
                return $this->entryCodes;
            }
        };
    }

    public function makeGameInfoFor(string $gameCode): GameInfoData
    {
        return $this->makeGameInfo($gameCode);
    }

    private function addVendorInfo(): VendorInfo
    {
        $vendorInfo = $this->vendor->vendorInfos()->save(
            VendorInfo::factory()->make(['admin' => 0])
        );
        $this->vendor->load('vendorInfos');

        return $vendorInfo;
    }

    #[TestDox('syncVendor 逐商戶同步並以聯集回報三方未提供的遊戲')]
    public function test_sync_vendor_syncs_each_merchant_and_reports_missing_from_union(): void
    {
        $merchantB = $this->addVendorInfo();
        $this->createGame('VENDOR_REMOVED_GAME');

        // 兩商戶目錄互斥：任一商戶單獨差集都會誤報對方的遊戲，聯集不會
        $driver = $this->makeDriver([
            $this->vendorInfo->id => ['MERCHANT_A_GAME'],
            $merchantB->id => ['MERCHANT_B_GAME'],
        ]);

        $result = $this->syncService->syncVendor($this->vendor, $driver);

        $this->assertTrue($result->unionComplete);
        $this->assertCount(2, $result->merchantResults);
        $this->assertEquals(1, $result->merchantResults[0]->syncResult?->gamesInserted);
        $this->assertEquals(1, $result->merchantResults[1]->syncResult?->gamesInserted);
        $this->assertSame(['VENDOR_REMOVED_GAME' => 'game VENDOR_REMOVED_GAME'], $result->missingGameCodes);
        $this->assertDatabaseHas('games', ['vendor_id' => $this->vendor->id, 'game_type' => 'MERCHANT_A_GAME']);
        $this->assertDatabaseHas('games', ['vendor_id' => $this->vendor->id, 'game_type' => 'MERCHANT_B_GAME']);
    }

    #[TestDox('任一商戶清單缺漏時跳過「三方未提供」偵測，其餘商戶照常同步')]
    public function test_sync_vendor_skips_missing_detection_when_any_merchant_list_is_empty(): void
    {
        $merchantB = $this->addVendorInfo();
        $this->createGame('VENDOR_REMOVED_GAME');

        $driver = $this->makeDriver([
            $this->vendorInfo->id => ['MERCHANT_A_GAME'],
            $merchantB->id => [],
        ]);

        $result = $this->syncService->syncVendor($this->vendor, $driver);

        // 商戶 B 清單缺漏 → 聯集不完整、跳過偵測；商戶 A 照常同步
        $this->assertFalse($result->unionComplete);
        $this->assertSame([], $result->missingGameCodes);
        $this->assertNull($result->merchantResults[1]->syncResult);
        $this->assertDatabaseHas('games', ['vendor_id' => $this->vendor->id, 'game_type' => 'MERCHANT_A_GAME']);
    }

    #[TestDox('入口遊戲不參與同步也不列入「三方未提供」回報')]
    public function test_sync_vendor_excludes_entry_games_from_sync_and_missing_report(): void
    {
        $this->createGame('ENTRY_GAME');

        $driver = $this->makeDriver(
            [$this->vendorInfo->id => ['NORMAL_GAME']],
            entryCodes: ['ENTRY_GAME'],
        );

        $result = $this->syncService->syncVendor($this->vendor, $driver);

        $this->assertSame(['ENTRY_GAME'], $result->entryGameCodes);
        $this->assertSame([], $result->missingGameCodes);
        $this->assertEquals(1, $this->createdEntryGameStatus());
    }

    private function createdEntryGameStatus(): int
    {
        return (int) Game::query()
            ->where('vendor_id', $this->vendor->id)
            ->where('game_type', 'ENTRY_GAME')
            ->value('status');
    }

    #[TestDox('syncNow 單商失敗以 error 欄位回報並繼續，不中斷整批')]
    public function test_sync_now_isolates_vendor_failures(): void
    {
        // factory vendor 不在 config('vendor.driver') 名單 → driver 解析失敗，
        // syncNow 應以 error 欄位回報並正常返回，不拋例外中斷整批
        $results = $this->syncService->syncNow($this->vendor->id);

        $this->assertCount(1, $results);
        $this->assertSame($this->vendor->id, $results[0]->vendorId);
        $this->assertNotNull($results[0]->error);
    }

    #[TestDox('後商戶失敗不回滾前商戶已提交的寫入（per-商戶事務）')]
    public function test_sync_vendor_keeps_committed_merchants_when_later_merchant_fails(): void
    {
        $merchantB = $this->addVendorInfo();

        $driver = $this->makeDriver([
            $this->vendorInfo->id => ['MERCHANT_A_GAME'],
            $merchantB->id => new RuntimeException('三方 API 掛了'),
        ]);

        try {
            $this->syncService->syncVendor($this->vendor, $driver);
            $this->fail('商戶失敗應拋出中止');
        } catch (RuntimeException) {
            // per-商戶事務：商戶 A 已提交的寫入不因商戶 B 失敗回滾
            $this->assertDatabaseHas('games', ['vendor_id' => $this->vendor->id, 'game_type' => 'MERCHANT_A_GAME']);
        }
    }

    #[TestDox('聯集偵測以 vendor 為界，不誤報他商同名代碼')]
    public function test_find_games_missing_from_union_scoped_to_vendor(): void
    {
        $this->createGame('SHARED_CODE');

        $otherVendor = Vendor::factory()->create();
        $otherVendor->games()->save(new Game([
            'game_type' => 'SHARED_CODE',
            'name' => 'other vendor game',
            'type_id' => 0,
            'status' => 1,
            'open_window' => 0,
            'image' => 'https://example.invalid/img.png',
            'admin' => 0,
        ]));

        $missing = $this->syncService->findGamesMissingFromUnion(
            vendor: $this->vendor,
            unionGameCodes: ['SHARED_CODE'],
        );

        $this->assertSame([], $missing);
    }

    private const string ENTRY_CODE = 'ESPORT';

    #[TestDox('入口遊戲不因三方清單缺席而被停用或列入「三方未提供」回報')]
    public function test_entry_game_is_not_disabled_nor_reported_when_absent_from_vendor_list(): void
    {
        $entryGame = $this->createGame(self::ENTRY_CODE, name: '自建入口', typeId: GameTypeEnum::E_SPORTS->value);
        $staleGame = $this->createGame('999', name: '三方已下架', typeId: GameTypeEnum::E_SPORTS->value);

        // 三方只回一款正常遊戲，不含入口遊戲也不含已下架的 999
        $this->syncService->sync(
            gameInfos: [$this->makeGameInfo('VENDOR_GAME')],
            vendor: $this->vendor,
            vendorInfo: $this->vendorInfo,
            entryGameCodes: [self::ENTRY_CODE],
        );

        // 同步不自動停用任何遊戲（target/op_tickets#2871）
        $this->assertSame(1, (int) $entryGame->refresh()->status);
        $this->assertSame(1, (int) $staleGame->refresh()->status);

        // 「三方未提供」偵測只回報一般遊戲，入口遊戲被排除
        $missing = $this->syncService->findGamesMissingFromUnion(
            vendor: $this->vendor,
            unionGameCodes: ['VENDOR_GAME'],
            entryGameCodes: [self::ENTRY_CODE],
        );

        $this->assertArrayHasKey(999, $missing);
        $this->assertArrayNotHasKey(self::ENTRY_CODE, $missing);
    }

    #[TestDox('未宣告入口遊戲時，同名代碼照舊列入「三方未提供」回報')]
    public function test_entry_game_is_reported_missing_when_not_declared(): void
    {
        $entryGame = $this->createGame(self::ENTRY_CODE, name: '自建入口', typeId: GameTypeEnum::E_SPORTS->value);

        $this->syncService->sync(
            gameInfos: [$this->makeGameInfo('VENDOR_GAME')],
            vendor: $this->vendor,
            vendorInfo: $this->vendorInfo,
        );

        // 未宣告 → 不受保護，但同步本身也不停用，只由聯集偵測回報
        $this->assertSame(1, (int) $entryGame->refresh()->status);

        $missing = $this->syncService->findGamesMissingFromUnion(
            vendor: $this->vendor,
            unionGameCodes: ['VENDOR_GAME'],
        );

        $this->assertArrayHasKey(self::ENTRY_CODE, $missing);
    }

    #[TestDox('三方若回傳同名代碼，入口遊戲的名稱與類型也不被覆蓋')]
    public function test_entry_game_is_not_updated_even_when_vendor_returns_same_code(): void
    {
        $entryGame = $this->createGame(self::ENTRY_CODE, name: '自建入口', typeId: GameTypeEnum::E_SPORTS->value);

        $this->syncService->sync(
            gameInfos: [$this->makeVendorGameInfo(self::ENTRY_CODE)],
            vendor: $this->vendor,
            vendorInfo: $this->vendorInfo,
            entryGameCodes: [self::ENTRY_CODE],
        );

        $entryGame->refresh();

        $this->assertSame('自建入口', $entryGame->name);
        $this->assertSame(GameTypeEnum::E_SPORTS->value, (int) $entryGame->type_id);
        $this->assertSame(1, (int) $entryGame->status);
    }

    #[TestDox('入口遊戲代碼不會被當成新遊戲插入')]
    public function test_entry_game_code_is_not_inserted(): void
    {
        $this->syncService->sync(
            gameInfos: [$this->makeGameInfo(self::ENTRY_CODE)],
            vendor: $this->vendor,
            vendorInfo: $this->vendorInfo,
            entryGameCodes: [self::ENTRY_CODE],
        );

        $this->assertDatabaseMissing('games', [
            'vendor_id' => $this->vendor->id,
            'game_type' => self::ENTRY_CODE,
        ]);
    }

    #[TestDox('IA driver 宣告 HasEntryGames 並回傳 ESPORT 與 IA')]
    public function test_ia_driver_declares_entry_game_codes(): void
    {
        // driver 建構時會查 vendor 主檔，需先備妥 IA 的 vendor 列
        Vendor::factory()->create(['id' => VendorIdEnum::IA->value]);

        $driver = new IaDriver(VendorIdEnum::IA->value, null);

        $this->assertInstanceOf(HasEntryGames::class, $driver);
        $this->assertSame(['ESPORT', 'IA'], $driver->entryGameCodes());
    }

    #[Group('ticket-4423')]
    #[Group('ac-1 遊戲商回傳的既有遊戲代碼前後帶空白時，不會被當成新遊戲再新增一筆')]
    #[TestDox('遊戲商回傳的代碼前後帶空白時，比對得到資料庫裡沒有空白的同一款遊戲，不另外新增')]
    public function test_padded_code_matches_existing_game(): void
    {
        $this->createGame('abc123');

        $result = $this->syncService->syncVendor($this->vendor, $this->makeDriver([
            $this->vendorInfo->id => [' abc123 '],
        ]));

        $this->assertSame(0, $result->merchantResults[0]->syncResult?->gamesInserted);
        $this->assertSame(1, Game::query()->where('vendor_id', $this->vendor->id)->count());
        $this->assertSame([], $result->missingGameCodes, '去空白後就是同一款遊戲，不能被列為三方未提供');
    }

    #[Group('ticket-4423')]
    #[Group('ac-2 遊戲商回傳前後帶空白的新遊戲時，新增的那一筆遊戲代碼沒有前後空白')]
    #[TestDox('新遊戲的代碼前後帶空白時，寫進資料庫的是去掉空白後的代碼')]
    public function test_padded_new_code_is_inserted_trimmed(): void
    {
        $this->syncService->syncVendor($this->vendor, $this->makeDriver([
            $this->vendorInfo->id => [' new456 '],
        ]));

        $this->assertSame(
            ['new456'],
            Game::query()->where('vendor_id', $this->vendor->id)->pluck('game_type')->all(),
        );
    }

    #[Group('ticket-4423')]
    #[Group('ac-2 遊戲商回傳前後帶空白的新遊戲時，新增的那一筆遊戲代碼沒有前後空白')]
    #[TestDox('同一份清單裡去掉空白後相同的代碼只新增一筆')]
    public function test_codes_equal_after_trim_are_inserted_once(): void
    {
        $this->syncService->syncVendor($this->vendor, $this->makeDriver([
            $this->vendorInfo->id => ['dup789', ' dup789 '],
        ]));

        $this->assertSame(1, Game::query()->where('vendor_id', $this->vendor->id)->where('game_type', 'dup789')->count());
    }

    #[Group('ticket-4423')]
    #[Group('ac-1 遊戲商回傳的既有遊戲代碼前後帶空白時，不會被當成新遊戲再新增一筆')]
    #[TestDox('資料庫裡只有帶空白的舊資料時，同步比對得到它，不會新增沒有空白的那一筆')]
    public function test_legacy_padded_db_code_matches_trimmed_vendor_code(): void
    {
        $this->createGame(' legacy01 ');

        $result = $this->syncService->syncVendor($this->vendor, $this->makeDriver([
            $this->vendorInfo->id => [' legacy01 '],
        ]));

        $this->assertSame(0, $result->merchantResults[0]->syncResult?->gamesInserted);
        $this->assertSame(1, Game::query()->where('vendor_id', $this->vendor->id)->count());
        $this->assertSame([], $result->missingGameCodes);
    }

    #[Group('ticket-4423')]
    #[TestDox('因空白而重複的舊資料不會被列為三方未提供，同步時沿用沒有空白的那一筆')]
    public function test_legacy_duplicates_are_not_reported_missing(): void
    {
        // game_type 是 PAD SPACE collation，尾端空白在唯一索引上視同沒有，會重複的只有前導空白
        $clean = $this->createGame('dup01');
        $this->createGame(' dup01');

        $result = $this->syncService->syncVendor($this->vendor, $this->makeDriver([
            $this->vendorInfo->id => ['dup01'],
        ]));

        $this->assertSame(0, $result->merchantResults[0]->syncResult?->gamesInserted);
        $this->assertSame([], $result->missingGameCodes);
        $this->assertDatabaseHas('vendor_info_games', ['vendor_info_id' => $this->vendorInfo->id, 'game_id' => $clean->id]);
    }
}
