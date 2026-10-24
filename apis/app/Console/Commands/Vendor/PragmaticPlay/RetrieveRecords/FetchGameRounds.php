<?php

declare(strict_types=1);

namespace App\Console\Commands\Vendor\PragmaticPlay\RetrieveRecords;

use App\Console\Commands\Vendor\PragmaticPlay\Helpers\FetchGameRoundsReportWriter;
use App\Console\Commands\Vendor\PragmaticPlay\Services\FetchGameRoundsService;
use App\Enums\Vendor\VendorIdEnum;
use App\Models\VendorInfo;
use App\Services\BotNotifyService;
use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * 取得 PragmaticPlay 遊戲輪次資料
 *
 * 使用方式：
 * php artisan ppsw:game-rounds 2026-01-18
 * php artisan ppsw:compare-records 2026-01-13
 * php artisan ppsw:test-timepoint '2026-01-14 01:20:00'
 */
class FetchGameRounds extends Command
{
    protected $signature = 'ppsw:game-rounds
                            {date?}
                            {--delay-ms=30 : 每筆請求間延遲（毫秒），調低可加速但避免觸發 PPSW 限流}
                            {--dry-run : 只收集 URL 不實際請求（僅生成 CSV 檔案）}
                            {--missing-only : 僅重新拉取與 bet_records 不一致的時間點（依 bet_id 反推 timepoint）}';

    protected $description = '透過 DataFeed API 取得 PPSW 遊戲輪次資料並儲存至資料庫（序列請求）';

    private int $delayMs = 30;

    private bool $dryRun = false;

    private bool $missingOnly = false;

    /**
     * @var array<int,true>|null 缺漏 timepoint 索引快取（key = 0~287），跨 domain/dataType 共用
     */
    private ?array $missingIndexes = null;

    private BotNotifyService $notifyService;

    private FetchGameRoundsService $service;

    private ?FetchGameRoundsReportWriter $reportWriter = null;

    public function __construct()
    {
        parent::__construct();
        $this->notifyService = new BotNotifyService;
        $this->service = new FetchGameRoundsService;
    }

    /**
     * Execute the console command.
     *
     * @throws Exception
     */
    public function handle(): int
    {
        ini_set('memory_limit', '512M');

        // 讀取批次間延遲（毫秒）
        $this->delayMs = max(0, min(5000, (int) $this->option('delay-ms')));

        // 讀取 dry-run 模式
        $this->dryRun = (bool) $this->option('dry-run');

        // 讀取 missing-only 模式
        $this->missingOnly = (bool) $this->option('missing-only');

        if ($this->dryRun) {
            $this->warn('⚠️  Dry-run 模式：只收集 URL，不實際發送請求');
            $this->newLine();
        }

        $timestamp = $this->getTimestampFromInput();
        $vendorInfo = $this->getVendorInfo();
        $domainList = $this->getDomainList($vendorInfo->info);

        $this->service->initTable();

        // missing-only 模式：先計算當日缺漏的 timepoint 索引
        if ($this->missingOnly) {
            $this->missingIndexes = $this->computeMissingTimepointIndexes($timestamp);

            if (empty($this->missingIndexes)) {
                $this->info('✅ 當日 bet_records 與 game_rounds 完全對齊，無需補拉');

                return 0;
            }

            $this->warn(sprintf(
                '🎯 Missing-only 模式：偵測到 %d 個需重拉的 timepoint（共 288 個中）',
                count($this->missingIndexes)
            ));
            $this->newLine();
        }

        // 建立請求 URL 報告檔（同一次執行寫入同一檔案）
        $this->reportWriter = new FetchGameRoundsReportWriter;
        // $this->reportWriter->writeHeader('PPSW gamerounds request urls', [
        //     'date_utc' => Carbon::createFromTimestamp($timestamp, 'UTC')->format('Y-m-d'),
        //     'timestamp_utc' => $timestamp,
        //     'domains' => count($domainList),
        //     'concurrent' => $this->dryRun ? 'N/A (dry-run)' : $this->concurrent,
        // ]);

        $this->info('開始抓取遊戲輪次資料...');
        $this->info('日期: ' . Carbon::createFromTimestamp($timestamp, 'UTC')->format('Y-m-d') . ' (UTC)');
        $this->info('域名數量: ' . count($domainList));
        $this->info('模式: ' . ($this->dryRun ? 'Dry-run（僅收集 URL）' : '正常採集（序列）'));
        if (!$this->dryRun) {
            $this->info("批次延遲: {$this->delayMs}ms");
        }
        $this->newLine();

        foreach ($domainList as $domainIndex => $domain) {
            $this->line("處理域名 [$domain] (" . ($domainIndex + 1) . '/' . count($domainList) . ')');
            $this->fetchAndSaveGameRounds($domain, $timestamp, $vendorInfo->info);
        }

        $this->info('✅ 遊戲輪次資料抓取完成！');
        $this->newLine();

        // 自動同步 payout_time（dry-run 模式跳過）
        if (!$this->dryRun) {
            $dateStr = Carbon::createFromTimestamp($timestamp, 'UTC')->format('Y-m-d');
            $this->info('========================================');
            $this->info('🔄 開始同步 payout_time...');
            $this->info('========================================');

            try {
                $exitCode = \Illuminate\Support\Facades\Artisan::call('ppsw:sync-payout-time', [
                    'date' => $dateStr,
                ], $this->output);

                if ($exitCode === 0) {
                    $this->info('✅ payout_time 同步完成！');
                } else {
                    $this->warn("⚠️  payout_time 同步失敗（退出碼: {$exitCode}）");
                }
            } catch (Exception $e) {
                $this->error('❌ payout_time 同步異常: ' . $e->getMessage());
                Log::error('Auto sync payout_time failed', [
                    'date' => $dateStr,
                    'error' => $e->getMessage(),
                ]);
            }

            $this->newLine();
        }
        $this->info('✅ 全部完成！');

        if ($this->reportWriter !== null) {
            $this->newLine();
            $this->info('📄 Request URLs CSV 報告: ' . $this->reportWriter->path());
            $this->reportWriter->close();
        }

        return 0;
    }

