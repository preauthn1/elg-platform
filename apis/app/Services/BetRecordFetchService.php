<?php

declare(strict_types=1);

namespace App\Services;

use App\Data\BaseTotalBetRecordData;
use App\Data\BetRecordFetchChunkResultData;
use App\Data\InsertedBetRecordData;
use App\Data\VendorBetRecordFetchResultData;
use App\Enums\General\CacheTtl;
use App\Enums\General\ContextKeyEnum;
use App\Helpers\Common;
use App\Jobs\FetchVendorBetRecord;
use App\Models\AdminOperationLog;
use App\Models\Game;
use App\Models\QueueJobRun;
use App\Models\TotalBetRecord;
use App\Models\Vendor;
use App\Models\VendorInfo;
use App\Models\VendorInfoGame;
use App\Vendors\Contracts\ShouldScheduleBetRecordFetch;
use App\Vendors\Contracts\SupportsBetRecordFetch;
use App\Vendors\Models\AbstractBetRecord;
use Carbon\Carbon;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use LogicException;
use Throwable;

/**
 * 拉注單服務：fetch_bet_record 的三種執行模式（排程派發 Job／--direct 同步
 * 全窗口／--dry-run 只比對不寫入的乾跑）＋ Job 與 --direct 共用的單片拉取 fetchChunk()。
 *
 * 排程全量範圍 = 實作 ShouldScheduleBetRecordFetch marker 的遊戲商；
 * 指定 vendor 時放寬至能力契約 SupportsBetRecordFetch（未排程者手動補拉）。
 *
 * @knowledge rules/bet-record-pull
 */
class BetRecordFetchService extends AbstractVendorBatchService
{
    private const int INSERT_CHUNK_SIZE = 500;

    /** 乾跑清單（新增／更新）每類最多列出的筆數，避免整天十幾萬筆灌爆執行輸出 */
    public const int PREVIEW_LIST_LIMIT = 500;

    /**
     * 三方原始回應快取 TTL：乾跑後在此時限內跑正式模式直接沿用、不再打三方。
     * 整天窗口受 rate limit 乾跑本身可能跑十幾分鐘，再加人工看差異，30 分鐘不夠；
     * 陳舊風險由每小時排程照常重拉兜底。
     */
    private const int RAW_CACHE_TTL = CacheTtl::ONE_HOUR->value;

    /**
     * 等 rate limit 預算釋放時，每幾秒醒來看一次後台「中斷」旗標。
     *
     * 取 5 與 TransferMoneyBack::CANCEL_POLL_SECONDS 一致：人按下中斷後多等幾秒無感，
     * 每片之間本來就會查一次，這裡只負責把最長 300 秒的空窗期切開。
     */
    private const int CANCEL_POLL_SECONDS = 5;

    /** 乾跑時收集新增／更新清單；正式模式不收集 */
    private bool $collectPreview = false;

    /** @var list<array{key: string, amounts: array<string, string>}> 當前遊戲商窗口內將新增的注單 */
    private array $previewNew = [];

    /** @var list<array{key: string, changes: array<string, array{from: string, to: string}>}> 當前遊戲商窗口內金額有異動的既有列 */
    private array $previewChanged = [];

    /** @var list<array{key: string, amounts: array<string, string>}> 反向對帳：DB 有、整個窗口三方都沒回的既有列 */
    private array $previewMissing = [];

    /** 反向對帳命中總筆數（previewMissing 只留前 PREVIEW_LIST_LIMIT 筆） */
    private int $previewMissingTotal = 0;

    /** @var array<string, true> 乾跑時累積整個窗口三方回過的唯一鍵，供反向對帳比對 */
    private array $windowFetchedKeys = [];

    /**
     * 單片跑完的回呼（同步模式專用）。
     *
     * 同步模式把整個窗口逐片跑完才回傳結果，中途沒有任何輸出：PP 這類一次只回 10 分鐘窗口的
     * 遊戲商，拉整天就是 144 片、十幾分鐘不吭聲，操作者看不出是在跑還是卡住（2026-09-23 實際被問）。
     * 由呼叫端決定要不要把進度印出來，Service 自己不碰 console。
     *
     * @var (Closure(Vendor, int, int, string, BetRecordFetchChunkResultData): void)|null
     */
    private ?Closure $onChunkComplete = null;

    /**
     * 設定單片進度回呼；傳 null 取消。
     *
     * 回呼參數：遊戲商、本片序號（1 起算）、預估總片數、本片起點（UTC）、本片結果。
     *
     * @param  (Closure(Vendor, int, int, string, BetRecordFetchChunkResultData): void)|null  $callback
     */
    public function onChunkComplete(?Closure $callback): self
    {
        $this->onChunkComplete = $callback;

        return $this;
    }

