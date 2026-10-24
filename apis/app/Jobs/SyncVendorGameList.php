<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\QueueJobKeyEnum;
use App\Enums\QueueJobRunSourceEnum;
use App\Jobs\Contracts\HasQueueJobRun;
use App\Models\QueueJobRun;
use App\Models\Vendor;
use App\Models\VendorMaintenances;
use App\Services\GameListSyncService;
use App\Services\VendorService;
use App\Vendors\Contracts\HasGameList;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * 同步單一遊戲商的遊戲清單（由 update_game_list 排程逐商派發）
 *
 * 各遊戲商獨立成 Job，可由多個 worker 並行消化；執行時重新檢查商狀態
 * 與維護狀態（入列到執行間有時間差）。同步編排收斂於
 * GameListSyncService::syncVendor()，與 --direct 命令共用。
 *
 * 後台「更新遊戲清單」操作頁不派發本 Job：一律經 RunAdminOperationJob 跑
 * update_game_list --direct 同步執行（不經 queue，不留本 job 的執行列）。
 *
 * @knowledge rules/game-list-sync
 */
class SyncVendorGameList implements HasQueueJobRun, ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /** 失敗不重試，交由下一輪排程重新派發 */
    public int $tries = 1;

    /** 分頁型清單（最多數十頁）需要較長的執行時間 */
    public int $timeout = 600;

    /**
     * 同商 Job 未消化完成前，重複派發直接略過。
     *
     * 取 55 分鐘（略短於 hourly 排程間隔）：唯一鎖在「取鎖後、入列失敗」時不會釋放，
     * 若取滿 60 分鐘會與下一輪整點排程對齊，造成同商連續整輪被略過。
     */
    public int $uniqueFor = 3300;

    public function __construct(
        public int $vendorId,
        /** 執行紀錄的觸發來源（本 job 僅排程派發，SCHEDULE） */
        public QueueJobRunSourceEnum $source = QueueJobRunSourceEnum::SCHEDULE,
        /** 派發端以 QueueJobRun::createPending() 建立的執行列 id；收尾 update 同列 */
        public ?int $queueJobRunId = null,
    ) {
        // 走長時 job 專用連線（retry_after > timeout）：預設連線 retry_after=90，
        // 分頁型清單（ClotPlaySW / TTHSW）常跑超過 90 秒，會被重複派發誤判 MaxAttemptsExceeded
        $this->onConnection('redis-long-running');
    }

    public function uniqueId(): string
    {
        return (string) $this->vendorId;
    }

    public function queueJobRunKey(): QueueJobKeyEnum
    {
        return QueueJobKeyEnum::UPDATE_GAME_LIST;
    }

    public function queueJobRunWorkKey(): string
    {
        return (string) $this->vendorId;
    }

    /** 派發當下整點，對齊 hourlyAt(12) 排程的格 */
    public function queueJobRunBucketTime(): string
    {
        return now()->format('Y-m-d H:00:00');
    }

    public function setQueueJobRunId(int $id): void
    {
        $this->queueJobRunId = $id;
    }

    public function handle(VendorService $vendorService, GameListSyncService $syncService): void
    {
        // 收尾 update 派發時建立的執行列（見 QueueJobRun 生命週期）。
        // 略過（商停用／維護中／driver 未實作）也是成功收尾——列在派發時已出生，
        // output 載明略過原因，讓「派發了但沒做事」在表上有跡可循；
        // 例外上拋走 failed()，該列標失敗。
        QueueJobRun::finishRun(
            $this->queueJobRunId,
            success: true,
            output: $this->sync($vendorService, $syncService),
        );
    }

    /**
     * timeout、worker 異常等框架層失敗的兜底：執行列標失敗
     */
    public function failed(?Throwable $exception): void
    {
        QueueJobRun::finishRun($this->queueJobRunId, success: false, output: $exception?->getMessage() ?? 'job failed');
    }

    /**
     * 同步本商遊戲清單；商停用／維護中／driver 未實作 HasGameList 時記 Log 後略過。
     *
     * @return string 執行摘要（寫入執行列的 output；統計細節仍以 Log 為準）
     *
     * @throws Throwable 任一商戶同步失敗
     */
    private function sync(VendorService $vendorService, GameListSyncService $syncService): string
    {
        $vendor = Vendor::query()
            ->where('id', $this->vendorId)
            ->where('status', 1)
            ->with(['vendorInfos' => fn ($q) => $q->where('status', 1)])
            ->select('id', 'name', 'code', 'valid_bet_rate')
            ->first();

        if ($vendor === null || $vendor->vendorInfos->isEmpty()) {
            Log::info('更新遊戲清單略過：遊戲商或商戶已停用', ['vendor_id' => $this->vendorId]);

            return '略過：遊戲商或商戶已停用';
        }

        if ($this->isUnderMaintenance()) {
            Log::info('更新遊戲清單略過：遊戲商維護中', ['vendor_id' => $this->vendorId]);

            return '略過：遊戲商維護中';
        }

        $vendorDriver = $vendorService->getDriver($vendor->id);

        if (!$vendorDriver instanceof HasGameList) {
            Log::warning('更新遊戲清單略過：driver 未實作 HasGameList', ['vendor_id' => $vendor->id]);

            return '略過：driver 未實作 HasGameList';
        }

        $vendorResult = $syncService->syncVendor($vendor, $vendorDriver);

        $summary = [];

        foreach ($vendorResult->merchantResults as $merchant) {
            if ($merchant->syncResult === null) {
                $summary[] = sprintf('商戶 %s：未取得清單', $merchant->vendorInfoName);

                continue;
            }

            $summary[] = sprintf(
                '商戶 %s：清單 %d 款，新增 遊戲 %d／商戶遊戲 %d／代理遊戲 %d／語系名稱 %d',
                $merchant->vendorInfoName,
                count($merchant->gameList),
                $merchant->syncResult->gamesInserted,
                $merchant->syncResult->vendorInfoGamesInserted,
                $merchant->syncResult->agentGamesInserted,
                $merchant->syncResult->gameNamesInserted,
            );

            Log::info('更新遊戲清單完成', [
                'vendor_id' => $vendor->id,
                'vendor_info_id' => $merchant->vendorInfoId,
                'game_count' => count($merchant->gameList),
                'games_inserted' => $merchant->syncResult->gamesInserted,
                'agent_games_inserted' => $merchant->syncResult->agentGamesInserted,
                'vendor_info_games_inserted' => $merchant->syncResult->vendorInfoGamesInserted,
                'game_names_inserted' => $merchant->syncResult->gameNamesInserted,
                'inserted_game_codes' => array_slice($merchant->syncResult->insertedGameCodes, 0, 50, true),
            ]);
        }

        if ($vendorResult->missingGameCodes !== []) {
            $summary[] = sprintf('⚠ 三方未提供但仍啟用 %d 款，請人工確認', count($vendorResult->missingGameCodes));
        }

        return implode("\n", $summary);
    }

    /**
     * 遊戲商是否在維護時段內（口徑同 AbstractVendorBatchService::maintenanceVendorIds）
     */
    private function isUnderMaintenance(): bool
    {
        $now = now()->toDateTimeString();

        return VendorMaintenances::query()
            ->where('vendor_id', $this->vendorId)
            ->where('status', 1)
            ->where('type', 1)
            ->where('start_time', '<=', $now)
            ->where('end_time', '>', $now)
            ->exists();
    }
}
