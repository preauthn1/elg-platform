<?php

declare(strict_types=1);

namespace App\Console\Commands\Vendor;

use App\Data\BetRecordFetchChunkResultData;
use App\Data\VendorBetRecordFetchResultData;
use App\Helpers\Common;
use App\Models\Vendor;
use App\Services\BetRecordFetchService;
use Carbon\Carbon;
use Closure;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * 統一拉注單排程（純調度殼）
 *
 * 業務邏輯收斂於 BetRecordFetchService，本命令依 option 調用對應語意方法並渲染輸出：
 * 無 flag → dispatchQueueFetch()、--direct → fetchNow()、--dry-run → previewFetch()（只比對不寫入）。
 * --use-cache 讓乾跑拉回的三方回應在 1 小時內被正式模式沿用（拉取失敗不進快取）。
 *
 * php artisan fetch_bet_record                            # 排程模式：逐商派發 queue，預設前 1 小時
 * php artisan fetch_bet_record --vendor=6 --direct        # ACE333 同步補拉（不派發 queue）
 * php artisan fetch_bet_record --vendor=6 --dry-run       # 乾跑（不寫入資料）
 * php artisan fetch_bet_record --minutes=30               # 前 30 分鐘
 * php artisan fetch_bet_record --date=2026-06-10          # 全天
 * php artisan fetch_bet_record --start=2026-06-10@03 --end=2026-06-10@05
 * php artisan fetch_bet_record --vendor=54 --date=2026-06-10 --direct --update   # 金額異動的既有列一併更新
 *
 * 新接 TW 遊戲商只需在 Driver 實作 ShouldScheduleBetRecordFetch，無需新增 Command。
 *
 * @knowledge rules/bet-record-pull
 */
class FetchBetRecordCommand extends Command
{
    protected $signature = 'fetch_bet_record
        {--vendor= : 指定 vendor_id（預設跑所有實作 ShouldScheduleBetRecordFetch 的遊戲商）}
        {--vendor-info= : 指定 vendor_info id（需搭配 --vendor；預設該商全部啟用中商戶）}
        {--start= : 開始時間，格式 yyyy-MM-dd@HH}
        {--end= : 結束時間，格式 yyyy-MM-dd@HH}
        {--minutes= : 拉取最近 N 分鐘}
        {--date= : 拉取指定日期（全天），格式 yyyy-MM-dd}
        {--direct : 同步跑完整個窗口不派發 queue（救援用，放寬至 SupportsBetRecordFetch）}
        {--update : 金額異動時更新既有注單列並重轉 TBR（預設純新增去重；取消單靠此生效）}
        {--dry-run : 乾跑模式，只打三方與 DB 比對、不寫入，列出將新增／金額異動的注單}
        {--use-cache : 沿用 1 小時內快取的三方回應（乾跑後正式跑免重打三方；拉取失敗不進快取）}';

    protected $description = '拉取遊戲商注單並同步至 total_bet_records（派發 FetchVendorBetRecord Job；單一錢包只支援 --dry-run 比對）';

    public function __construct(private readonly BetRecordFetchService $fetchService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        [$startTime, $endTime] = $this->parseInputTime();
        $this->info(sprintf('時間範圍: %s ~ %s', $startTime, $endTime));

        $vendorId = $this->option('vendor') !== null ? (int) $this->option('vendor') : null;
        $vendorInfoId = $this->option('vendor-info') !== null ? (int) $this->option('vendor-info') : null;
        $updateExisting = (bool) $this->option('update');
        $useCache = (bool) $this->option('use-cache');

        if ($vendorInfoId !== null && $vendorId === null) {
            $this->error('--vendor-info 需搭配 --vendor 使用');

            return self::FAILURE;
        }

        if ($useCache) {
            $this->info('使用三方回應快取（TTL 1 小時；命中不打三方，拉取失敗不進快取）');
        }

        $hasError = false;
        // 同步模式整個窗口跑完才回傳結果，中途沒有輸出；逐片印一行，免得操作者以為卡住
        $this->fetchService->onChunkComplete($this->chunkProgressReporter());

        try {
            if ($this->option('dry-run')) {
                $this->warn('=== 乾跑模式：只比對不寫入 ===');
                $hasError = $this->renderVendorResults($this->fetchService->previewFetch($vendorId, $startTime, $endTime, $updateExisting, $vendorInfoId, $useCache));
                $this->info('=== 乾跑模式結束：未寫入任何資料 ===');
            } elseif ($this->option('direct')) {
                $hasError = $this->renderVendorResults($this->fetchService->fetchNow($vendorId, $startTime, $endTime, $updateExisting, $vendorInfoId, $useCache));
            } else {
                $this->renderDispatched($this->fetchService->dispatchQueueFetch($vendorId, $startTime, $endTime, $updateExisting, $vendorInfoId));
            }
        } catch (Throwable $e) {
            $this->error($e->getMessage());
            Log::error('FetchBetRecordCommand 錯誤', [
                'error' => $e->getMessage(),
                'trace' => Common::formatTrace($e),
            ]);

            return self::FAILURE;
        }

        // 任一商拉取失敗即以失敗作收，Olympus 操作紀錄才不會誤標成功
        return $hasError ? self::FAILURE : self::SUCCESS;
    }