    /**
     * 排程模式：逐商派發 FetchVendorBetRecord Job
     *
     * @return list<array{vendor_id: int, vendor_name: string, error?: string}>
     */
    public function dispatchQueueFetch(?int $specificVendorId, string $startTime, string $endTime, bool $updateExisting = false, ?int $vendorInfoId = null): array
    {
        return $this->loopVendors(
            callback: function (Vendor $vendor) use ($startTime, $endTime, $updateExisting, $vendorInfoId): array {
                $job = new FetchVendorBetRecord($vendor->id, $startTime, $endTime, 0, $updateExisting, $vendorInfoId);
                $job->setQueueJobRunId(QueueJobRun::createPending(
                    jobKey: $job->queueJobRunKey(),
                    workKey: $job->queueJobRunWorkKey(),
                    bucketTime: $job->queueJobRunBucketTime(),
                    source: $job->source,
                ));
                dispatch($job);

                return ['vendor_id' => $vendor->id, 'vendor_name' => $vendor->name];
            },
            onError: function (Vendor $vendor, Throwable $e): array {
                Log::error('拉取注單派發錯誤', [
                    'vendor_id' => $vendor->id,
                    'error' => $e->getMessage(),
                    'trace' => Common::formatTrace($e),
                ]);

                return ['vendor_id' => $vendor->id, 'vendor_name' => $vendor->name, 'error' => $e->getMessage()];
            },
            specificVendorId: $specificVendorId,
        );
    }

    /**
     * 同步模式（--direct）：逐商逐片跑完整個窗口，不派發 queue（救援用）
     *
     * @return list<VendorBetRecordFetchResultData>
     */
    public function fetchNow(?int $specificVendorId, string $startTime, string $endTime, bool $updateExisting = false, ?int $vendorInfoId = null, bool $useCache = false): array
    {
        Context::add(ContextKeyEnum::FLOW->value, 'fetch_bet_record');
        $this->collectPreview = false;

        return $this->loopVendors(
            callback: $this->fetchVendorCallback($startTime, $endTime, waitOnRateLimit: true, updateExisting: $updateExisting, vendorInfoId: $vendorInfoId, useCache: $useCache),
            onError: $this->fetchErrorCallback(),
            specificVendorId: $specificVendorId,
        );
    }

    /**
     * 乾跑模式（--dry-run）：同 fetchNow 打三方並與 DB 比對，但不寫入任何資料
     * （注單、TBR 皆不動，故不需事務回滾），改為收集將新增／金額有異動的注單清單供人工檢核。
     * 沒有事務要撐，rate limit 用罄時與正式模式一樣原地等待，整個窗口跑完清單才完整。
     *
     * @return list<VendorBetRecordFetchResultData>
     */
    public function previewFetch(?int $specificVendorId, string $startTime, string $endTime, bool $updateExisting = false, ?int $vendorInfoId = null, bool $useCache = false): array
    {
        Context::add(ContextKeyEnum::FLOW->value, 'fetch_bet_record');
        $this->collectPreview = true;

        try {
            return $this->loopVendors(
                callback: $this->fetchVendorCallback($startTime, $endTime, waitOnRateLimit: true, updateExisting: $updateExisting, vendorInfoId: $vendorInfoId, useCache: $useCache),
                onError: $this->fetchErrorCallback(),
                specificVendorId: $specificVendorId,
            );
        } finally {
            $this->collectPreview = false;
        }
    }

    /**
     * 維護中照常拉單：維護只擋玩家下注，已結算注單仍需持續拉取
     */
    protected function excludesMaintenanceVendors(): bool
    {
        return false;
    }

    /**
     * 排程全量模式僅納入 driver 標記 ShouldScheduleBetRecordFetch 的遊戲商
     */
    protected function applySchedulableVendorScope(Builder $query): void
    {
        $fetchableVendorIds = array_keys(array_filter(
            config('vendor.driver', []),
            fn (string $driverClass): bool => is_subclass_of($driverClass, ShouldScheduleBetRecordFetch::class),
        ));

        $query->whereIn('id', $fetchableVendorIds);
    }

    /**
     * @return Closure(Vendor): VendorBetRecordFetchResultData
     */
    private function fetchVendorCallback(string $startTime, string $endTime, bool $waitOnRateLimit, bool $updateExisting = false, ?int $vendorInfoId = null, bool $useCache = false): Closure
    {
        return function (Vendor $vendor) use ($startTime, $endTime, $waitOnRateLimit, $updateExisting, $vendorInfoId, $useCache): VendorBetRecordFetchResultData {
            $driver = $this->vendorService->getDriver($vendor->id);

            if (!$driver instanceof SupportsBetRecordFetch) {
                Log::warning('拉取注單略過：driver 未實作 SupportsBetRecordFetch', ['vendor_id' => $vendor->id]);

                return new VendorBetRecordFetchResultData(
                    vendorId: $vendor->id,
                    vendorName: $vendor->name,
                    error: 'driver 未實作 SupportsBetRecordFetch',
                );
            }

            return $this->fetchVendorWindow($vendor, $driver, $startTime, $endTime, $waitOnRateLimit, $updateExisting, $vendorInfoId, $useCache);
        };
    }

    /**
     * @return Closure(Vendor, Throwable): VendorBetRecordFetchResultData
     */
    private function fetchErrorCallback(): Closure
    {
        return function (Vendor $vendor, Throwable $e): VendorBetRecordFetchResultData {
            Log::error('拉取注單錯誤', [
                'vendor_id' => $vendor->id,
                'error' => $e->getMessage(),
                'trace' => Common::formatTrace($e),
            ]);

            return new VendorBetRecordFetchResultData(
                vendorId: $vendor->id,
                vendorName: $vendor->name,
                error: $e->getMessage(),
            );
        };
    }

