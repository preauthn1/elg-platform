<?php

declare(strict_types=1);

namespace App\Services;

use App\Data\GameInfoData;
use App\Data\GameListSyncResultData;
use App\Data\MerchantGameListSyncResultData;
use App\Data\Vendor\GetGameListParamsData;
use App\Data\VendorGameListSyncResultData;
use App\Helpers\Common;
use App\Jobs\SyncVendorGameList;
use App\Models\AgentGame;
use App\Models\AgentVendor;
use App\Models\Game;
use App\Models\GameName;
use App\Models\QueueJobRun;
use App\Models\Vendor;
use App\Models\VendorInfo;
use App\Models\VendorInfoGame;
use App\Vendors\Contracts\HasEntryGames;
use App\Vendors\Contracts\HasGameList;
use App\Vendors\Contracts\NeedsGameListSourceUrl;
use App\Vendors\Contracts\ShouldSyncGameList;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * 遊戲清單同步服務
 *
 * 將遊戲商 API 回傳的遊戲清單同步至 games / agent_games / vendor_info_games /
 * game_names 四張表。純新增：API 有、DB 無 → 新增；兩者皆有 → 僅補齊缺漏的
 * 關聯列，不更新任何既有欄位——既有資料一律由客服於後台手動維護。
 *
 * ⚠️ 「DB 有、API 無」不自動停用：games 表為 vendor 層共用，多商戶目錄互斥時逐商停用
 * 會互踩誤停（2026-08-20 VWorld PGSSW 361 款全滅事故）。改由 syncVendor() 收齊全部
 * 啟用商戶清單的聯集，以 findGamesMissingFromUnion() 偵測「三方未提供」，僅提示人工關閉。
 *
 * ⚠️ driver 標記 HasEntryGames 的入口遊戲於比對前自兩側移除，完全不參與同步——
 * 那是我方自建、三方永不回傳，照差集處理會被誤判成「DB 有、API 無」。
 *
 * ⚠️ 不更新既有資料是紅線：後台人工上／下架、備註、改名等屬營運資產，
 * 不得被三方回傳值覆蓋（target/op_tickets#2871）；三方值僅在新增時採用。
 *
 * @knowledge rules/game-list-sync
 */
class GameListSyncService extends AbstractVendorBatchService
{
    /**
     * 遊戲代碼比對前要去掉的前後字元：只去半形空白。
     *
     * 與 MySQL TRIM() 的預設行為一致（資料庫端的 game_type 都在 SQL 裡去空白），
     * PHP 這端若連 tab、換行一起去掉，兩邊結果會對不上。
     */
    private const string GAME_CODE_PADDING = ' ';

    /**
     * 同上游（同 driver）job 的派發間隔秒數
     *
     * 聚合遊戲商（Top55 / TTHSW 等）數十個子商共用同一三方 API 與限流額度
     * （Top55 實測 5 次/30 秒），整點並發派發會集體撞限流；
     * 同 driver 逐商錯開 15 秒，配合 token 快取原子鎖即可穩定低於額度。
     */
    private const int SAME_UPSTREAM_DISPATCH_INTERVAL_SECONDS = 15;

    /**
     * 排程模式：逐商派發遊戲清單同步 Job（同 driver 依序錯開 delay，避免打爆共用上游限流）
     *
     * @return list<array{vendor_id: int, vendor_name: string, delay: int, error?: string}> 派發清單
     */
    public function dispatchQueueSync(?int $specificVendorId = null): array
    {
        $delaysByDriver = [];

        return $this->loopVendors(
            callback: function (Vendor $vendor) use (&$delaysByDriver): array {
                $driverClass = config('vendor.driver')[$vendor->id] ?? '';
                $delay = $delaysByDriver[$driverClass] ?? 0;
                $delaysByDriver[$driverClass] = $delay + self::SAME_UPSTREAM_DISPATCH_INTERVAL_SECONDS;

                $job = new SyncVendorGameList($vendor->id);
                $job->setQueueJobRunId(QueueJobRun::createPending(
                    jobKey: $job->queueJobRunKey(),
                    workKey: $job->queueJobRunWorkKey(),
                    bucketTime: $job->queueJobRunBucketTime(),
                    source: $job->source,
                ));
                dispatch($job)->delay($delay);

                return ['vendor_id' => $vendor->id, 'vendor_name' => $vendor->name, 'delay' => $delay];
            },
            onError: function (Vendor $vendor, Throwable $e): array {
                Log::error('更新遊戲清單派發錯誤', [
                    'vendor_id' => $vendor->id,
                    'error' => $e->getMessage(),
                    'trace' => Common::formatTrace($e),
                ]);

                return ['vendor_id' => $vendor->id, 'vendor_name' => $vendor->name, 'delay' => 0, 'error' => $e->getMessage()];
            },
            specificVendorId: $specificVendorId,
        );
    }

