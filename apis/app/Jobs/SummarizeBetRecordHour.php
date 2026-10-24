<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Console\Commands\SummaryBetRecord\SummaryBetRecordRunner;
use App\Console\Commands\SummaryBetRecord\SummaryBetRecordService;
use App\Enums\General\ContextKeyEnum;
use App\Enums\QueueJobKeyEnum;
use App\Enums\QueueJobRunSourceEnum;
use App\Helpers\Common;
use App\Models\QueueJobRun;
use App\Services\BotNotifyService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * 整點 SBR 彙總 job：處理指定 hour bucket 並回看補洞（target/tickets#4257）
 *
 * 2026-08-28 節點汰換使 `schedule:run` 遲到 4 分鐘、整點的 summary_bet_record
 * 永久跳過且全程靜默（K8s job 成功、Laravel 判斷正確、應用無例外）。
 * 防再發設計：整點排程只 dispatch 本 job（帶明確 bucket），job 每次執行
 * 回看近 {@see self::LOOKBACK_HOURS} 小時，凡 queue_job_runs 缺
 * 完成標記的 (vendor, bucket) 一律補跑——任何一輪觸發缺失（節點汰換、
 * dispatch 失敗、Redis 抖動）都由下一個整點自動治癒。手動補跑（Olympus／CLI）
 * 的標記不算數，見 {@see SummaryBetRecordRunner::BACKFILL_IGNORED_SOURCES}。
 *
 * 併發防護（同 vendor × hour 禁止並行計算調帳差額）三層：
 * 1. WithoutOverlapping：同一時間僅一個本 job 在跑（連續整點的回看視窗重疊）
 * 2. Runner 內 vendor × hour cache lock（與 CLI 手動補跑互斥，TTL > timeout）
 * 3. 取鎖後二次確認完成標記（skipIfCompleted），已完成即跳過
 *
 * 失敗可見性：單一廠商失敗不中斷其他廠商，收尾統一拋出觸發 retry
 * （已完成的 (vendor, bucket) 有標記，重試只補殘餘）；maxExceptions 耗盡
 * 落 failed_jobs 並經 failed() 發 BotNotify。
 *
 * 結算保底：彙總結算（team／滾碼）沒有完成標記，兩道防線——
 * 1. trigger bucket 一律重跑結算（writer 刪除重建、冪等）
 * 2. 任一結算失敗即重置該結算涵蓋 bucket 的 vendor 完成標記（手動補跑的列保留）並 BotNotify，
 *    retry 重新判缺、整格重算（vendor 彙總為冪等覆蓋，重算安全）——否則
 *    回看 bucket 的結算失敗會被 retry 靜默洗成成功、缺格永久漏。
 * 殘餘風險：worker 於結算中被硬砍（無 catch 可走）時標記不會重置，該格結算
 * 仍會漏——根治需結算完成標記體系，已登記雙軌收斂待辦。
 *
 * @knowledge rules/summary-bet-record
 */
class SummarizeBetRecordHour implements ShouldQueue
{
    use Queueable;

    /** 回看視窗（小時）：含本輪觸發 bucket；任一輪觸發缺失由後續整點自動補齊 */
    public const int LOOKBACK_HOURS = 6;

    /** attempts 不設上限：WithoutOverlapping 取不到鎖會 release 重入，不可計為失敗 */
    public int $tries = 0;

    /** 真實例外重試上限：耗盡落 failed_jobs 並 BotNotify */
    public int $maxExceptions = 3;

    /**
     * 例外重試退避（秒）。實際取用的索引受 WithoutOverlapping 影響——release 重入
     * 也使 attempts() +1，故多數重試落在末段 660，恰跨過 Runner 鎖 TTL（660），
     * 殘鎖到期後重試才有機會取鎖。
     * ⚠️ timeout 硬砍不走 backoff：被砍的 job 留在 reserved set，須等連線
     * retry_after（3660 秒，config/queue.php）到期才被撿回；該路徑的缺漏
     * 由下一個整點 dispatch 的回看補洞兜底。
     *
     * @var array<int, int>
     */
    public array $backoff = [120, 660];

    public int $timeout = 600;

    /** 本輪觸發的 hour bucket 起點（UTC，Y-m-d H:00:00） */
    public string $bucketStartUtc;