    /**
     * 同步跑完單一遊戲商的整個窗口（逐片呼叫 fetchChunk）
     *
     * rate limit 預算用罄時：$waitOnRateLimit = true 原地 sleep 等預算釋放續跑；
     * false 則中止並以 pendingFrom 回報剩餘起點。
     *
     * 每片之間輪詢後台「中斷」旗標，等預算的空窗期也拆段輪詢；收到就停在片邊界、
     * 以 pendingFrom 回報續跑起點，cancelled 標記讓呼叫端把它跟 rate limit 中止區分開。
     *
     * @throws Throwable
     */
    private function fetchVendorWindow(
        Vendor $vendor,
        SupportsBetRecordFetch $driver,
        string $startTime,
        string $endTime,
        bool $waitOnRateLimit,
        bool $updateExisting = false,
        ?int $vendorInfoId = null,
        bool $useCache = false,
    ): VendorBetRecordFetchResultData {
        $fetched = 0;
        $inserted = 0;
        $updated = 0;
        $chunks = 0;
        $pendingFrom = null;
        $cancelled = false;
        $from = $startTime;
        // 由後台（RunAdminOperationJob）觸發時才有值；命令列直接跑沒有操作紀錄可讀，不輪詢
        $operationLogId = (int) Context::getHidden(ContextKeyEnum::ADMIN_OPERATION_LOG_ID->value);
        // 預估總片數：minStartTime 收斂起點時實際會更少，只供進度顯示，不參與流程判斷
        $totalChunks = (int) ceil(
            Carbon::parse($startTime, 'UTC')->diffInMinutes(Carbon::parse($endTime, 'UTC'))
            / max(1, $driver->betRecordApiWindowMinutes())
        );
        $this->previewNew = [];
        $this->previewChanged = [];
        $this->previewMissing = [];
        $this->previewMissingTotal = 0;
        $this->windowFetchedKeys = [];

        do {
            $result = $this->fetchChunk($vendor->id, $driver, $from, $endTime, $updateExisting, $vendorInfoId, $useCache);
            $inserted += $result->inserted;
            $updated += $result->updated;

            // rate limit 中止代表這片沒跑完、整片會重拉：不計片數、不累加三方筆數，
            // 否則同一時間片被算成好幾片（進度編號超出總片數），重拉的筆數也會重複計。
            // 已寫入的 inserted / updated 照常累加——重拉以唯一鍵去重，不會再算第二次
            $rateLimited = $result->retryAfterSeconds > 0;

            if (!$rateLimited) {
                $fetched += $result->fetched;
                $chunks++;
            }

            Log::info($rateLimited ? 'BetRecordFetchService 單片中止（rate limit）' : 'BetRecordFetchService 單片完成', [
                'vendor_id' => $vendor->id,
                'start_time' => $from,
                'end_time' => $endTime,
                'chunk' => $chunks,
                'fetched' => $result->fetched,
                'inserted' => $result->inserted,
                'updated' => $result->updated,
                'pending_from' => $result->pendingFrom,
                'retry_after_seconds' => $result->retryAfterSeconds,
            ]);

            if ($this->onChunkComplete !== null) {
                ($this->onChunkComplete)($vendor, $chunks, $totalChunks, $from, $result);
            }

            if ($result->pendingFrom === null) {
                break;
            }

            // 後台「中斷」：旗標由操作頁寫進 admin_operation_logs.cancel_requested_at，這裡每片輪詢。
            // 停點落在片與片之間，已完成的片都已寫入，以 pendingFrom 回報續跑起點
            if (AdminOperationLog::isCancelRequested($operationLogId)) {
                $cancelled = true;
                $pendingFrom = $result->pendingFrom;

                break;
            }

            if ($result->retryAfterSeconds > 0) {
                if (!$waitOnRateLimit) {
                    $pendingFrom = $result->pendingFrom;

                    break;
                }

                // +1 秒緩衝避免預算釋放的臨界誤差
                if (!$this->sleepUnlessCancelled($result->retryAfterSeconds + 1, $operationLogId)) {
                    $cancelled = true;
                    $pendingFrom = $result->pendingFrom;

                    break;
                }
            }

            $from = $result->pendingFrom;
        } while (true);

        // 反向對帳只在乾跑做，且窗口必須完整跑完（rate limit 中止時三方鍵集合不全，會誤報）
        if ($this->collectPreview && $pendingFrom === null) {
            $this->collectMissingFromThirdParty($driver, $startTime, $endTime, $vendorInfoId);
        }

        return new VendorBetRecordFetchResultData(
            vendorId: $vendor->id,
            vendorName: $vendor->name,
            fetched: $fetched,
            inserted: $inserted,
            updated: $updated,
            chunks: $chunks,
            pendingFrom: $pendingFrom,
            cancelled: $cancelled,
            previewNew: $this->previewNew,
            previewChanged: $this->previewChanged,
            missing: $this->previewMissingTotal,
            previewMissing: $this->previewMissing,
        );
    }