    /**
     * 同步模式（--direct）：於當前程序逐商同步，單商失敗以 error 欄位回報並繼續
     *
     * @param  string|null  $gameListSourceUrl  清單來源網址，僅 driver 標 NeedsGameListSourceUrl 者需要
     * @return list<VendorGameListSyncResultData>
     */
    public function syncNow(?int $specificVendorId = null, ?string $gameListSourceUrl = null): array
    {
        return $this->loopVendors(
            callback: $this->syncVendorCallback($gameListSourceUrl),
            onError: $this->syncErrorCallback(),
            specificVendorId: $specificVendorId,
        );
    }

    /**
     * 乾跑模式（--dry-run）：同 syncNow，但整趟包事務、結束一律回滾
     *
     * @param  string|null  $gameListSourceUrl  清單來源網址，僅 driver 標 NeedsGameListSourceUrl 者需要
     * @return list<VendorGameListSyncResultData>
     */
    public function previewSync(?int $specificVendorId = null, ?string $gameListSourceUrl = null): array
    {
        return $this->previewLoop(
            callback: $this->syncVendorCallback($gameListSourceUrl),
            onError: $this->syncErrorCallback(),
            specificVendorId: $specificVendorId,
        );
    }

    /**
     * 排程全量模式僅納入 driver 標記 ShouldSyncGameList 的遊戲商
     */
    protected function applySchedulableVendorScope(Builder $query): void
    {
        $syncableVendorIds = array_keys(array_filter(
            config('vendor.driver', []),
            fn (string $driverClass): bool => is_subclass_of($driverClass, ShouldSyncGameList::class),
        ));

        $query->whereIn('id', $syncableVendorIds);
    }

    /**
     * @param  string|null  $gameListSourceUrl  清單來源網址，僅 driver 標 NeedsGameListSourceUrl 者需要
     * @return Closure(Vendor): VendorGameListSyncResultData
     */
    private function syncVendorCallback(?string $gameListSourceUrl = null): Closure
    {
        return function (Vendor $vendor) use ($gameListSourceUrl): VendorGameListSyncResultData {
            $vendorDriver = $this->vendorService->getDriver($vendor->id);

            if (!$vendorDriver instanceof HasGameList) {
                Log::warning('更新遊戲清單略過：driver 未實作 HasGameList', ['vendor_id' => $vendor->id]);

                return new VendorGameListSyncResultData(
                    vendorId: $vendor->id,
                    vendorName: $vendor->name,
                    error: 'driver 未實作 HasGameList',
                );
            }

            // 清單來源網址是憑證、不落 config，於此注入本次執行用的值；
            // 未提供時不在這裡擋，交由 driver 的 getGameList 拋出（錯誤訊息帶操作指引）
            if ($vendorDriver instanceof NeedsGameListSourceUrl && $gameListSourceUrl !== null) {
                $vendorDriver->setGameListSourceUrl($gameListSourceUrl);
            }

            return $this->syncVendor($vendor, $vendorDriver);
        };
    }

    /**
     * @return Closure(Vendor, Throwable): VendorGameListSyncResultData
     */
    private function syncErrorCallback(): Closure
    {
        return function (Vendor $vendor, Throwable $e): VendorGameListSyncResultData {
            Log::error('更新遊戲清單錯誤', [
                'vendor_id' => $vendor->id,
                'error' => $e->getMessage(),
                'trace' => Common::formatTrace($e),
            ]);

            return new VendorGameListSyncResultData(
                vendorId: $vendor->id,
                vendorName: $vendor->name,
                error: $e->getMessage(),
            );
        };
    }