    /**
     * 計算當日缺漏的 timepoint 索引
     *
     * 透過 LEFT JOIN 找出 bet_records 中沒有對應 game_round 的 bet_id，
     * 將其 bet_time 換算為 5 分鐘 timepoint 索引（0 ~ 287），
     * 並加入前後 ±1 的安全邊界（涵蓋跨 timepoint 邊界的 Free Spin 等情境）。
     *
     * @return array<int,true> key 為 timepoint 索引，便於後續 array_intersect_key 過濾
     */
    private function computeMissingTimepointIndexes(int $dayTimestamp): array
    {
        $startStr = Carbon::createFromTimestamp($dayTimestamp, 'UTC')->format('Y-m-d H:i:s');
        $endStr = Carbon::createFromTimestamp($dayTimestamp + 86400, 'UTC')->format('Y-m-d H:i:s');

        $missingTimes = DB::table('ppsw_bet_records as br')
            ->leftJoin('ppsw_game_rounds as gr', 'br.bet_id', '=', 'gr.playSessionID')
            ->whereBetween('br.payout_time', [$startStr, $endStr])
            ->whereNull('gr.playSessionID')
            ->pluck('br.bet_time')
            ->all();

        if (empty($missingTimes)) {
            return [];
        }

        $indexes = [];
        foreach ($missingTimes as $time) {
            if (empty($time)) {
                continue;
            }

            $ts = Carbon::parse($time, 'UTC')->timestamp;
            $idx = (int) floor(($ts - $dayTimestamp) / 300);

            // ±1 timepoint 安全邊界，避免跨邊界 round 漏掉
            for ($offset = -1; $offset <= 1; $offset++) {
                $candidate = $idx + $offset;
                if ($candidate >= 0 && $candidate < 288) {
                    $indexes[$candidate] = true;
                }
            }
        }

        ksort($indexes);

        return $indexes;
    }

    /**
     * 從使用者輸入或參數取得時間戳記（UTC 時區）
     */
    private function getTimestampFromInput(): int
    {
        // 優先使用命令參數
        $date = $this->argument('date');

        // 如果沒有參數，詢問使用者
        if (empty($date)) {
            $date = $this->ask('請輸入查詢日期 Y-m-d (留空使用今天，使用 UTC 時區)');
        }

        if (empty($date)) {
            return Carbon::now('UTC')->startOfDay()->timestamp;
        }

        return Carbon::createFromFormat('Y-m-d', (string) $date, 'UTC')->startOfDay()->timestamp;
    }