    /**
     * 等預算釋放，但每 CANCEL_POLL_SECONDS 秒醒來看一次後台「中斷」旗標。
     *
     * 撞 rate limit 要等上整個窗口（ACE333 為 300 秒），一次睡飽會讓「中斷」按下去
     * 最久等五分鐘才有反應，等於按了沒反應。
     *
     * @return bool 睡滿回 true；被中斷回 false
     */
    private function sleepUnlessCancelled(int $seconds, int $operationLogId): bool
    {
        // 沒有操作紀錄可輪詢（命令列直接跑）時，省掉拆段的空轉
        if ($operationLogId <= 0) {
            sleep($seconds);

            return true;
        }

        for ($slept = 0; $slept < $seconds; $slept += self::CANCEL_POLL_SECONDS) {
            sleep(min(self::CANCEL_POLL_SECONDS, $seconds - $slept));

            if (AdminOperationLog::isCancelRequested($operationLogId)) {
                return false;
            }
        }

        return true;
    }

    /**
     * 反向對帳（乾跑提示）：列出派彩時間落在窗口內、但整個窗口三方都沒回的既有注單列。
     *
     * 拉單本身只做「三方 → DB」方向（缺單補寫、金額異動更新），反向這側純提示不影響寫入。
     * 三個已知限制：
     * - 注單表沒有商戶欄位，指定 --vendor-info 時無法排除其他商戶的列，故直接略過不做。
     * - Model 未宣告 payoutTimeColumn（如 ACE333）無法界定窗口內的既有列，同樣略過。
     * - 窗口邊界仍可能誤報：DB 派彩時間在窗口內、三方 updated_at 落在窗口外的注單會被列出。
     */
    private function collectMissingFromThirdParty(
        SupportsBetRecordFetch $driver,
        string $startTime,
        string $endTime,
        ?int $vendorInfoId,
    ): void {
        if ($vendorInfoId !== null) {
            return;
        }

        $modelClass = $driver->getBetRecordModelClass();
        /** @var AbstractBetRecord $model */
        $model = new $modelClass;
        $payoutTimeColumn = $model->getPayoutTimeColumn();

        if ($payoutTimeColumn === null) {
            return;
        }

        $uniqueKey = $driver->betRecordUniqueKey();
        $amountColumns = $this->amountColumns($driver);

        $modelClass::query()
            ->where($payoutTimeColumn, '>=', $startTime)
            ->where($payoutTimeColumn, '<', $endTime)
            // lazyById 以主鍵游標分頁，主鍵必須在 select 內
            ->select([$model->getKeyName(), $uniqueKey, ...$amountColumns])
            ->lazyById(self::INSERT_CHUNK_SIZE)
            ->each(function (AbstractBetRecord $record) use ($uniqueKey, $amountColumns): void {
                $key = (string) $record->{$uniqueKey};

                if (isset($this->windowFetchedKeys[$key])) {
                    return;
                }

                $this->previewMissingTotal++;

                if (count($this->previewMissing) >= self::PREVIEW_LIST_LIMIT) {
                    return;
                }

                $this->previewMissing[] = [
                    'key' => $key,
                    'amounts' => array_reduce(
                        $amountColumns,
                        fn (array $carry, string $column): array => [...$carry, $column => (string) $record->{$column}],
                        [],
                    ),
                ];
            });
    }

