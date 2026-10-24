<?php

declare(strict_types=1);

namespace App\Console\Commands\Schedule;

use App\Data\GameListSyncResultData;
use App\Data\VendorGameListSyncResultData;
use App\Helpers\Common;
use App\Models\GameType;
use App\Services\GameListSyncService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * 更新遊戲商遊戲清單（純調度殼）
 *
 * 業務邏輯收斂於 GameListSyncService，本命令依 option 調用對應語意方法並渲染輸出：
 * 無 flag → dispatchQueueSync()、--direct → syncNow()（繞過 Job 唯一鎖，Olympus 固定帶上）、
 * --dry-run → previewSync()；--show-list 純呈現、僅在本層渲染。
 *
 * 純新增不更新：既有遊戲的任何欄位一律由客服於後台手動維護。
 *
 * php artisan update_game_list                            # 排程模式：逐商派發 queue
 * php artisan update_game_list --vendor-id=83 --direct    # 同步單商（後台救援用）
 * php artisan update_game_list --vendor-id=233 --dry-run  # 乾跑（不寫入資料）
 * php artisan update_game_list --vendor-id=144 --direct --sheet-url=<直鏈>  # PTSW 需帶清單連結
 *
 * @knowledge rules/game-list-sync
 */
class UpdateVendorGameList extends Command
{
    protected $signature = 'update_game_list
                            {--vendor-id= : 指定要更新的遊戲商 ID}
                            {--direct : 同步執行不派發 queue（後台救援用，繞過 Job 唯一鎖）}
                            {--dry-run : 乾跑模式，不寫入資料庫}
                            {--show-list : 額外列出三方回傳的完整遊戲清單（乾跑與正式執行皆生效）}
                            {--sheet-url= : 遊戲清單來源網址；僅 PTSW(144) 這類三方不給 API 的遊戲商需要}';

    protected $description = '更新遊戲商的遊戲清單';