    /**
     * 取得廠商資訊
     */
    private function getVendorInfo(): VendorInfo
    {
        $vendorInfo = VendorInfo::where('vendor_id', VendorIdEnum::PPSW->value)->first();

        if (!$vendorInfo) {
            throw new Exception('找不到 PPSW 廠商資訊');
        }

        return $vendorInfo;
    }

    /**
     * 取得域名列表
     */
    private function getDomainList(array $info): array
    {
        return $this->service->getEnvironmentDomains($info);
    }

    /**
     * 抓取並儲存遊戲輪次資料（支持 HTTP 并发）
     *
     * 主流程控制方法，負責協調各個子方法
     */
    private function fetchAndSaveGameRounds(string $domain, int $timestamp, array $info): void
    {
        $credentials = $this->prepareCredentials($info);
        $dataTypes = $this->getDataTypes();

        foreach ($dataTypes as $dataType => $description) {
            $this->processDataType($domain, $timestamp, $credentials, $dataType, $description);
        }
    }

    /**
     * 準備 API 認證資訊
     */
    private function prepareCredentials(array $info): array
    {
        return [
            'login' => $info['SECURE_LOGIN'],
            'password' => $info['SECRET_KEY'],
        ];
    }

    /**
     * 獲取要處理的數據類型
     */
    private function getDataTypes(): array
    {
        return [
            'RNG' => '電子遊戲',
            'LC' => '真人遊戲',
        ];
    }

    /**
     * 處理單一數據類型的遊戲輪次
     */
    private function processDataType(
        string $domain,
        int $timestamp,
        array $credentials,
        string $dataType,
        string $description
    ): void {
        // 設定資料類型
        $credentials['dataType'] = $dataType;

        $this->displayProcessingHeader($domain, $description);

        // 生成所有時間點的請求 URL
        $requests = $this->buildTimePointRequests($domain, $credentials, $timestamp);

        // missing-only 模式：只保留缺漏的 timepoint 索引
        if ($this->missingOnly && $this->missingIndexes !== null) {
            $requests = array_intersect_key($requests, $this->missingIndexes);

            if (empty($requests)) {
                $this->line(sprintf('  ⏭️  [%s/%s] 無需補拉的 timepoint，略過', $domain, $dataType));

                return;
            }

            $this->line(sprintf(
                '  🎯 [%s/%s] 補拉 %d 個 timepoint',
                $domain,
                $dataType,
                count($requests)
            ));
        }

        // 記錄所有請求連結到報告檔案
        if ($this->reportWriter !== null) {
            $this->reportWriter->appendUrls($domain, $dataType, array_values($requests));
        }

        // 創建進度條
        $progressBar = $this->createProgressBar(count($requests));

        // 初始化統計資料
        $stats = $this->initializeStats(count($requests));

        // 序列處理請求
        $this->fetchTimepointsSerial($domain, $requests, $progressBar, $stats);

        $progressBar->finish();
        $this->newLine(2);

        // 失敗時間點重試（最多 2 輪，間隔 1 秒）
        $this->retryFailedTimepoints($domain, $stats);

        // 顯示處理結果
        $this->finishProcessing($domain, $dataType, $stats);
    }

    /**
     * 顯示處理開始的標題
     */
    private function displayProcessingHeader(string $domain, string $description): void
    {
        $this->newLine();
        $this->line(sprintf('開始處理域名: %s %s', $domain, $description));
    }

    /**
     * 創建進度條
     */
    private function createProgressBar(int $total): \Symfony\Component\Console\Helper\ProgressBar
    {
        $progressBar = $this->output->createProgressBar($total);
        $progressBar->start();

        return $progressBar;
    }

    /**
     * 初始化統計資料
     */
    private function initializeStats(int $total): array
    {
        return [
            'total' => $total,
            'success' => 0,
            'empty' => 0,
            'failed' => 0,
            'inserted' => 0,
            'failed_timepoints' => [],
            'failed_urls' => [],
            'retried_success' => 0,
            'final_failed' => 0,
        ];
    }