    /**
     * 拉取單一遊戲商的單一時間片（≤ betRecordApiWindowMinutes；排程 Job 與 --direct 共用）
     * 時間片是指每次拉取的時間區間，以 ACE333 為例則是 60 分鐘
     *
     * 窗口超過一片時以 pendingFrom 回報下一次執行起點時間，由呼叫端決定推進方式；
     * rate limit 預算用罄時中止並回報 retryAfterSeconds，不在此等待。
     *
     * $useCache = true 時三方原始回應以（遊戲商 × 憑證 × 時間片）為鍵快取 RAW_CACHE_TTL，
     * 命中即不打三方、不佔 rate limit 預算；拉取失敗直接上拋、不進快取。
     * 內容壓縮後才入快取（見 compressRaw）——這份快取與全站共用同一台 Redis。
     *
     * @throws Throwable
     */
    public function fetchChunk(
        int $vendorId,
        SupportsBetRecordFetch $driver,
        string $startTime,
        string $endTime,
        bool $updateExisting = false,
        ?int $vendorInfoId = null,
        bool $useCache = false,
    ): BetRecordFetchChunkResultData {
        $vendor = Vendor::query()
            ->with(['vendorInfos' => fn ($q) => $q->where('status', 1)
                ->when($vendorInfoId !== null, fn ($q) => $q->where('id', $vendorInfoId))])
            ->where('id', $vendorId)
            ->where('status', 1)
            ->first();

        if (!$vendor || $vendor->vendorInfos->isEmpty()) {
            Log::warning('BetRecordFetchService: vendor 不存在、停用或無符合的 vendor_info', [
                'vendor_id' => $vendorId,
                'vendor_info_id' => $vendorInfoId,
            ]);

            return new BetRecordFetchChunkResultData;
        }

        // 單一錢包的注單由回調即時記帳，拉單只能拿來比對：寫入會生出沒有錢包異動的孤兒注單，
        // 連帶把 TBR／彙總一起灌歪。乾跑不寫入，照常放行
        if (!$this->collectPreview && $vendor->is_single_wallet === 1) {
            throw new LogicException("單一錢包遊戲商（vendor_id: {$vendorId}）拉注單只支援乾跑模式（--dry-run）");
        }

        $windowMinutes = max(1, $driver->betRecordApiWindowMinutes());
        $rateLimit = $driver->betRecordApiRateLimit();
        $limiterKey = 'fetch_bet_record:' . $vendorId;

        $from = Carbon::parse($startTime, 'UTC');
        $to = Carbon::parse($endTime, 'UTC');

        // 可拉取界線 guard：整段早於界線不執行任何動作，跨界線則收斂起點
        $minStartTime = $driver->betRecordMinStartTime();
        if ($minStartTime !== null) {
            $minStart = Carbon::parse($minStartTime, 'UTC');

            if ($to <= $minStart) {
                Log::warning('BetRecordFetchService: 窗口整段早於可拉取界線，不執行', [
                    'vendor_id' => $vendorId,
                    'start_time' => $startTime,
                    'end_time' => $endTime,
                    'min_start_time' => $minStartTime,
                ]);

                return new BetRecordFetchChunkResultData;
            }

            if ($from < $minStart) {
                Log::info('BetRecordFetchService: 窗口起點早於可拉取界線，已收斂', [
                    'vendor_id' => $vendorId,
                    'start_time' => $startTime,
                    'min_start_time' => $minStartTime,
                ]);
                $from = $minStart->copy();
            }
        }

        $fetched = 0;
        $inserted = 0;
        $updated = 0;

        // 本次只處理 [from, chunkTo) 單一時間片
        $chunkTo = $from->copy()->addMinutes($windowMinutes);
        if ($chunkTo > $to) {
            $chunkTo = $to->copy();
        }

        $rateLimited = false;
        $rateLimitedRetryAfter = 0;

        // 同一時間片對每組憑證各打一次三方 API（共用憑證的 vendor_info 只拉一次），
        // 全部計入同一預算；預算於片內用罄時整片留給接續任務重拉（寫入以唯一鍵去重，重疊安全）。
        // 拉取失敗直接上拋由呼叫端處理；注單寫入與 TBR 轉檔以 chunk 為單位包同一事務，
        // 不會留下「已入 BR、未轉 TBR、重拉又被去重跳過」的孤兒列
        $usedCredentialKeys = [];
        foreach ($vendor->vendorInfos as $vendorInfo) {
            $credentialKey = $driver->betRecordCredentialKey($vendorInfo->info);
            $credentialKey = $credentialKey !== '' ? $credentialKey : (string) $vendorInfo->id;
            if (in_array($credentialKey, $usedCredentialKeys, true)) {
                continue;
            }
            $usedCredentialKeys[] = $credentialKey;

            // 商戶狀態由呼叫端統一載入（與錢包操作同慣例）：之後的 fetchBetRecords /
            // formatBetRecord 都在同一狀態下跑，快取命中與否路徑一致
            $driver->setInfo($vendorInfo->info);

            // 快取命中不打三方、不佔 rate limit 預算
            $cacheKey = self::rawCacheKey($vendorId, $credentialKey, $from, $chunkTo);
            $rawRecords = $useCache ? self::readRawCache($cacheKey) : null;

            if (!is_array($rawRecords)) {
                if (RateLimiter::tooManyAttempts($limiterKey, $rateLimit['max_calls'])) {
                    $rateLimited = true;
                    $rateLimitedRetryAfter = RateLimiter::availableIn($limiterKey);

                    break;
                }
                RateLimiter::hit($limiterKey, $rateLimit['per_seconds']);

                $rawRecords = $driver->fetchBetRecords($from->toDateTimeString(), $chunkTo->toDateTimeString());

                // 拉取失敗已在上一行直接上拋，不會走到這裡——錯誤永遠不進快取
                if ($useCache) {
                    Cache::put($cacheKey, self::compressRaw($rawRecords), self::RAW_CACHE_TTL);
                }
            }

            if (empty($rawRecords)) {
                continue;
            }

            $fetched += count($rawRecords);
            // 跨 vendor_info 不會重複：前一輪已入庫的鍵於 persistBetRecords 對照 DB 時排除
            [$chunkInserted, $chunkUpdated] = $this->persistBetRecords($driver, $vendor, $vendorInfo, $rawRecords, $updateExisting);
            $inserted += $chunkInserted;
            $updated += $chunkUpdated;
        }

        if ($rateLimited) {
            return new BetRecordFetchChunkResultData(
                fetched: $fetched,
                inserted: $inserted,
                updated: $updated,
                pendingFrom: $from->toDateTimeString(),
                retryAfterSeconds: $rateLimitedRetryAfter,
            );
        }

        return new BetRecordFetchChunkResultData(
            fetched: $fetched,
            inserted: $inserted,
            updated: $updated,
            // 片已完成：窗口還有剩餘時回報接續起點（retryAfterSeconds 維持 0 = 可立即派發）
            pendingFrom: $chunkTo < $to ? $chunkTo->toDateTimeString() : null,
        );
    }

