<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\General\ContextKeyEnum;
use App\Enums\QueueJobKeyEnum;
use App\Enums\QueueJobRunSourceEnum;
use App\Exceptions\VendorSetupException;
use App\Helpers\Common;
use App\Jobs\Contracts\HasQueueJobRun;
use App\Models\QueueJobRun;
use App\Services\BetRecordFetchService;
use App\Services\VendorService;
use App\Vendors\Contracts\SupportsBetRecordFetch;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * 拉取單一遊戲商注單（由 fetch_bet_record 排程逐商派發）
 *
 * 每次執行只跑一個時間片（BetRecordFetchService::fetchChunk），窗口有剩餘時
 * 派發接續任務（rate limit 預算用罄則延後重試當前片），不佔住 worker 等待；
 * --direct / --dry-run 的同步全窗口編排在 BetRecordFetchService，不經本 Job。
 *
 * 後台「拉取注單」操作頁不派發本 Job：一律經 RunAdminOperationJob 跑
 * fetch_bet_record --direct／--dry-run 同步執行（不經 queue，不留本 job 的執行列）。
 *
 * 可觀測性：flow 標記寫入 Context 供 GCP 以單一 filter 撈整個拉單流程，
 * tid + vendor_id 即單一遊戲商的完整補拉鏈（可經 CommandLog 反查觸發源）。
 *
 * 同商序列化用 WithoutOverlapping（處理時取鎖）而非 ShouldBeUnique（派發時取鎖）：
 * 接續任務與下一輪排程可能同時在列，派發時鎖會把接續 Job 直接丟棄、斷掉補拉鏈；
 * 縱使重疊執行，寫入仍以唯一鍵去重、三方呼叫由 RateLimiter 統一節流，安全但浪費預算。
 *
 * @knowledge rules/bet-record-pull
 */
class FetchVendorBetRecord implements HasQueueJobRun, ShouldQueue
{
    use Queueable;

    /** attempts 不設上限：WithoutOverlapping 取不到鎖會 release 重入，不可計為失敗 */
    public int $tries = 0;

    /** 業務例外不重試，交由下一輪排程重新覆蓋（窗口重疊 + 唯一鍵去重） */
    public int $maxExceptions = 1;

    public int $timeout = 600;

    /** 續跑 delay 下限（秒），避免 retryAfterSeconds 過小造成頻繁空轉 */
    private const int MIN_RETRY_DELAY_SECONDS = 60;

    public function __construct(
        public int $vendorId,
        public string $startTime,
        public string $endTime,
        public int $hop = 0,
        public bool $updateExisting = false,
        /** 指定商戶（null = 該商全部啟用中商戶） */
        public ?int $vendorInfoId = null,
        /** 沿用 1 小時內快取的三方回應（後台乾跑看完差異後接著正式跑，免重打三方） */
        public bool $useCache = false,
        /** 執行紀錄的觸發來源（本 job 僅排程派發，SCHEDULE；續棒沿用原值） */
        public QueueJobRunSourceEnum $source = QueueJobRunSourceEnum::SCHEDULE,
        /** 派發端以 QueueJobRun::createPending() 建立的執行列 id；收尾 update 同列 */
        public ?int $queueJobRunId = null,
    ) {
        // 走長時 job 專用連線（retry_after > timeout）：多時間片 × 多 vendor_info
        // 的三方呼叫可能跑超過預設連線的 retry_after=90 秒
        $this->onConnection('redis-long-running');
    }

    /**
     * 同一遊戲商同一時間僅一個 Job 在跑；取不到鎖 release 30 秒後重入，
     * 鎖存活略大於 timeout，worker 被砍時鎖可自行過期
     *
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping((string) $this->vendorId))
                ->releaseAfter(30)
                ->expireAfter(660),
        ];
    }

    public function queueJobRunKey(): QueueJobKeyEnum
    {
        return QueueJobKeyEnum::FETCH_BET_RECORD;
    }

    public function queueJobRunWorkKey(): string
    {
        return (string) $this->vendorId;
    }

    /** 本次執行的輸入片起點 */
    public function queueJobRunBucketTime(): string
    {
        return $this->startTime;
    }

    public function setQueueJobRunId(int $id): void
    {
        $this->queueJobRunId = $id;
    }