    /**
     * 序列處理 HTTP 請求（每筆獨立請求，間隔 delay-ms）
     *
     * 不使用 Http::pool，因實測 PPSW 對同 IP 同時 ≥2 個 in-flight 請求會拒絕約 43%。
     * 序列模式可達 100% 成功率，且效能瓶頸僅在 PPSW 響應時間。
     */
    private function fetchTimepointsSerial(
        string $domain,
        array $requests,
        $progressBar,
        array &$stats
    ): void {
        // Dry-run 模式：只標記進度，不實際請求
        if ($this->dryRun) {
            foreach ($requests as $url) {
                $stats['success']++;
                $progressBar->advance();
            }

            return;
        }

        $sleepUs = $this->delayMs * 1000;
        $count = count($requests);
        $i = 0;

        foreach ($requests as $url) {
            $this->fetchOneTimepoint($domain, $url, $stats);
            $progressBar->advance();

            if (++$i < $count && $sleepUs > 0) {
                usleep($sleepUs);
            }
        }

        gc_collect_cycles();
    }

    /**
     * 抓取單一 timepoint 並處理結果（序列共用 helper）
     *
     * 主流程與 retry 流程都呼叫這個方法，確保處理邏輯一致。
     */
    private function fetchOneTimepoint(string $domain, string $url, array &$stats, int $timeoutSec = 60): void
    {
        $timepointDateTime = $this->extractTimepointFromUrl($url);

        try {
            $response = Http::timeout($timeoutSec)->get($url);
            $this->processResponseData($domain, $response, $timepointDateTime, $stats, $url);
        } catch (Throwable $e) {
            $this->handleResponseError($domain, $timepointDateTime, $e, $stats, $url);
        }
    }

    /**
     * 處理響應數據
     */
    private function processResponseData(
        string $domain,
        $response,
        string $timepointDateTime,
        array &$stats,
        string $url
    ): void {
        // Http::pool 對單筆請求的連線失敗會回傳 Throwable 物件（不是 Response），需先處理
        if ($response instanceof Throwable) {
            throw $response;
        }

        // HTTP 4xx/5xx 必須計入 failed，不可當成空資料漏掉
        if (!$response->successful()) {
            throw new Exception(sprintf(
                'HTTP %d: %s',
                $response->status(),
                substr((string) $response->body(), 0, 200)
            ));
        }

        if (empty($response->body())) {
            $stats['empty']++;

            return;
        }

        try {
            $csvBody = $response->body();
            $rounds = $this->service->tidyCsv($csvBody);
            unset($csvBody);
        } catch (Exception $e) {
            $this->notifyService->channel('error')->send(
                '🚨 PPSW 遊戲輪次 CSV 解析失敗',
                [
                    'domain' => $domain,
                    'datetime' => $timepointDateTime,
                    'error' => $e->getMessage(),
                    'response_length' => strlen($response->body()),
                ]
            );

            Log::error('CSV 解析失敗', [
                'domain' => $domain,
                'datetime' => $timepointDateTime,
                'error' => $e->getMessage(),
                'response_preview' => substr($response->body(), 0, 500),
            ]);

            throw $e;
        }

        if (empty($rounds)) {
            $stats['empty']++;

            return;
        }

        try {
            $inserted = $this->service->batchInsertRounds($domain, $rounds, $timepointDateTime);
        } catch (Throwable $e) {
            // 保持原有通知行為：DB 失敗通知
            $this->notifyService->channel('error')->send(
                '🚨 PPSW 遊戲輪次寫入失敗',
                [
                    'domain' => $domain,
                    'datetime' => $timepointDateTime,
                    'error' => $e->getMessage(),
                ]
            );

            throw $e;
        }

        unset($rounds);

        $stats['inserted'] += $inserted;
        $stats['success']++;
    }

    /**
     * 處理響應錯誤
     */
    private function handleResponseError(
        string $domain,
        string $timepointDateTime,
        Throwable $e,
        array &$stats,
        string $url
    ): void {
        $stats['failed']++;
        $stats['failed_timepoints'][] = $timepointDateTime;
        $stats['failed_urls'][$timepointDateTime] = $url;

        Log::error('Error processing timepoint', [
            'domain' => $domain,
            'datetime' => $timepointDateTime,
            'error' => $e->getMessage(),
        ]);
    }