    /**
     * 三方原始回應壓縮後入快取。
     *
     * 🔴 這份快取與 session、業務快取共用同一台 Redis（各站 Memorystore BASIC 1GB）。
     * 未壓縮時 PP 拉整天約 440MB（RNG 一片 14000 筆注單、serialize 後 6MB），兩次執行就把
     * TPower 的 Redis 從平時 20% 推到 99.5%，觸發 OOM、全站寫入被拒約一分鐘（2026-09-23）。
     *
     * gzip 對這種「欄位名每筆重複、值高度同質」的資料壓縮比達 26 倍，整天降到約 17MB；
     * 單片壓縮 26ms，相對三方往返 530ms 可忽略。
     *
     * 不改存放格式：實測壓縮後 serialize 0.24MB、json 0.19MB、原始 CSV 0.17MB，
     * 差距從未壓縮的 3.6 倍縮到 1.4 倍（gzip 已把重複欄位名消化掉），
     * 為省整天 5MB 去改 SupportsBetRecordFetch 讓 driver 回傳原始回應並不划算。
     *
     * @param  array<int, array<string, mixed>>  $rawRecords
     */
    private static function compressRaw(array $rawRecords): string
    {
        return gzcompress(serialize($rawRecords), 6);
    }

    /**
     * 讀回快取：壓縮格式解壓，內容壞掉或解不開時當作沒有快取（退回打三方，不讓整趟中止）。
     *
     * @return array<int, array<string, mixed>>|null
     */
    private static function readRawCache(string $cacheKey): ?array
    {
        $cached = Cache::get($cacheKey);

        if (!is_string($cached)) {
            return null;
        }

        try {
            $raw = @gzuncompress($cached);
            $records = $raw === false ? null : unserialize($raw, ['allowed_classes' => false]);
        } catch (Throwable) {
            return null;
        }

        return is_array($records) ? $records : null;
    }

    /**
     * 三方原始回應的快取鍵：遊戲商 × 憑證 × 時間片。
     * 憑證鍵可能含三方帳號，hash 後入鍵，避免明文落到 redis key 與 log。
     */
    public static function rawCacheKey(int $vendorId, string $credentialKey, Carbon $from, Carbon $to): string
    {
        return sprintf(
            'fetch_bet_record:raw:%d:%s:%s-%s',
            $vendorId,
            sha1($credentialKey),
            $from->format('YmdHi'),
            $to->format('YmdHi'),
        );
    }

    /**
     * 寫入注單並轉入 TBR
     *
     * 預設以唯一鍵去重後純新增；$updateExisting = true 時，既有列金額有異動的
     * 一併更新並重轉 TBR（取消⇄正常互轉靠金額變化生效）。
     *
     * @param  array<int, array<string, mixed>>  $rawRecords
     * @return array{0: int, 1: int} [新增筆數, 更新筆數]
     */
    private function persistBetRecords(
        SupportsBetRecordFetch $driver,
        Vendor $vendor,
        VendorInfo $vendorInfo,
        array $rawRecords,
        bool $updateExisting,
    ): array {
        $uniqueKey = $driver->betRecordUniqueKey();
        $rate = (string) $vendorInfo->rate;

        // 批次內去重（三方回應可能重複），再對照 DB 已存在的鍵
        $rows = [];
        foreach ($rawRecords as $raw) {
            $row = $driver->formatBetRecord($raw, $rate);
            $rows[(string) $row[$uniqueKey]] = $row;
        }

        if ($this->collectPreview) {
            // 反向對帳要的是「整個窗口」的三方鍵集合：逐片累積，避免同一注單跨片被誤判成三方沒回
            $this->windowFetchedKeys += array_fill_keys(array_keys($rows), true);
        }

        $modelClass = $driver->getBetRecordModelClass();

        // 更新模式要比金額，取完整列；純新增只需要鍵
        $existing = $updateExisting
            ? $modelClass::query()->whereIn($uniqueKey, array_keys($rows))->get()
                ->keyBy(fn (AbstractBetRecord $record): string => (string) $record->{$uniqueKey})
            : null;
        $existingKeys = $existing?->keys()->all()
            ?? array_map('strval', $modelClass::query()->whereIn($uniqueKey, array_keys($rows))->pluck($uniqueKey)->all());

        $inserted = $this->insertNewRecords($driver, $vendor, collect($rows)->except($existingKeys));

        if ($existing === null) {
            return [$inserted, 0];
        }

        return [$inserted, $this->updateChangedRecords($driver, $vendor, collect($rows)->only($existingKeys), $existing)];
    }

