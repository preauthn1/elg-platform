<?php

declare(strict_types=1);

namespace App\Console\Commands\GoogleCloud\FUMA;

use App\Console\Commands\GoogleCloud\GcpCommand;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;

/**
 * 從 GCP Cloud Logging 找出失敗的 FUMA 投注 callback，並重新回送。
 *
 * ## 操作流程
 *
 * ```
 * GCP 響應紀錄（code=9999）
 *   └─ Step 1 ──→ {date}_{H}h_1_failed_ids.json          ← 每小時一份，確認失敗清單
 *                       │ request_id[]
 * GCP 請求紀錄（by request_id）
 *   └─ Step 2 ──→ {date}_{H}h_2_callback_payloads.json   ← 每小時一份，確認請求參數
 *                       │ [{request_id, url, params}]
 *         Step 3 ──→ HTTP POST（並行重送）
 *                       │
 *         Step 4 ──→ {date}_{H}h_4_replay_results.json   ← 每小時一份，結果明細
 * ```
 *
 * - **Step 1**：查詢 GCP `外部 API 響應紀錄` 中 `response.data.code=9999` 的失敗紀錄，
 *   萃取唯一 `request_id` 清單，寫入快取 JSON 供人工確認。
 * - **Step 2**：以 `request_id` 批次查詢 GCP `外部 API 請求紀錄`，
 *   取回每筆 callback 的 `url` 與 `params`，寫入快取 JSON。
 * - **Step 3**：依日誌時間**循序**對每筆 payload 重新發送 JSON POST，確保 bet → settle 順序正確。
 * - **Step 4**：將成功／略過／失敗的統計與明細寫入結果 JSON。
 *
 * > Step 1 / Step 2 若對應時間區段的快取檔已存在，自動跳過 GCP 查詢。
 * > 若需強制重查，刪除對應快取檔後再執行。
 *
 * ## 使用範例
 *
 * ```bash
 * # 今日（自動結算至當前小時，例如 15:xx 則查 00h-15h）
 * php artisan logs:resend-fuma-callbacks
 *
 * # 指定過去某天（UTC+8，自動查全天 00h-23h）
 * php artisan logs:resend-fuma-callbacks --date=2026-03-26
 *
 * # 調高 gcloud timeout
 * php artisan logs:resend-fuma-callbacks \
 *     --date=2026-03-26 \
 *     --gcloud-timeout=600
 * ```
 *
 * ## 注意事項
 *
 * - 執行前須完成 `gcloud auth login` 並確認有 Cloud Logging 讀取權限。
 * - `--date` 輸入 **UTC+8** 日期，格式 `2026-03-27`；未指定時預設為今日。
 * - 今日執行時，查詢範圍自動結算至**當前小時**（例如 15:30 → 查 00:00–15:59）。
 * - 過去日期自動查詢該天全部 24 小時。
 * - 快取檔名格式：`{date}_{H}h_{step}_{type}.json`，例：`2026-03-27_09h_1_failed_ids.json`。
 * - 每小時獨立走完 Step 1–4，無失敗紀錄的小時自動略過。
 * - Step 1 / Step 2 若對應小時的快取檔已存在，自動跳過 GCP 查詢。
 * - `--gcloud-timeout` 為每小時查詢的 timeout 秒數，預設 300。
 * - 重送回應 `code=9999` 且含 `Record duplicated` 者計入 skipped，表示已補單成功，不視為失敗。
 * - 結果 JSON 包含 `summary`（統計）、`successful`（成功明細）與 `results`（全部明細）。
 */
class ResendBetCallbacks extends GcpCommand
{
    protected $signature = 'logs:resend-fuma-callbacks
        {--dir=app/Console/Commands/GoogleCloud/FUMA : 快取與結果 JSON 存放目錄}
        {--project=tp-wz3-tpower : GCP project id}
        {--date= : 查詢日期，UTC+8，格式 2026-03-27（預設今日；今日結算至當前小時，過去日期查全天）}
        {--response-limit=0 : Step 1 每小時響應紀錄查詢的最大筆數（0 = 不限制）}
        {--gcloud-timeout=300 : 每小時 gcloud 查詢 timeout 秒數}
        {--batch=50 : Step 2 請求紀錄每批查詢的 request_id 數}
        {--post-timeout=30 : 單筆 callback 重送 timeout 秒數}';