    public function __construct(string $bucketStartUtc)
    {
        // 一律正規化到整點，確保與完成標記、鎖 key 的粒度一致
        $this->bucketStartUtc = date('Y-m-d H:00:00', strtotime($bucketStartUtc));

        // 走長時 job 專用連線（retry_after=3660 > timeout）：整批補洞可能跑超過
        // 預設連線的 retry_after=90 秒被重複派發
        $this->onConnection('redis-long-running');
    }

    /**
     * 全域單飛：連續整點 job 的回看視窗互相重疊，序列化避免互搶 vendor 鎖；
     * 取不到鎖 release 60 秒後重入，鎖存活略大於 timeout，worker 被砍時鎖可自行過期。
     *
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('summary-bet-record-hour'))
                ->releaseAfter(60)
                ->expireAfter(660),
        ];
    }

    public function handle(SummaryBetRecordRunner $runner): void
    {
        Context::add(ContextKeyEnum::FLOW->value, 'summary_bet_record');

        /** @var array<int, array{third_party_code: string, time_field: string, schedule_enabled?: bool}> $configs */
        $configs = SummaryBetRecordRunner::scheduledConfigs(config('summary_bet_record'));

        $buckets = $this->lookbackBuckets();
        $pending = QueueJobRun::missingPairs(
            QueueJobKeyEnum::SUMMARY_BET_RECORD,
            $buckets,
            array_keys($configs),
            SummaryBetRecordRunner::BACKFILL_IGNORED_SOURCES,
        );

        if ($pending === []) {
            Log::info('SummarizeBetRecordHour: 回看視窗內無缺漏 bucket', [
                'trigger_bucket' => $this->bucketStartUtc,
                'lookback_hours' => self::LOOKBACK_HOURS,
            ]);
        } else {
            $runner->prepareSharedData();
        }

        $failures = [];
        $cutoffBuckets = [];

        foreach ($buckets as $bucket) {
            $vendorIds = $pending[$bucket] ?? [];
            $processed = $vendorIds === [] ? 0 : $this->processBucket($runner, $bucket, $vendorIds, $configs, $failures);

            // 結算（team／滾碼）沒有完成標記，trigger bucket 一律重跑結算補回舊排程
            // 「每小時無條件結算」的語意（見類別 docblock「結算保底」）；
            // 回看 bucket 僅在實際補算到廠商時重跑結算
            if ($processed === 0 && $bucket !== $this->bucketStartUtc) {
                continue;
            }

            // 已關帳 bucket 的調帳列全落在 summary_time = 關帳時間同一格，
            // 彙總結算集中到最後以關帳時間跑一次（對齊 SummaryBetRecordCommand 行為）
            if (SummaryBetRecordService::hasPastCutoff($bucket)) {
                $cutoffBuckets[] = $bucket;

                continue;
            }

            if (!$this->runSettlementSafely($runner, $bucket, $failures)) {
                $this->resetCompletionMarks([$bucket], array_keys($configs));
            }
        }

        if ($cutoffBuckets !== []
            && !$this->runSettlementSafely($runner, SummaryBetRecordService::getDailyCutoffTime(), $failures)) {
            $this->resetCompletionMarks($cutoffBuckets, array_keys($configs));
        }

