<?php

declare(strict_types=1);

namespace App\Console\Commands\Schedule;

use App\Facades\BotNotify;
use App\Helpers\Common;
use App\Services\VendorAccountPoolService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * 監測補不進去的帳號池並告警（純調度殼）
 *
 * 偵測邏輯收斂於 {@see VendorAccountPoolService::detectStuckPools()}，本命令只負責門檻過濾、
 * 渲染輸出與發通知。
 *
 * 要解的問題：補建對三方失敗時只會在 Log 留痕、水位靜靜停在原地，沒有任何人會發現。
 * NextSpin 的 casion365 商戶（vendor_info 185）2022-10-03 第一筆失敗起，四年累積 113 萬筆
 * 未完成佔號、真正綁到玩家的只有 234 筆，無人察覺（target/op_tickets#3463）。
 *
 * php artisan vendor:monitor-account-pool                   # 排程模式
 * php artisan vendor:monitor-account-pool --dry-run         # 只印不發通知
 * php artisan vendor:monitor-account-pool --consecutive=5   # 放寬門檻試跑
 *
 * @knowledge rules/vendor-account-pool
 */
class MonitorVendorAccountPool extends Command
{
    /**
     * 合併通知時最多列出的商戶數
     *
     * BotNotifyService 對整則訊息做 `Str::limit($message, 1000)`：**超過直接截斷，不報錯也不分則**。
     * 單筆約 80 字元，取 8 筆留足空間給站名前綴與結尾的「另有 N 個」提示。超出的完整清單進 Log。
     */
    private const int MAX_LISTED_MERCHANTS = 8;

    protected $signature = 'vendor:monitor-account-pool
                            {--hours=1 : 只看這段時間內建立的帳號列}
                            {--consecutive=10 : 連續幾筆未完成才告警}
                            {--sample=20 : 表尾取樣筆數（同時是連續數上限）}
                            {--dry-run : 只輸出結果，不發送通知}';

    protected $description = '監測補不進去的帳號池並發 Telegram 告警';

    public function __construct(private readonly VendorAccountPoolService $poolService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $threshold = (int) $this->option('consecutive');

        try {
            $stuck = $this->poolService->detectStuckPools(
                withinHours: (int) $this->option('hours'),
                sampleSize: (int) $this->option('sample'),
            );
        } catch (Throwable $e) {
            $this->error($e->getMessage());
            Log::error('帳號池監測錯誤', ['error' => $e->getMessage(), 'trace' => Common::formatTrace($e)]);

            return self::FAILURE;
        }

        // 門檻留在命令層：Service 回報所有「表尾有未完成」的商戶，要多嚴格由呼叫端決定
        $alerts = array_values(array_filter($stuck, fn (array $row): bool => $row['consecutive'] >= $threshold));

        if ($alerts === []) {
            $this->info('帳號池監測：無異常');

            return self::SUCCESS;
        }

        $message = $this->buildMessage($alerts);
        $this->warn($message);

        Log::warning('帳號池補建連續失敗', ['threshold' => $threshold, 'merchants' => $alerts]);

        // 只有正式環境送通知：dev 的三方多半根本打不通，壞掉的商戶一大把
        // （2026-10-07 實測命中 14 個），發出去只會變成沒人看的噪音。
        // BotNotifyService::shouldSkipDelivery() 只擋 testing，擋不到 dev。
        if ($this->option('dry-run')) {
            $this->line('（--dry-run：未發送通知）');
        } elseif (!app()->isProduction()) {
            $this->line(sprintf('（非正式環境 %s：未發送通知）', app()->environment()));
        } else {
            BotNotify::channel('warn')->send($message);
        }

        return self::SUCCESS;
    }

    /**
     * 組告警內文（站名前綴由 BotNotifyService 自動補上，這裡不重複帶）
     *
     * @param  list<array{vendor_id: int, vendor_name: string, vendor_info_id: int, vendor_info_name: string, consecutive: int, latest_username: string, latest_created_at: string, unbound: int, target: int}>  $alerts
     */
    private function buildMessage(array $alerts): string
    {
        if (count($alerts) === 1) {
            $row = $alerts[0];

            return implode(PHP_EOL, [
                sprintf(
                    '【帳號池異常】%s (%d) / %s (info %d)',
                    $row['vendor_name'],
                    $row['vendor_id'],
                    $row['vendor_info_name'],
                    $row['vendor_info_id'],
                ),
                '',
                $this->describeConsecutive($row),
                sprintf('池水位 %d／%d', $row['unbound'], $row['target']),
                '',
                sprintf('→ 確認三方商戶是否仍有效；已廢棄請停用 vendor_infos %d', $row['vendor_info_id']),
            ]);
        }

        $lines = [sprintf('【帳號池異常】%d 個商戶', count($alerts))];

        foreach (array_slice($alerts, 0, self::MAX_LISTED_MERCHANTS) as $row) {
            $lines[] = '';
            $lines[] = sprintf(
                '%s (%d) / %s (info %d)',
                $row['vendor_name'],
                $row['vendor_id'],
                $row['vendor_info_name'],
                $row['vendor_info_id'],
            );
            $lines[] = sprintf('%s，池水位 %d／%d', $this->describeConsecutive($row), $row['unbound'], $row['target']);
        }

        if (count($alerts) > self::MAX_LISTED_MERCHANTS) {
            $lines[] = '';
            $lines[] = sprintf('…另有 %d 個商戶，完整清單見 log', count($alerts) - self::MAX_LISTED_MERCHANTS);
        }

        return implode(PHP_EOL, $lines);
    }

    /**
     * 連續筆數受取樣數上限所限，達上限時標示為「≥」避免讀者誤以為剛好那麼多筆
     *
     * @param  array{consecutive: int, latest_username: string, latest_created_at: string}  $row
     */
    private function describeConsecutive(array $row): string
    {
        $atCap = $row['consecutive'] >= (int) $this->option('sample');

        return sprintf(
            '連續 %s%d 筆建立未完成，最新 %s @ %s',
            $atCap ? '≥' : '',
            $row['consecutive'],
            $row['latest_username'],
            $row['latest_created_at'],
        );
    }
}