    public function handle(VendorService $vendorService, BetRecordFetchService $fetchService): void
    {
        // 每次執行時覆寫（同 worker 前一個 job 的殘留值不會滲入本鏈的 log）
        Context::add(ContextKeyEnum::FLOW->value, 'fetch_bet_record');

        try {
            $driver = $vendorService->getDriver($this->vendorId);

            if (!$driver instanceof SupportsBetRecordFetch) {
                Log::warning('FetchVendorBetRecord: driver 未實作 SupportsBetRecordFetch，略過', [
                    'vendor_id' => $this->vendorId,
                ]);
                QueueJobRun::finishRun($this->queueJobRunId, success: true, output: '略過：driver 未實作 SupportsBetRecordFetch');

                return;
            }

            $result = $fetchService->fetchChunk($this->vendorId, $driver, $this->startTime, $this->endTime, $this->updateExisting, $this->vendorInfoId, $this->useCache);

            Log::info('FetchVendorBetRecord 完成', [
                'vendor_id' => $this->vendorId,
                'start_time' => $this->startTime,
                'end_time' => $this->endTime,
                'hop' => $this->hop,
                'fetched' => $result->fetched,
                'inserted' => $result->inserted,
                'updated' => $result->updated,
                'pending_from' => $result->pendingFrom,
            ]);

            // 收尾 update 派發時建立的執行列（見 QueueJobRun 生命週期）。
            // rate limit 用罄而未推進窗口的執行同樣是成功收尾（確實打了三方），
            // delay 重排的下一棒有自己的列。
            QueueJobRun::finishRun($this->queueJobRunId, success: true, output: sprintf(
                '%s ~ %s：取得 %d 筆，新增 %d／更新 %d%s',
                $this->startTime,
                $this->endTime,
                $result->fetched,
                $result->inserted,
                $result->updated,
                $result->pendingFrom !== null ? "\n窗口尚有剩餘（{$result->pendingFrom} 起），已派發接續任務" : '',
            ));

            $this->dispatchRemainingIfNeeded($result->pendingFrom, $result->retryAfterSeconds);
        } catch (VendorSetupException $e) {
            // 該站未建此 vendor 主檔（多白標共用代碼，逐站資料不一定齊）：
            // 降級為 warning，避免每輪排程都產生 failed_jobs
            Log::warning('FetchVendorBetRecord: vendor 主檔未建立，略過', [
                'vendor_id' => $this->vendorId,
                'error' => $e->getMessage(),
            ]);
            QueueJobRun::finishRun($this->queueJobRunId, success: true, output: '略過：vendor 主檔未建立');
        } catch (Throwable $e) {
            Log::error('FetchVendorBetRecord 執行失敗', [
                'vendor_id' => $this->vendorId,
                'start_time' => $this->startTime,
                'end_time' => $this->endTime,
                'error' => $e->getMessage(),
                'trace' => Common::formatTrace($e),
            ]);

            throw $e;
        }
    }

    /**
     * timeout、worker 異常等框架層失敗的兜底：執行列標失敗
     */
    public function failed(?Throwable $exception): void
    {
        QueueJobRun::finishRun($this->queueJobRunId, success: false, output: $exception?->getMessage() ?? 'job failed');
    }

    /**
     * 窗口有剩餘時派發接續任務
     *
     * retryAfterSeconds > 0 = 預算用罄重試當前片（延後至預算釋放）；
     * = 0 = 片已完成接續下一片（預算尚有餘裕，立即派發）。
     */
    private function dispatchRemainingIfNeeded(?string $pendingFrom, int $retryAfterSeconds): void
    {
        if ($pendingFrom === null) {
            return;
        }

        $delay = $retryAfterSeconds > 0
            ? max($retryAfterSeconds, self::MIN_RETRY_DELAY_SECONDS)
            : 0;

        // 下一棒是新的一次執行，派發時建立自己的執行列
        $nextRunId = QueueJobRun::createPending(
            jobKey: QueueJobKeyEnum::FETCH_BET_RECORD,
            workKey: $this->vendorId,
            bucketTime: $pendingFrom,
            source: $this->source,
        );
        $pendingDispatch = self::dispatch($this->vendorId, $pendingFrom, $this->endTime, $this->hop + 1, $this->updateExisting, $this->vendorInfoId, $this->useCache, $this->source, $nextRunId);
        if ($delay > 0) {
            $pendingDispatch->delay(now()->addSeconds($delay));
        }

        Log::info('FetchVendorBetRecord: 窗口尚有剩餘，已派發接續任務', [
            'vendor_id' => $this->vendorId,
            'pending_from' => $pendingFrom,
            'end_time' => $this->endTime,
            'delay_seconds' => $delay,
            'next_hop' => $this->hop + 1,
        ]);
    }
}