    public function __construct(private readonly GameListSyncService $syncService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $vendorId = $this->option('vendor-id') !== null ? (int) $this->option('vendor-id') : null;
        // 清單來源網址是憑證（不需登入即可下載該廠商全站遊戲代碼），刻意不落 config／env，
        // 由操作者於執行當下帶入，只存在於本次執行的記憶體中（見 NeedsGameListSourceUrl）
        $sheetUrl = $this->option('sheet-url') !== null ? trim((string) $this->option('sheet-url')) : null;

        try {
            if ($this->option('dry-run')) {
                $this->warn('=== 乾跑模式：所有操作將回滾 ===');
                $this->renderVendorResults($this->syncService->previewSync($vendorId, $sheetUrl));
                $this->info('=== 乾跑模式結束：所有變更已回滾 ===');
            } elseif ($this->option('direct')) {
                $this->renderVendorResults($this->syncService->syncNow($vendorId, $sheetUrl));
            } else {
                $this->renderDispatched($this->syncService->dispatchQueueSync($vendorId));
            }
        } catch (Throwable $e) {
            $this->error($e->getMessage());
            Log::error('UpdateVendorGameList 錯誤', [
                'error' => $e->getMessage(),
                'trace' => Common::formatTrace($e),
            ]);

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * @param  list<array{vendor_id: int, vendor_name: string, delay: int, error?: string}>  $dispatched
     */
    private function renderDispatched(array $dispatched): void
    {
        if ($dispatched === []) {
            $this->warn('沒有可處理的遊戲商');

            return;
        }

        $failed = 0;

        foreach ($dispatched as $item) {
            if (isset($item['error'])) {
                $failed++;
                $this->error("派發失敗: {$item['vendor_name']} (ID: {$item['vendor_id']})，{$item['error']}");

                continue;
            }

            $this->line("已派發: {$item['vendor_name']} (ID: {$item['vendor_id']})"
                . ($item['delay'] > 0 ? "，延遲 {$item['delay']} 秒" : ''));
        }

        $this->info('共派發 ' . (count($dispatched) - $failed) . ' 個遊戲清單同步任務' . ($failed > 0 ? "，{$failed} 個派發失敗" : ''));
    }

    /**
     * @param  list<VendorGameListSyncResultData>  $vendorResults
     */
    private function renderVendorResults(array $vendorResults): void
    {
        if ($vendorResults === []) {
            $this->warn('沒有可處理的遊戲商');

            return;
        }

        foreach ($vendorResults as $vendorResult) {
            $this->newLine();
            $this->info("處理遊戲商: {$vendorResult->vendorName} (ID: {$vendorResult->vendorId})");
            $this->renderVendorResult($vendorResult);
            $this->info("完成遊戲商: {$vendorResult->vendorName} (ID: {$vendorResult->vendorId})");
        }
    }

    private function renderVendorResult(VendorGameListSyncResultData $vendorResult): void
    {
        if ($vendorResult->error !== null) {
            $this->error("⚠ {$vendorResult->error}");

            return;
        }

        if ($vendorResult->entryGameCodes !== []) {
            $this->line('排除我方自建入口遊戲: ' . implode(', ', $vendorResult->entryGameCodes));
        }

        foreach ($vendorResult->merchantResults as $index => $merchant) {
            if ($index > 0) {
                $this->newLine();
                $this->line(str_repeat('─', 60));
                $this->newLine();
            }

            $groupNames = $merchant->agentGroupNames !== []
                ? implode('、', $merchant->agentGroupNames)
                : '未綁定遊戲商組別';
            // 顏色只在終端機生效，Olympus 純文字輸出靠 ▶ 符號維持醒目
            $this->line("<options=bold;fg=cyan>▶ 更新組別: {$groupNames}</>");

            if ($merchant->syncResult === null) {
                $this->warn('⚠ 未獲取到任何遊戲資料');

                continue;
            }

            $this->line('取得遊戲清單數量: ' . count($merchant->gameList));

            // 全量清單動輒數百行會淹沒摘要，僅在明確要求時列出（乾跑與正式執行皆生效）
            if ($this->option('show-list')) {
                // 一併列出圖片網址：客服要能直接開連結確認縮圖存不存在。
                // 部分遊戲商不回圖片、由我方依代碼推導路徑（如 PTSW 的 CDN 規則），
                // 推導錯或三方沒放圖時前台就是破圖，只有這裡看得出來
                $this->table(
                    ['GameCode', 'GameName', 'GameType', 'ImageUrl'],
                    array_map(fn ($g) => [
                        $g->gameCode,
                        $g->gameName,
                        $this->gameTypeName($g->gameType),
                        $g->imageUrl,
                    ], $merchant->gameList)
                );
            }

            $this->outputSyncResult($merchant->syncResult);
        }

        $this->outputMissingFromUnion($vendorResult);
    }

    /**
     * 輸出「三方未提供但仍啟用」的偵測結果
     */
    private function outputMissingFromUnion(VendorGameListSyncResultData $vendorResult): void
    {
        if (!$vendorResult->unionComplete) {
            $this->warn('⚠ 商戶清單聯集不完整，本輪跳過「三方未提供」偵測');

            return;
        }

        if ($vendorResult->missingGameCodes === []) {
            return;
        }

        $total = count($vendorResult->missingGameCodes);
        $shown = array_slice($vendorResult->missingGameCodes, 0, self::MAX_LISTED_GAME_CODES, true);

        $this->warn("⚠ 三方未提供但仍啟用中的遊戲共 {$total} 款，請人工確認後於後台手動關閉:");
        // 純數字代碼會被 PHP 轉成 int key，統一轉回字串
        $this->table(
            ['GameCode', 'GameName'],
            array_map(
                fn (int|string $gameCode, string $gameName): array => [(string) $gameCode, $gameName],
                array_keys($shown),
                array_values($shown),
            ),
        );

        if ($total > count($shown)) {
            $this->warn('（僅列前 ' . count($shown) . " 款，共 {$total} 款）");
        }
    }

    /**
     * 輸出同步結果統計
     */
    private function outputSyncResult(GameListSyncResultData $result): void
    {
        // 給客服看的口語標題：遊戲=games、商戶遊戲=vendor_info_games、
        // 代理遊戲=agent_games、遊戲語系名稱=game_names
        $this->table(
            ['資料類型', '遊戲', '商戶遊戲', '代理遊戲', '遊戲語系名稱'],
            [[
                '新增',
                $result->gamesInserted,
                $result->vendorInfoGamesInserted,
                $result->agentGamesInserted,
                $result->gameNamesInserted,
            ]],
        );

        if (!empty($result->insertedGameCodes)) {
            $this->line('↳ 新增遊戲: ' . $this->formatGameCodes($result->insertedGameCodes));
        }

        $this->line('<options=bold;fg=green>✓ 同步完成</>');
    }

    /** @var array<int, string>|null game_types 的 id => 中文名稱（首次使用時載入） */
    private ?array $gameTypeNames = null;

    /**
     * 遊戲類別的中文名稱；表外類別（三方回傳的未知 type）以原數值顯示
     */
    private function gameTypeName(int $typeId): string
    {
        $this->gameTypeNames ??= GameType::query()->pluck('name', 'id')->all();

        return $this->gameTypeNames[$typeId] ?? (string) $typeId;
    }

    /**
     * 遊戲代碼清單顯示上限（防單次異動數百款洗版）
     */
    private const int MAX_LISTED_GAME_CODES = 50;

    /**
     * @param  array<string, string>  $codes  代碼 => 名稱
     */
    private function formatGameCodes(array $codes): string
    {
        $shown = array_slice($codes, 0, self::MAX_LISTED_GAME_CODES, true);

        $labels = [];
        foreach ($shown as $gameCode => $gameName) {
            $labels[] = filled($gameName) ? "{$gameCode} ({$gameName})" : (string) $gameCode;
        }

        $suffix = count($codes) > count($shown) ? '（僅列前 ' . count($shown) . ' 款，共 ' . count($codes) . ' 款）' : '';

        return implode(', ', $labels) . $suffix;
    }
}