    protected $description = 'Resend failed FUMA bet record callbacks (response code 9999) from GCP logs';

    private const string RESPONSE_LOG_PATTERN = '\[.+\] external/api/sw/fuma 響應紀錄';

    private const string REQUEST_LOG_MESSAGE = '\[.+\] external/api/sw/fuma 請求紀錄';

    private const string CALLBACK_FAILED_CODE = '9999';

    public function handle(): int
    {
        // CLI 指令不受 Web 記憶體限制，全量日誌可能佔用大量記憶體
        ini_set('memory_limit', '-1');

        $project = (string) $this->option('project');
        $dir = rtrim((string) $this->option('dir'), '/');
        $responseLimit = max(0, (int) $this->option('response-limit'));
        $gcpTimeout = max(30, (int) $this->option('gcloud-timeout'));
        $batchSize = max(1, (int) $this->option('batch'));
        $postTimeout = max(1, (int) $this->option('post-timeout'));

        $now = CarbonImmutable::now('Asia/Taipei');
        $dateInput = $this->option('date');
        $date = $dateInput
            ? CarbonImmutable::parse($dateInput, 'Asia/Taipei')->startOfDay()
            : $now->startOfDay();

        $isToday = $date->toDateString() === $now->toDateString();
        $fromUtc8 = $date->startOfDay();
        $toUtc8 = $isToday ? $now->endOfHour() : $date->endOfDay();
        $dateLabel = $date->format('Y-m-d');

        $startTime = $fromUtc8->utc()->format('Y-m-d\TH:i:s\Z');
        $endTime = $toUtc8->utc()->format('Y-m-d\TH:i:s\Z');

        if (!$this->checkGcloud()) {
            $this->error('找不到 gcloud，請先安裝並完成 gcloud auth login');

            return self::FAILURE;
        }

        $intervals = $this->buildHourlyIntervals($startTime, $endTime);
        $totalHours = count($intervals);
        $overallStart = microtime(true);

        $this->info("日期：{$dateLabel}，共 {$totalHours} 小時");

        $grand = ['total' => 0, 'success' => 0, 'skipped' => 0, 'failed' => 0];

        foreach ($intervals as $index => [$hourStart, $hourEnd]) {
            $hourCarbon = CarbonImmutable::parse($hourStart)->setTimezone('Asia/Taipei');
            $hourLabel = $hourCarbon->format('H');      // "00" ~ "23"
            $hourDisplay = $hourCarbon->format('Y-m-d H:i');
            $filePrefix = "{$dateLabel}_{$hourLabel}h";  // 例 2026-03-27_09h

            $failedIdsFile = base_path("$dir/{$filePrefix}_1_failed_ids.json");
            $callbackPayloadsFile = base_path("$dir/{$filePrefix}_2_callback_payloads.json");
            $outputFile = base_path("$dir/{$filePrefix}_4_replay_results.json");

            $this->newLine();
            $this->line(sprintf('━━ [%d/%d] %s UTC+8 ━━', $index + 1, $totalHours, $hourDisplay));

            // Step 1: 收集該小時失敗 request_id
            $requestIds = $this->loadOrFetchFailedRequestIdsForHour(
                $failedIdsFile, $project, $hourStart, $hourEnd, $responseLimit, $gcpTimeout
            );

            if (empty($requestIds)) {
                $this->line('  → 無失敗紀錄，略過');

                continue;
            }

            $this->line('  Step 1: ' . count($requestIds) . " 筆 → $failedIdsFile");

            // Step 2: 取得原始請求參數（同小時範圍查詢）
            $callbackPayloads = $this->loadOrFetchCallbackPayloads(
                $callbackPayloadsFile, $requestIds, $project, $hourStart, $hourEnd, $batchSize
            );

            if (empty($callbackPayloads)) {
                $this->warn('  Step 2: 未取得任何 payload，略過');

                continue;
            }

            $this->line('  Step 2: ' . count($callbackPayloads) . " 筆 → $callbackPayloadsFile");

            // Step 3: 重新回送
            $this->line('  Step 3: 重送中...');
            $hourStartedAt = microtime(true);
            $results = $this->resendCallbacks($callbackPayloads, $postTimeout);

            // Step 4: 寫出結果
            $summary = $this->buildSummary($results, $hourStartedAt);
            $successful = array_values(array_filter($results, fn ($r) => $r['error'] === null && !$r['skipped']));

            file_put_contents($outputFile, json_encode([
                'summary' => $summary,
                'successful' => $successful,
                'results' => $results,
            ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

            $this->line("  Step 4: 成功 {$summary['success']} / 略過 {$summary['skipped']} / 失敗 {$summary['failed']} → $outputFile");

            $grand['total'] += $summary['total'];
            $grand['success'] += $summary['success'];
            $grand['skipped'] += $summary['skipped'];
            $grand['failed'] += $summary['failed'];
        }

        $elapsed = round(microtime(true) - $overallStart, 1);

        $this->newLine();
        $this->info('═══════════════ 全日彙總 ═══════════════');
        $this->line("total   : {$grand['total']}");
        $this->line("success : {$grand['success']}");
        $this->line("skipped : {$grand['skipped']} (Record duplicated)");
        $this->line("failed  : {$grand['failed']}");
        $this->line("elapsed : {$elapsed}s");

        return self::SUCCESS;
    }

    /**
     * 載入或查詢單一小時的失敗 request_id 清單。
     * 快取檔存在時直接讀取，否則查詢 GCP 後寫檔。
     */
    private function loadOrFetchFailedRequestIdsForHour(
        string $cacheFile,
        string $project,
        string $hourStart,
        string $hourEnd,
        int $limit,
        int $gcpTimeout,
    ): array {
        if (is_file($cacheFile)) {
            return json_decode(file_get_contents($cacheFile), true) ?? [];
        }

        $filter = 'jsonPayload.message=~"' . self::RESPONSE_LOG_PATTERN . '" '
            . 'jsonPayload.payload.context.response.data.code="' . self::CALLBACK_FAILED_CODE . '" '
            . 'timestamp>="' . $hourStart . '" '
            . 'timestamp<="' . $hourEnd . '"';

        $logEntries = $this->readGcpLogs($filter, $project, $limit, $gcpTimeout);

        $seen = [];
        $requestIds = [];

        foreach ($logEntries as $logEntry) {
            $requestId = $logEntry['jsonPayload']['payload']['context']['request_id'] ?? null;
            if (!is_string($requestId) || $requestId === '') {
                continue;
            }
            if (!isset($seen[$requestId])) {
                $seen[$requestId] = true;
                $requestIds[] = $requestId;
            }
        }

        unset($logEntries);

        file_put_contents($cacheFile, json_encode($requestIds, JSON_PRETTY_PRINT));

        return $requestIds;
    }

    /**
     * 將 UTC 起訖時間拆分為每小時區間。
     *
     * @return array<int, array{0: string, 1: string}> [[startZ, endZ], ...]
     */
    private function buildHourlyIntervals(string $startTimeUtc, string $endTimeUtc): array
    {
        $start = CarbonImmutable::parse($startTimeUtc);
        $end = CarbonImmutable::parse($endTimeUtc);
        $intervals = [];
        $current = $start->startOfHour();

        while ($current->lte($end)) {
            $intervals[] = [
                ($current->lt($start) ? $start : $current)->format('Y-m-d\TH:i:s\Z'),
                ($current->endOfHour()->gt($end) ? $end : $current->endOfHour())->format('Y-m-d\TH:i:s\Z'),
            ];
            $current = $current->addHour();
        }

        return $intervals;
    }

    /**
     * 若快取檔存在則直接載入，否則查詢 GCP 並存檔。
     * 回傳 [{request_id, url, params}] 陣列。
     */
    private function loadOrFetchCallbackPayloads(
        string $cacheFile,
        array $requestIds,
        string $project,
        string $startTime,
        string $endTime,
        int $batchSize
    ): array {
        if (is_file($cacheFile)) {
            $this->line("Step 2: 載入快取 → $cacheFile");

            return json_decode(file_get_contents($cacheFile), true) ?? [];
        }

        $this->info('Step 2: 查詢原始請求參數...');

        $chunks = array_chunk($requestIds, $batchSize);
        $totalBatches = count($chunks);
        $callbackPayloads = [];
        $completedBatches = 0;

        foreach ($chunks as $chunk) {
            $batchRecords = $this->queryRequestLogBatch($chunk, $project, $startTime, $endTime);
            foreach ($batchRecords as $record) {
                $callbackPayloads[] = [
                    'request_id' => $record['request_id'],
                    'url' => $record['url'],
                    'params' => $record['params'],
                ];
            }

            $completedBatches++;
            if ($completedBatches % 10 === 0 || $completedBatches === $totalBatches) {
                $count = count($callbackPayloads);
                $this->line("GCP 進度 $completedBatches/{$totalBatches}，已取得 $count");
            }
        }

        file_put_contents($cacheFile, json_encode($callbackPayloads, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        return $callbackPayloads;
    }

    private function queryRequestLogBatch(
        array $batch,
        string $project,
        string $startTime,
        string $endTime
    ): array {
        $ids = implode(' OR ', array_map(fn ($id) => '"' . $id . '"', $batch));
        $filter = 'jsonPayload.payload.context.request_id=(' . $ids . ') '
            . 'jsonPayload.message=~"' . self::REQUEST_LOG_MESSAGE . '" '
            . 'timestamp>="' . $startTime . '" '
            . 'timestamp<="' . $endTime . '"';

        $callbacks = [];
        foreach ($this->readGcpLogs($filter, $project, count($batch) * 2) as $logEntry) {
            $logContext = $logEntry['jsonPayload']['payload']['context'] ?? null;
            if (!is_array($logContext)) {
                continue;
            }

            $requestId = $logContext['request_id'] ?? null;
            $url = $logContext['request']['url'] ?? null;
            $params = $logContext['request']['params'] ?? null;

            if (!is_string($requestId) || !is_string($url) || !is_array($params)) {
                continue;
            }

            $callbacks[] = [
                'request_id' => $requestId,
                'url' => $url,
                'params' => $params,
            ];
        }

        return $callbacks;
    }

    private function resendCallbacks(array $callbackPayloads, int $timeout): array
    {
        $totalCallbacks = count($callbackPayloads);
        $results = [];

        foreach ($callbackPayloads as $callback) {
            $response = Http::timeout($timeout)
                ->acceptJson()
                ->asJson()
                ->post($callback['url'], $callback['params']);

            $results[] = $this->parseCallbackResult($callback, $response);

            $sentCount = count($results);
            if ($sentCount % 100 === 0 || $sentCount === $totalCallbacks) {
                $this->logResendProgress($results, $sentCount, $totalCallbacks);
            }
        }

        return $results;
    }

    private function parseCallbackResult(array $callback, mixed $response): array
    {
        $responseBody = $response?->json();
        if (!is_array($responseBody)) {
            $responseBody = $response?->body();
        }

        $errorMessage = match (true) {
            !$response => 'No response',
            $response->failed() => 'HTTP ' . $response->status(),
            default => null,
        };

        $isDuplicateRecord = is_array($responseBody)
            && (($responseBody['code'] ?? null) === 9999)
            && str_contains((string) ($responseBody['msg'] ?? ''), 'Record duplicated');

        return [
            'request_id' => $callback['request_id'],
            'url' => $callback['url'],
            'params' => $callback['params'],
            'status' => $response?->status(),
            'response' => $responseBody,
            'skipped' => $isDuplicateRecord,
            'error' => $isDuplicateRecord ? null : $errorMessage,
        ];
    }

    private function logResendProgress(array $results, int $sentCount, int $totalCallbacks): void
    {
        $success = count(array_filter($results, fn ($r) => $r['error'] === null && !$r['skipped']));
        $skipped = count(array_filter($results, fn ($r) => $r['skipped'] === true));
        $failed = count(array_filter($results, fn ($r) => $r['error'] !== null));
        $this->line("重送進度 $sentCount/{$totalCallbacks}，成功 $success / 略過 $skipped / 失敗 $failed");
    }

    private function buildSummary(array $callbackResults, float $startedAt): array
    {
        $success = count(array_filter($callbackResults, fn ($r) => $r['error'] === null && !$r['skipped']));
        $skipped = count(array_filter($callbackResults, fn ($r) => $r['skipped'] === true));
        $failed = count(array_filter($callbackResults, fn ($r) => $r['error'] !== null));

        return [
            'total' => count($callbackResults),
            'success' => $success,
            'skipped' => $skipped,
            'failed' => $failed,
            'generated_at' => CarbonImmutable::now('UTC')->toIso8601String(),
            'elapsed_seconds' => round(microtime(true) - $startedAt, 1),
        ];
    }
}