    /**
     * 同步單一遊戲商的遊戲清單（vendor 級編排，排程 Job 與 --direct 命令共用）
     *
     * 事務邊界：三方 HTTP 在事務外、僅寫入包 per-商戶事務（慢速分頁清單不拖長
     * DB 事務），商戶間互不回滾。任一商戶同步失敗即拋出中止（不走偵測步驟）；
     * 「未取得清單」不視為失敗，僅標記聯集不完整、跳過偵測。
     * 呼叫端須先以「啟用商戶」約束載入 $vendor->vendorInfos，本方法不重查。
     *
     * @throws Throwable
     */
    public function syncVendor(
        Vendor $vendor,
        HasGameList $vendorDriver,
    ): VendorGameListSyncResultData {
        $entryGameCodes = $vendorDriver instanceof HasEntryGames ? $vendorDriver->entryGameCodes() : [];
        $groupNamesByVendorInfo = $this->agentGroupNamesByVendorInfoIds($vendor->vendorInfos->pluck('id')->all());

        $merchantResults = [];
        $unionGameCodes = [];
        $unionComplete = true;

        foreach ($vendor->vendorInfos as $vendorInfo) {
            $merchant = $this->syncMerchant(
                $vendorDriver,
                $vendorInfo,
                $vendor,
                $entryGameCodes,
                $groupNamesByVendorInfo[$vendorInfo->id] ?? [],
            );
            $merchantResults[] = $merchant;

            if ($merchant->syncResult === null) {
                $unionComplete = false;

                continue;
            }

            $unionGameCodes = array_merge(
                $unionGameCodes,
                array_map(fn (GameInfoData $gameInfo): string => $gameInfo->gameCode, $merchant->gameList),
            );
        }

        $missingGameCodes = [];

        if (!$unionComplete || $unionGameCodes === []) {
            $unionComplete = false;
            Log::warning('更新遊戲清單：商戶清單聯集不完整，本輪跳過「三方未提供」偵測', [
                'vendor_id' => $vendor->id,
            ]);
        } else {
            $missingGameCodes = $this->findGamesMissingFromUnion(
                vendor: $vendor,
                unionGameCodes: array_values(array_unique($unionGameCodes)),
                entryGameCodes: $entryGameCodes,
            );

            if ($missingGameCodes !== []) {
                Log::warning('更新遊戲清單：偵測到三方未提供的啟用中遊戲，請人工確認後於後台手動關閉', [
                    'vendor_id' => $vendor->id,
                    'missing_count' => count($missingGameCodes),
                    'missing_game_codes' => array_slice($missingGameCodes, 0, 50, true),
                ]);
            }
        }

        return new VendorGameListSyncResultData(
            vendorId: $vendor->id,
            vendorName: $vendor->name,
            merchantResults: $merchantResults,
            unionComplete: $unionComplete,
            missingGameCodes: $missingGameCodes,
            entryGameCodes: $entryGameCodes,
        );
    }

    /**
     * 各商戶服務的遊戲商個性化組別名稱（agent_group_type = 5），一次查齊供輸出顯示
     *
     * @param  list<int>  $vendorInfoIds
     * @return array<int, list<string>> vendor_info_id => 組別名稱清單
     */
    private function agentGroupNamesByVendorInfoIds(array $vendorInfoIds): array
    {
        if ($vendorInfoIds === []) {
            return [];
        }

        return AgentVendor::query()
            ->join('agents', 'agents.id', '=', 'agent_vendors.agent_id')
            ->join('agent_groups', function ($join) {
                $join->on('agent_groups.id', '=', 'agents.vendor_customize_group_id')
                    ->where('agent_groups.agent_group_type', 5);
            })
            ->whereIn('agent_vendors.vendor_info_id', $vendorInfoIds)
            ->distinct()
            ->get(['agent_vendors.vendor_info_id', 'agent_groups.name'])
            ->groupBy('vendor_info_id')
            ->map(fn ($rows) => $rows->pluck('name')->all())
            ->all();
    }