        if ($failures !== []) {
            throw new RuntimeException('SummarizeBetRecordHour 部分執行失敗: ' . implode('; ', $failures));
        }
    }

    /**
     * maxExceptions 耗盡（或 timeout 等框架層失敗）：落 failed_jobs 後告警。
     * 缺漏的 (vendor, bucket) 仍無完成標記，由下一個整點 job 的回看視窗接手。
     */
    public function failed(?Throwable $exception): void
    {
        Log::error('SummarizeBetRecordHour 最終失敗', [
            'trigger_bucket' => $this->bucketStartUtc,
            'error' => $exception?->getMessage(),
        ]);

        app(BotNotifyService::class)->send(
            "[SummarizeBetRecordHour error] bucket {$this->bucketStartUtc} 重試耗盡最終失敗，缺漏由下一整點回看補洞接手: "
            . ($exception?->getMessage() ?? 'unknown')
        );
    }

    /**
     * 處理單一 bucket 的所有缺漏廠商；單一廠商失敗記入 $failures 不中斷其他廠商。
     *
     * @param  array<int, int>  $vendorIds
     * @param  array<int, array{third_party_code: string, time_field: string, schedule_enabled?: bool}>  $configs
     * @param  array<int, string>  $failures
     * @return int 實際重算的廠商數（skipped 不計，供呼叫端決定是否重跑 SP）
     */
    private function processBucket(SummaryBetRecordRunner $runner, string $bucket, array $vendorIds, array $configs, array &$failures): int
    {
        $bucketEnd = date('Y-m-d H:i:s', strtotime($bucket . ' +1 hour'));
        $source = $bucket === $this->bucketStartUtc
            ? QueueJobRunSourceEnum::SCHEDULE
            : QueueJobRunSourceEnum::BACKFILL;
        $processed = 0;

        foreach ($vendorIds as $vendorId) {
            try {
                $stats = $runner->runVendorBucket(
                    vendorId: $vendorId,
                    config: $configs[$vendorId],
                    start: $bucket,
                    end: $bucketEnd,
                    dryRun: false,
                    source: $source,
                    skipIfCompleted: true,
                );

                if ($stats['mode'] !== 'skipped') {
                    $processed++;
                }
            } catch (Throwable $e) {
                $failures[] = sprintf('vendor %d bucket %s: %s', $vendorId, $bucket, $e->getMessage());
                Log::error('SummarizeBetRecordHour 廠商執行失敗', [
                    'vendor_id' => $vendorId,
                    'bucket' => $bucket,
                    'source' => $source->value,
                    'error' => $e->getMessage(),
                    'trace' => Common::formatTrace($e),
                ]);
            }
        }

        return $processed;
    }

    /**
     * @param  array<int, string>  $failures
     * @return bool 結算是否成功（false 時呼叫端須重置相關完成標記讓 retry 重算）
     */
    private function runSettlementSafely(SummaryBetRecordRunner $runner, string $summaryTime, array &$failures): bool
    {
        try {
            $runner->runSummarySettlement($summaryTime);

            return true;
        } catch (Throwable $e) {
            $failures[] = sprintf('settlement %s: %s', $summaryTime, $e->getMessage());
            Log::error('SummarizeBetRecordHour 彙總結算失敗', [
                'summary_time' => $summaryTime,
                'error' => $e->getMessage(),
                'trace' => Common::formatTrace($e),
            ]);
            // 即時告警（不等 maxExceptions 耗盡）：retry 若洗成成功便不會再有 failed()
            // 通知，這裡是結算失敗唯一保證送達的告警
            app(BotNotifyService::class)->send(
                "[SummarizeBetRecordHour error] 彙總結算失敗 {$summaryTime}: {$e->getMessage()}"
                . '（將重置該輪相關完成標記，retry 整格重算）'
            );

            return false;
        }
    }

    /**
     * 結算失敗時重置相關 bucket 的完成標記（僅限排程啟用廠商）。
     *
     * 結算本身沒有完成標記，不重置的話 retry 會因 vendor 標記已齊而跳過結算、
     * 缺格永久靜默；vendor 彙總是冪等覆蓋，整格重算安全。
     *
     * @param  array<int, string>  $buckets
     * @param  array<int, int>  $vendorIds
     */
    private function resetCompletionMarks(array $buckets, array $vendorIds): void
    {
        $deleted = QueueJobRun::resetMarks(
            QueueJobKeyEnum::SUMMARY_BET_RECORD,
            $buckets,
            $vendorIds,
            SummaryBetRecordRunner::BACKFILL_IGNORED_SOURCES,
        );

        Log::warning('SummarizeBetRecordHour: 結算失敗，重置完成標記供 retry 重算', [
            'buckets' => $buckets,
            'deleted' => $deleted,
        ]);
    }

    /**
     * @return array<int, string> 回看視窗 bucket 清單（舊 → 新，含本輪觸發 bucket）
     */
    private function lookbackBuckets(): array
    {
        $buckets = [];

        for ($i = self::LOOKBACK_HOURS - 1; $i >= 0; $i--) {
            $buckets[] = date('Y-m-d H:00:00', strtotime("{$this->bucketStartUtc} -{$i} hours"));
        }

        return $buckets;
    }
}