    /**
     * 逐片進度：`[12/144] 2026-09-21 02:00:00 三方 8 筆，新增 3、更新 0`
     *
     * 只在同步模式（--dry-run / --direct）生效；queue 模式每片是獨立 job，各自有自己的執行列。
     * 撞三方流量限制的片改印等待行、不佔片號，等預算釋放重拉成功才算一片。
     */
    private function chunkProgressReporter(): Closure
    {
        return function (Vendor $vendor, int $chunk, int $totalChunks, string $from, BetRecordFetchChunkResultData $result): void {
            // 撞 rate limit 的片沒跑完、整片會重拉，印成進度行會讓同一時間片出現多行
            // 「三方 0 筆」，讀起來像那個時段真的沒注單（實際只是還沒拉）
            if ($result->retryAfterSeconds > 0) {
                $this->warn(sprintf(
                    '  %s %s 觸發三方流量限制，等待 %d 秒後重拉同一片',
                    $vendor->name,
                    $from,
                    $result->retryAfterSeconds,
                ));

                return;
            }

            $this->line(sprintf(
                '  [%d/%d] %s %s 三方 %d 筆，新增 %d、更新 %d',
                $chunk,
                $totalChunks,
                $vendor->name,
                $from,
                $result->fetched,
                $result->inserted,
                $result->updated,
            ));
        };
    }

    /**
     * @param  list<array{vendor_id: int, vendor_name: string, error?: string}>  $dispatched
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

            $this->line("已派發: {$item['vendor_name']} (ID: {$item['vendor_id']})");
        }

        $this->info('共派發 ' . (count($dispatched) - $failed) . ' 個拉注單任務' . ($failed > 0 ? "，{$failed} 個派發失敗" : ''));
    }

    /**
     * @param  list<VendorBetRecordFetchResultData>  $results
     * @return bool 是否有任一商拉取失敗
     */
    private function renderVendorResults(array $results): bool
    {
        if ($results === []) {
            $this->warn('沒有可處理的遊戲商');

            return false;
        }

        $hasError = false;

        foreach ($results as $result) {
            if ($result->error !== null) {
                $hasError = true;
                $this->error("⚠ {$result->vendorName} (ID: {$result->vendorId}): {$result->error}");

                continue;
            }

            $this->line(sprintf(
                '%s (ID: %d): 三方回傳 %d 筆，新增 %d 筆，更新 %d 筆%s，共 %d 個時間片',
                $result->vendorName,
                $result->vendorId,
                $result->fetched,
                $result->inserted,
                $result->updated,
                $result->missing > 0 ? sprintf('，三方查無 %d 筆', $result->missing) : '',
                $result->chunks,
            ));

            if ($result->pendingFrom !== null) {
                $this->warn($result->cancelled
                    ? "  ⚠ 已依「中斷」要求停止，剩餘窗口未處理（自 {$result->pendingFrom} 起）"
                    : "  ⚠ rate limit 預算用罄，剩餘窗口未處理（自 {$result->pendingFrom} 起）");
            }

            $this->renderPreviewLists($result);
        }

        $this->info('完成，共處理 ' . count($results) . ' 個遊戲商');

        return $hasError;
    }

    /**
     * 乾跑清單：將新增的注單（唯一鍵＋金額）與金額有異動的既有列（各欄位 from → to）；
     * 正式模式不收集清單，這裡自然不輸出
     */
    private function renderPreviewLists(VendorBetRecordFetchResultData $result): void
    {
        if ($result->previewNew !== []) {
            $this->line($this->previewHeading('新增', $result->inserted, count($result->previewNew)));

            foreach ($result->previewNew as $row) {
                $amounts = collect($row['amounts'])
                    ->map(fn (string $value, string $column): string => "{$column}={$value}")
                    ->implode(' ');
                $this->line("    {$row['key']}  {$amounts}");
            }
        }

        if ($result->previewChanged !== []) {
            $this->line($this->previewHeading('更新', $result->updated, count($result->previewChanged)));

            foreach ($result->previewChanged as $row) {
                $changes = collect($row['changes'])
                    ->map(fn (array $change, string $column): string => "{$column}: {$change['from']} → {$change['to']}")
                    ->implode('; ');
                $this->line("    {$row['key']}  {$changes}");
            }
        }

        if ($result->previewMissing !== []) {
            $this->warn($this->previewHeading('三方查無（DB 有、三方整個窗口沒回）', $result->missing, count($result->previewMissing)));
            // 窗口邊界誤差會誤報：DB 派彩時間在窗口內、三方 updated_at 落在窗口外的注單也會進這份清單
            $this->line('  ※ 反向對帳提示，不影響寫入；窗口邊界前後各放寬一小時重跑可排除邊界誤報');

            foreach ($result->previewMissing as $row) {
                $amounts = collect($row['amounts'])
                    ->map(fn (string $value, string $column): string => "{$column}={$value}")
                    ->implode(' ');
                $this->line("    {$row['key']}  {$amounts}");
            }
        }
    }

    private function previewHeading(string $label, int $total, int $listed): string
    {
        return $listed < $total
            ? sprintf('  %s %d 筆（僅列前 %d 筆）：', $label, $total, $listed)
            : sprintf('  %s %d 筆：', $label, $total);
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function parseInputTime(): array
    {
        if ($date = $this->option('date')) {
            $startTime = $date . ' 00:00:00';
            $endTime = Carbon::parse($startTime, 'UTC')->addDay()->toDateTimeString();
        } elseif ($minutes = $this->option('minutes')) {
            $endTime = Carbon::now('UTC')->toDateTimeString();
            $startTime = Carbon::now('UTC')->subMinutes((int) $minutes)->toDateTimeString();
        } else {
            $startTime = $this->option('start')
                ? str_replace('@', ' ', (string) $this->option('start')) . ':00:00'
                : Carbon::now('UTC')->subHour()->toDateTimeString();
            $endTime = $this->option('end')
                ? str_replace('@', ' ', (string) $this->option('end')) . ':00:00'
                : Carbon::parse($startTime, 'UTC')->addHour()->toDateTimeString();
        }

        return [$startTime, $endTime];
    }
}