    /**
     * 純新增去重後的列；每個 chunk 的注單寫入與 TBR 轉檔包同一事務，
     * 例外時一起回滾——半途炸掉不會留下重拉時被去重跳過、卻永遠沒轉 TBR 的孤兒列
     *
     * @param  Collection<string, array<string, mixed>>  $toInsert  唯一鍵值 => 注單欄位
     * @return int 新增筆數
     */
    private function insertNewRecords(SupportsBetRecordFetch $driver, Vendor $vendor, Collection $toInsert): int
    {
        $modelClass = $driver->getBetRecordModelClass();

        // 乾跑：只收集清單，不寫入
        if ($this->collectPreview) {
            $amountColumns = array_flip($this->amountColumns($driver));

            foreach ($toInsert->take(max(0, self::PREVIEW_LIST_LIMIT - count($this->previewNew))) as $uniqueKeyValue => $attributes) {
                $this->previewNew[] = [
                    'key' => (string) $uniqueKeyValue,
                    'amounts' => array_map('strval', array_intersect_key($attributes, $amountColumns)),
                ];
            }

            return $toInsert->count();
        }

        foreach ($toInsert->chunk(self::INSERT_CHUNK_SIZE) as $chunk) {
            $records = $chunk
                ->map(fn (array $attributes, string $uniqueKeyValue) => new InsertedBetRecordData($uniqueKeyValue, $attributes))
                ->values()
                ->all();

            DB::transaction(function () use ($modelClass, $chunk, $driver, $vendor, $records): void {
                $modelClass::insert($chunk->values()->toArray());
                $this->transferToTotalBetRecords($driver, $vendor, $records);
            });
        }

        return $toInsert->count();
    }

    /**
     * 更新金額有異動的既有列並重轉 TBR（$updateExisting 模式）
     *
     * 比對欄位由 BetRecord Model 宣告的金額欄位組成；一個可比對欄位都沒有時
     * 一律視為有異動——寧可多更新，也不要漏掉該更新的注單。
     * TBR 以 (vendor_id, bet_record_id) upsert，既有列的金額會被覆寫。
     *
     * @param  Collection<string, array<string, mixed>>  $rows  既有列的新資料（唯一鍵值 => 注單欄位）
     * @param  Collection<string, AbstractBetRecord>  $existing  唯一鍵值 => 既有列
     * @return int 更新筆數
     */
    private function updateChangedRecords(
        SupportsBetRecordFetch $driver,
        Vendor $vendor,
        Collection $rows,
        Collection $existing,
    ): int {
        $modelClass = $driver->getBetRecordModelClass();
        $uniqueKey = $driver->betRecordUniqueKey();
        $amountColumns = $this->amountColumns($driver);

        $changed = $rows->filter(
            fn (array $attributes, string $key): bool => !$this->hasSameAmounts($existing[$key], $attributes, $amountColumns),
        );

        // 乾跑：只收集差異清單，不更新
        if ($this->collectPreview) {
            foreach ($changed->take(max(0, self::PREVIEW_LIST_LIMIT - count($this->previewChanged))) as $key => $attributes) {
                $this->previewChanged[] = [
                    'key' => (string) $key,
                    'changes' => $this->amountDiffs($existing[$key], $attributes, $amountColumns),
                ];
            }

            return $changed->count();
        }

        foreach ($changed->chunk(self::INSERT_CHUNK_SIZE) as $chunk) {
            $records = $chunk
                ->map(fn (array $attributes, string $uniqueKeyValue) => new InsertedBetRecordData($uniqueKeyValue, $attributes))
                ->values()
                ->all();

            DB::transaction(function () use ($modelClass, $uniqueKey, $chunk, $driver, $vendor, $records): void {
                foreach ($chunk as $uniqueKeyValue => $attributes) {
                    $modelClass::query()->where($uniqueKey, $uniqueKeyValue)->update($attributes);
                }

                $this->transferToTotalBetRecords($driver, $vendor, $records);
            });
        }

        return $changed->count();
    }

    /**
     * BetRecord Model 宣告的金額欄位（比對異動與乾跑清單共用）
     *
     * @return list<string>
     */
    private function amountColumns(SupportsBetRecordFetch $driver): array
    {
        $modelClass = $driver->getBetRecordModelClass();

        /** @var AbstractBetRecord $model */
        $model = new $modelClass;

        return array_values(array_unique(array_filter([
            $model->getBetColumn(),
            $model->getValidBetColumn(),
            $model->getWinLoseColumn(),
            $model->getWinColumn(),
            $model->getJackpotColumn(),
            $model->getBonusColumn(),
        ])));
    }

    /**
     * 既有列與新資料在各金額欄位的差異（乾跑清單用）
     *
     * @param  array<string, mixed>  $attributes
     * @param  list<string>  $amountColumns
     * @return array<string, array{from: string, to: string}>
     */
    private function amountDiffs(AbstractBetRecord $record, array $attributes, array $amountColumns): array
    {
        $diffs = [];

        foreach ($amountColumns as $column) {
            if (!array_key_exists($column, $attributes)) {
                continue;
            }

            $from = (string) $record->{$column};
            $to = (string) $attributes[$column];

            if (Common::numCompare($from, $to, 4) !== 0) {
                $diffs[$column] = ['from' => $from, 'to' => $to];
            }
        }

        return $diffs;
    }

    /**
     * 金額是否與現有紀錄相同（相同就不必更新）
     *
     * @param  array<string, mixed>  $attributes
     * @param  array<int, string>  $amountColumns
     */
    private function hasSameAmounts(AbstractBetRecord $record, array $attributes, array $amountColumns): bool
    {
        $compared = false;

        foreach ($amountColumns as $column) {
            if (!array_key_exists($column, $attributes)) {
                continue;
            }

            $compared = true;

            if (Common::numCompare((string) $record->{$column}, (string) $attributes[$column], 4) !== 0) {
                return false;
            }
        }

        return $compared;
    }