    /**
     * 同步單一商戶：抓取三方清單（事務外）後於 per-商戶事務內寫入
     */
    private function syncMerchant(
        HasGameList $vendorDriver,
        VendorInfo $vendorInfo,
        Vendor $vendor,
        array $entryGameCodes,
        array $agentGroupNames,
    ): MerchantGameListSyncResultData {
        try {
            $params = GetGameListParamsData::from(['vendorInfo' => $vendorInfo]);
            $result = $vendorDriver->getGameList($params);

            if (empty($result->gameList)) {
                Log::warning('更新遊戲清單：未獲取到任何遊戲資料', [
                    'vendor_id' => $vendor->id,
                    'vendor_info_id' => $vendorInfo->id,
                ]);

                return new MerchantGameListSyncResultData($vendorInfo->id, $vendorInfo->name, agentGroupNames: $agentGroupNames);
            }

            $gameList = self::trimAndDedupeGameCodes($result->gameList);

            DB::beginTransaction();
            try {
                $syncResult = $this->sync(
                    gameInfos: $gameList,
                    vendor: $vendor,
                    vendorInfo: $vendorInfo,
                    entryGameCodes: $entryGameCodes,
                );
                DB::commit();
            } catch (Throwable $e) {
                DB::rollBack();

                throw $e;
            }

            return new MerchantGameListSyncResultData($vendorInfo->id, $vendorInfo->name, $gameList, $syncResult, $agentGroupNames);
        } catch (Throwable $e) {
            Log::error('更新遊戲清單錯誤', [
                'vendor_id' => $vendor->id,
                'vendor_info_id' => $vendorInfo->id,
                'error' => $e->getMessage(),
                'trace' => Common::formatTrace($e),
            ]);

            throw $e;
        }
    }

    /**
     * 遊戲商回傳的遊戲代碼去掉前後的半形空白，並依代碼去重（保留第一筆）。
     *
     * 帶空白的代碼比對不到 games.game_type 裡沒有空白的同一款遊戲，會被當成新遊戲再新增一筆；
     * 去空白後若同一份清單出現兩筆相同代碼，同樣會新增兩筆，所以一併去重。
     * 同步、「三方未提供」聯集偵測與執行結果都用處理後的清單，三者看到的代碼一致。
     *
     * @param  array<int|string, GameInfoData>  $gameList
     * @return list<GameInfoData>
     */
    private static function trimAndDedupeGameCodes(array $gameList): array
    {
        $trimmed = [];

        foreach ($gameList as $gameInfo) {
            $gameInfo->gameCode = trim($gameInfo->gameCode, self::GAME_CODE_PADDING);
            $trimmed[$gameInfo->gameCode] ??= $gameInfo;
        }

        return array_values($trimmed);
    }

    /**
     * 同步單一商戶的遊戲清單至資料庫（純新增，不更新既有欄位）
     *
     * @param  GameInfoData[]  $gameInfos  遊戲商 API 回傳的遊戲清單
     * @param  Vendor  $vendor  遊戲商
     * @param  VendorInfo  $vendorInfo  商戶
     * @param  list<string>  $entryGameCodes  我方自建的入口遊戲代碼，完全不參與同步
     */
    public function sync(
        array $gameInfos,
        Vendor $vendor,
        VendorInfo $vendorInfo,
        array $entryGameCodes = [],
    ): GameListSyncResultData {
        $result = new GameListSyncResultData;

        // 先把入口遊戲從三方清單側移除；DB 側於 syncGames 內移除。
        // 兩側都拔掉後，後續四張表的差集比對都不會看到這些代碼。
        $gameInfos = array_values(array_filter(
            $gameInfos,
            fn (GameInfoData $gameInfo): bool => !in_array($gameInfo->gameCode, $entryGameCodes, true),
        ));

        $gameIds = $this->syncGames($gameInfos, $vendor, $result, $entryGameCodes);
        $this->syncAgentGames($gameIds, $result);
        $this->syncVendorInfoGames($gameIds, $vendorInfo, $vendor, $result);
        $this->syncGameNames($gameInfos, $result);

        return $result;
    }