    /**
     * 完成處理並顯示統計資料
     */
    private function finishProcessing(
        string $domain,
        string $dataType,
        array $stats
    ): void {
        $this->displayStats($domain, $dataType, $stats);

        // 如果重試後仍有失敗的時間點，發送匯總通知
        if ($stats['failed'] > 0) {
            $this->notifyFailureSummary($domain, $dataType, $stats);
        }
    }

    /**
     * 序列重試失敗的時間點（低速、低併發）
     *
     * - 最多 2 輪
     * - 每筆間隔 1 秒，避免再次觸發限流
     * - 救回的時間點移出 failed，併入 retried_success
     * - 第二輪仍失敗的記入 final_failed
     */
    private function retryFailedTimepoints(string $domain, array &$stats): void
    {
        if ($this->dryRun || empty($stats['failed_urls'])) {
            $stats['final_failed'] = $stats['failed'];

            return;
        }

        $maxRounds = 2;

        for ($round = 1; $round <= $maxRounds; $round++) {
            if (empty($stats['failed_urls'])) {
                break;
            }

            $retryUrls = $stats['failed_urls'];
            $stats['failed_urls'] = [];
            $stats['failed_timepoints'] = [];
            $stats['failed'] = 0;

            $count = count($retryUrls);
            $this->newLine();
            $this->info(sprintf('🔄 [%s] 重試第 %d/%d 輪：%d 個失敗時間點', $domain, $round, $maxRounds, $count));

            $retryProgress = $this->createProgressBar($count);
            $previousSuccess = $stats['success'];
            $previousEmpty = $stats['empty'];

            foreach ($retryUrls as $url) {
                $this->fetchOneTimepoint($domain, $url, $stats, 120);
                $retryProgress->advance();
                usleep(1000000);
            }

            $retryProgress->finish();
            $this->newLine(2);

            $recovered = ($stats['success'] - $previousSuccess) + ($stats['empty'] - $previousEmpty);
            $stats['retried_success'] += $recovered;

            $this->info(sprintf(
                '🔄 [%s] 第 %d 輪重試完成：救回 %d 筆，仍失敗 %d 筆',
                $domain,
                $round,
                $recovered,
                count($stats['failed_urls']),
            ));
        }

        $stats['final_failed'] = $stats['failed'];
    }

    /**
     * 發送失敗匯總通知
     */
    private function notifyFailureSummary(string $domain, string $dataType, array $stats): void
    {
        $failedCount = $stats['failed'];
        $failedTimepoints = array_slice($stats['failed_timepoints'], 0, 5); // 只顯示前5個

        $this->notifyService->channel('error')->send(
            "⚠️ PPSW {$dataType} 處理失敗匯總",
            [
                'domain' => $domain,
                'data_type' => $dataType,
                'total_timepoints' => $stats['total'],
                'failed_count' => $failedCount,
                'failed_timepoints' => $failedTimepoints,
                'success_rate' => round(($stats['success'] / $stats['total']) * 100, 2) . '%',
            ]
        );
    }

    /**
     * 生成所有時間點的請求 URL
     */
    private function buildTimePointRequests(string $domain, array $credentials, int $timestamp): array
    {
        return $this->service->buildTimePointRequests($domain, $credentials, $timestamp, (string) ($credentials['dataType'] ?? ''));
    }

    /**
     * 從 URL 中提取時間點（用於日誌）
     */
    private function extractTimepointFromUrl(string $url): string
    {
        return $this->service->extractTimepointFromUrl($url);
    }

    /**
     * 显示统计信息
     */
    private function displayStats(string $domain, string $dataType, array $stats): void
    {
        $this->info("========== [$domain] $dataType 處理完成 ==========");
        $this->info("總時間點: {$stats['total']}");
        $this->info("成功處理: {$stats['success']}");
        $this->info("空資料: {$stats['empty']}");
        $this->info("重試救回: {$stats['retried_success']}");
        $this->info("最終失敗: {$stats['final_failed']}");
        $this->info("寫入記錄: {$stats['inserted']} 筆");

        if ($stats['final_failed'] > 0) {
            $this->error('⚠️  以下時間點重試後仍失敗:');
            foreach (array_slice($stats['failed_timepoints'], 0, 10) as $failed) {
                $this->line("  - $failed");
            }
            if (count($stats['failed_timepoints']) > 10) {
                $this->line('  ... 更多失敗記錄請查看日誌');
            }
        }

        $this->info('==========================================');
        $this->newLine();
    }
}