    /**
     * 本次新增的注單列批次轉入 total_bet_records
     *
     * 以拉取時的記憶體列轉檔，僅回查唯一鍵 => bet_record_id 對應；
     * member_id 由帳號表解析（無對應會員不轉入）、game_id 查無記 0、
     * valid_bet_rate 依 vendor_info_games → games → vendors 逐層 fallback，
     * 以 (vendor_id, bet_record_id) 唯一鍵 upsert，重跑安全。
     *
     * @param  list<InsertedBetRecordData>  $insertedRecords
     *
     * @throws Throwable
     */
    private function transferToTotalBetRecords(
        SupportsBetRecordFetch $driver,
        Vendor $vendor,
        array $insertedRecords
    ): void {
        if (empty($insertedRecords)) {
            return;
        }

        $modelClass = $driver->getBetRecordModelClass();
        $accountClass = $driver->getAccountModelClass();
        $accountColumn = $driver->betRecordAccountColumn();
        $uniqueKey = $driver->betRecordUniqueKey();

        $transferred = 0;
        $skippedNotQualified = 0;
        $skippedNoAccountKeys = [];

        foreach (array_chunk($insertedRecords, self::INSERT_CHUNK_SIZE) as $chunkRecords) {
            /** @var array<array-key, int> $idMap 唯一鍵值 => bet_record_id */
            $idMap = $modelClass::query()
                ->whereIn($uniqueKey, array_map(
                    fn (InsertedBetRecordData $record): string => $record->uniqueKeyValue,
                    $chunkRecords,
                ))
                ->pluck('id', $uniqueKey)
                ->all();

            /** @var Collection<int, BaseTotalBetRecordData> $bases bet_record_id => TBR 基礎欄位 */
            $bases = collect($chunkRecords)
                ->mapWithKeys(function (InsertedBetRecordData $record) use ($driver, $idMap, &$skippedNotQualified): array {
                    $betRecordId = $idMap[$record->uniqueKeyValue] ?? null;
                    $base = $betRecordId !== null ? $driver->formatBaseTotalBetRecord($record) : null;

                    if ($base === null) {
                        $skippedNotQualified++;

                        return [];
                    }

                    return [(int) $betRecordId => $base];
                });

            if ($bases->isEmpty()) {
                continue;
            }

            $accountMap = $accountClass::query()
                ->whereIn($accountColumn, $bases->pluck('accountKey')->unique())
                ->where('member_id', '>', 0)
                ->get()
                ->keyBy($accountColumn);

            $gameMap = Game::query()
                ->where('vendor_id', $vendor->id)
                ->whereIn('game_type', $bases->pluck('gameCode')->unique())
                ->get()
                ->keyBy('game_type');

            $vigRateMap = VendorInfoGame::query()
                ->whereIn('vendor_info_id', $accountMap->pluck('vendor_info_id')->unique())
                ->whereIn('game_id', $gameMap->pluck('id')->unique())
                ->get()
                ->keyBy(fn (VendorInfoGame $vig) => $vig->vendor_info_id . ':' . $vig->game_id);

            $rows = [];
            foreach ($bases as $betRecordId => $base) {
                $account = $accountMap[$base->accountKey] ?? null;
                if ($account === null) {
                    // 無對應會員帳號的注單不轉入 TBR：這是 BR/TBR 對帳差異的主要來源，須留樣本供追查
                    $skippedNoAccountKeys[] = $base->referenceNo;

                    continue;
                }

                $game = $gameMap[$base->gameCode] ?? null;
                $vig = $game !== null
                    ? ($vigRateMap[$account->vendor_info_id . ':' . $game->id] ?? null)
                    : null;

                $rows[] = [
                    'summary_time' => Carbon::parse($base->payoutTime, 'UTC')->startOfHour()->toDateTimeString(),
                    'member_id' => (int) $account->member_id,
                    'vendor_id' => $vendor->id,
                    'bet_record_id' => $betRecordId,
                    'game_id' => (int) ($game->id ?? 0),
                    'reference_no' => $base->referenceNo,
                    'bet' => $base->bet,
                    'valid_bet' => $base->validBet,
                    'win_lose' => $base->winLose,
                    'valid_bet_rate' => Common::numAdd(
                        '0',
                        (string) ($vig?->valid_bet_rate ?? $game?->valid_bet_rate ?? $vendor->valid_bet_rate),
                        4
                    ),
                    'bet_time' => $base->betTime,
                    'payout_time' => $base->payoutTime,
                ];
            }

            if (empty($rows)) {
                continue;
            }

            TotalBetRecord::upsert(
                $rows,
                ['vendor_id', 'bet_record_id'],
                ['summary_time', 'member_id', 'game_id', 'reference_no', 'bet', 'valid_bet', 'win_lose', 'valid_bet_rate', 'bet_time', 'payout_time'],
            );
            $transferred += count($rows);
        }

        Log::info('BetRecordFetchService: TBR 轉檔完成', [
            'vendor_id' => $vendor->id,
            'inserted' => count($insertedRecords),
            'transferred' => $transferred,
            'skipped_not_qualified' => $skippedNotQualified,
            'skipped_no_account' => count($skippedNoAccountKeys),
            'skipped_no_account_samples' => array_slice($skippedNoAccountKeys, 0, 10),
        ]);
    }
}