    /**
     * 同步遊戲主表（差集比對）
     *
     * @param  GameInfoData[]  $gameInfos
     * @param  list<string>  $entryGameCodes  我方自建的入口遊戲代碼，排除於差集比對
     * @return int[] 本次處理的遊戲 ID 清單
     */
    private function syncGames(
        array $gameInfos,
        Vendor $vendor,
        GameListSyncResultData $result,
        array $entryGameCodes = [],
    ): array {
        $apiGameCodes = array_map(fn ($info) => $info->gameCode, $gameInfos);

        /** @var array<string, GameInfoData> $apiGameMap */
        $apiGameMap = [];
        foreach ($gameInfos as $gameInfo) {
            $apiGameMap[$gameInfo->gameCode] = $gameInfo;
        }

        // 入口遊戲不納入 DB 側清單，完全不參與差集比對
        // game_type 直接取去掉前後空白的值：舊資料可能帶空白寫入，不去空白就比對不到、會再新增一筆。
        // 只拿 id 與代碼，模型不會被存回，資料庫裡的值不變。
        // 去空白後重複（舊的重複資料）時，排序讓本來就沒有空白的那筆排最後，keyBy 取到的就是它。
        $dbGames = Game::query()
            ->select('id')
            ->selectRaw('TRIM(game_type) AS game_type')
            ->where('vendor_id', $vendor->id)
            ->when($entryGameCodes !== [], fn ($query) => $query->whereNotIn('game_type', $entryGameCodes))
            ->orderByRaw('game_type = TRIM(game_type)')
            ->get();
        $dbGameCodes = $dbGames->pluck('game_type')->toArray();
        $dbGameMap = $dbGames->keyBy('game_type');

        $gamesToInsert = array_diff($apiGameCodes, $dbGameCodes);
        $gamesToExisting = array_intersect($apiGameCodes, $dbGameCodes);

        $gameIds = [];

        // 新增
        if (!empty($gamesToInsert)) {
            $insertData = [];
            foreach ($gamesToInsert as $gameCode) {
                $gameInfo = $apiGameMap[$gameCode];
                $insertData[] = [
                    'vendor_id' => $vendor->id,
                    'game_type' => $gameInfo->gameCode,
                    'name' => $gameInfo->gameName,
                    'type_id' => $gameInfo->gameType,
                    'image' => $gameInfo->imageUrl,
                    'valid_bet_rate' => $vendor->valid_bet_rate,
                    'info' => $gameInfo->info,
                    'is_new' => 0,
                    'is_hot' => 0,
                    'status' => $gameInfo->status,
                    'open_window' => $gameInfo->openWindow,
                    'remark' => $gameInfo->remark,
                    'memo' => $gameInfo->memo,
                    // 0 = 系統同步寫入；後台人工異動會蓋上操作者 id（> 0），
                    // 「三方未提供」偵測以此區分是否已人工處理
                    'admin' => 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }

            Game::insert($insertData);
            $result->gamesInserted = count($insertData);

            foreach ($gamesToInsert as $gameCode) {
                $result->insertedGameCodes[$gameCode] = $apiGameMap[$gameCode]->gameName;
            }

            $newGames = Game::query()
                ->where('vendor_id', $vendor->id)
                ->whereIn('game_type', array_values($gamesToInsert))
                ->get();

            foreach ($newGames as $game) {
                $gameIds[] = $game->id;
                if (isset($apiGameMap[$game->game_type])) {
                    $apiGameMap[$game->game_type]->dbId = $game->id;
                }
            }
        }

        // 既有遊戲僅收集 ID（供補齊缺漏的關聯列），欄位一律不更新——由客服後台手動維護
        foreach ($gamesToExisting as $gameCode) {
            $gameInfo = $apiGameMap[$gameCode];
            $dbGame = $dbGameMap[$gameCode];

            $gameIds[] = $dbGame->id;
            $gameInfo->dbId = $dbGame->id;
        }

        return $gameIds;
    }

    /**
     * 找出「DB 啟用中、但全商戶三方清單聯集皆未提供」的遊戲（vendor 層差集，唯讀）
     *
     * 純偵測不寫入：這類遊戲代表三方目錄已下架，需由人工確認後於後台手動關閉。
     * 後台被異動過的遊戲（admin > 0）視為已人工處理，不再回報。
     * 必須在該 vendor 全部啟用商戶都成功取得清單後呼叫，聯集才完整；
     * 任一商戶清單缺漏（取清單失敗或回空）時，呼叫端應跳過本偵測，
     * 否則缺漏商戶專屬的遊戲會被誤報。
     *
     * @param  list<string>  $unionGameCodes  全部啟用商戶三方清單的聯集遊戲代碼
     * @param  list<string>  $entryGameCodes  我方自建的入口遊戲代碼，不參與比對
     * @return array<string, string> 三方未提供且仍啟用的遊戲（代碼 => 名稱）
     */
    public function findGamesMissingFromUnion(
        Vendor $vendor,
        array $unionGameCodes,
        array $entryGameCodes = [],
    ): array {
        // 空聯集代表沒有任何商戶清單可比對，全報等於雜訊，直接不報
        if ($unionGameCodes === []) {
            return [];
        }

        return Game::query()
            ->where('vendor_id', $vendor->id)
            // 舊資料的 game_type 可能帶空白，不去空白比對會把遊戲商其實有提供的遊戲誤報成「三方未提供」；
            // 函式包住欄位用不到 game_type 索引，但前面已用 vendor_id 收斂到單一遊戲商
            ->whereNotIn(DB::raw('TRIM(game_type)'), array_merge($unionGameCodes, $entryGameCodes))
            ->where('status', '!=', 0)
            // admin > 0 = 後台被人工異動過，視為已人工處理，不再回報
            ->where('admin', '<=', 0)
            ->pluck('name', 'game_type')
            ->all();
    }

    /**
     * 同步代理商遊戲關聯表（差集比對）
     *
     * @param  int[]  $gameIds
     */
    private function syncAgentGames(
        array $gameIds,
        GameListSyncResultData $result,
    ): void {
        if (empty($gameIds)) {
            return;
        }

        $existingAgentGames = AgentGame::query()
            ->where('agent_id', 1)
            ->whereIn('game_id', $gameIds)
            ->get()
            ->keyBy('game_id');

        $existingGameIds = $existingAgentGames->pluck('game_id')->toArray();
        $gamesToInsert = array_diff($gameIds, $existingGameIds);

        if (!empty($gamesToInsert)) {
            $insertData = [];
            foreach ($gamesToInsert as $gameId) {
                $insertData[] = [
                    'agent_id' => 1,
                    'game_id' => $gameId,
                    'status' => 0,
                    'admin' => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
            AgentGame::insert($insertData);
            $result->agentGamesInserted = count($insertData);
        }
    }

    /**
     * 同步商戶遊戲關聯表（差集比對）
     *
     * @param  int[]  $gameIds
     */
    private function syncVendorInfoGames(
        array $gameIds,
        VendorInfo $vendorInfo,
        Vendor $vendor,
        GameListSyncResultData $result,
    ): void {
        if (empty($gameIds)) {
            return;
        }

        $existingVendorInfoGames = VendorInfoGame::query()
            ->where('vendor_info_id', $vendorInfo->id)
            ->whereIn('game_id', $gameIds)
            ->get()
            ->keyBy('game_id');

        $existingGameIds = $existingVendorInfoGames->pluck('game_id')->toArray();
        $gamesToInsert = array_diff($gameIds, $existingGameIds);

        if (!empty($gamesToInsert)) {
            $insertData = [];
            foreach ($gamesToInsert as $gameId) {
                $insertData[] = [
                    'vendor_info_id' => $vendorInfo->id,
                    'game_id' => $gameId,
                    'valid_bet_rate' => $vendor->valid_bet_rate,
                    'status' => 0,
                    'bettable' => 1,
                    'is_new' => 1,
                    'join_bet_event' => 1,
                    'calc_flow_limit' => 1,
                    'admin' => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
            VendorInfoGame::insert($insertData);
            $result->vendorInfoGamesInserted = count($insertData);
        }
    }

    /**
     * 同步遊戲多語言名稱（差集比對）
     *
     * @param  GameInfoData[]  $gameInfos
     */
    private function syncGameNames(
        array $gameInfos,
        GameListSyncResultData $result,
    ): void {
        /** @var array<string, array{language_id: int, game_id: int, name: string}> $apiGameNames */
        $apiGameNames = [];
        $gameIds = [];

        foreach ($gameInfos as $gameInfo) {
            if (!isset($gameInfo->dbId) || empty($gameInfo->gameNames)) {
                continue;
            }

            $gameIds[] = $gameInfo->dbId;

            foreach ($gameInfo->gameNames as $langId => $name) {
                $key = $langId . '_' . $gameInfo->dbId;
                $apiGameNames[$key] = [
                    'language_id' => $langId,
                    'game_id' => $gameInfo->dbId,
                    'name' => $name,
                ];
            }
        }

        if (empty($apiGameNames)) {
            return;
        }

        $existingGameNames = GameName::query()
            ->whereIn('game_id', array_unique($gameIds))
            ->get();

        $existingKeys = [];
        foreach ($existingGameNames as $gameName) {
            $existingKeys[] = $gameName->language_id . '_' . $gameName->game_id;
        }

        $namesToInsert = array_diff(array_keys($apiGameNames), $existingKeys);

        if (!empty($namesToInsert)) {
            $insertData = [];
            foreach ($namesToInsert as $key) {
                $insertData[] = [
                    'language_id' => $apiGameNames[$key]['language_id'],
                    'game_id' => $apiGameNames[$key]['game_id'],
                    'name' => $apiGameNames[$key]['name'],
                    'admin' => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
            GameName::insert($insertData);
            $result->gameNamesInserted = count($insertData);
        }
    }
}
