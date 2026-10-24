<?php

declare(strict_types=1);

namespace App\Console\Commands\Summary;

use App\Facades\BotNotify;
use App\Helpers\Common;
use App\Services\Summary\VipRewardSummaryService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * VIP 獎勵彙總（summary_event_records／summary_event_record_agents 的 event 9）target/tickets#4420。
 *
 * 取代 MySQL EVENT `Event_Summary_event_reocrd_by_vip` ＋ `SP_summary_event_record_by_vip`。
 * **部署後必須停用各站該 EVENT**，兩個寫入者不可並存——它們會對同一批小時桶各自刪除重建。
 *
 * 一支命令兩種模式，共用 {@see VipRewardSummaryService}，口徑只有一份：
 *
 * ```bash
 * php artisan summary:event-record-vip                              # 排程用：前 3 個整點小時
 * php artisan summary:event-record-vip --hours=6
 * php artisan summary:event-record-vip --from=2026-08-25 --dry-run  # 回補：先看區間與現有列數
 * php artisan summary:event-record-vip --from=2026-08-25 --to=2026-09-23
 * ```
 *
 * 回補模式逐日重建（一天一個交易），而非整段吞成一個交易：長區間的刪除加寫入若共用一個交易，
 * 鎖會壓在兩張千萬列表上太久；逐日切分後每天各自完整，中途斷掉也不會留下半套資料。
 * TPower 實測單日約 0.9 秒。
 */
class SummarizeVipReward extends Command
{
    protected $signature = 'summary:event-record-vip
        {--hours=3 : 回看的整點小時數（含剛結束的那一小時）；指定 --from 時忽略}
        {--from= : 回補起日（+8 日曆日，YYYY-MM-DD）；指定後改走逐日回補模式}
        {--to= : 回補迄日（+8 日曆日，含；預設今天）}
        {--dry-run : 只列出將重建的區間與現有列數，不寫入}';

    protected $description = '重建 VIP 獎勵彙總 summary_event_records／summary_event_record_agents（event 9）';

    /** 回補模式的日曆日時區（與報表、看板的 +8 日界一致） */
    private const string TZ = '+08:00';

    public function __construct(private readonly VipRewardSummaryService $service)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        try {
            return ((string) $this->option('from')) === ''
                ? $this->runHourly()
                : $this->runBackfill();
        } catch (Throwable $e) {
            $message = sprintf(
                '[系統通知] summary:event-record-vip 失敗（連線 %s）：%s',
                DB::getDefaultConnection(),
                $e->getMessage(),
            );

            $this->error($message);
            Log::error($message, ['trace' => Common::formatTrace($e)]);
            BotNotify::channel('warn')->send($message);

            return self::FAILURE;
        }
    }

    /**
     * 排程模式：重建 [現在整點 − N 小時, 現在整點)。
     *
     * 回看 3 小時沿用原 SP 的視窗，包容晚到的 VIP 派獎寫入。
     */
    private function runHourly(): int
    {
        $hours = max(1, (int) $this->option('hours'));
        $endUtc = Carbon::now('UTC')->startOfHour();
        $startUtc = $endUtc->copy()->subHours($hours);

        $this->info(sprintf(
            '連線：%s／窗口 UTC [%s, %s)／模式：%s',
            DB::getDefaultConnection(),
            $startUtc->toDateTimeString(),
            $endUtc->toDateTimeString(),
            $this->option('dry-run') ? '乾跑' : '執行',
        ));

        $this->rebuildSegment($startUtc->toDateTimeString(), $endUtc->toDateTimeString());

        return self::SUCCESS;
    }

    /**
     * 回補模式：把 +8 日曆日區間換成 UTC，逐日重建。
     */
    private function runBackfill(): int
    {
        $from = (string) $this->option('from');
        $to = (string) ($this->option('to') ?: Carbon::now(self::TZ)->toDateString());

        $fromDay = Carbon::parse($from, self::TZ)->startOfDay();
        $toDay = Carbon::parse($to, self::TZ)->startOfDay();

        if ($toDay->lt($fromDay)) {
            $this->error('--to 不可早於 --from');

            return self::FAILURE;
        }

        // 小時桶以 UTC 整點為界，迄點不含：迄日隔天 00:00(+8) 與現在整點取較早者
        $hardEnd = min($toDay->copy()->addDay()->utc(), Carbon::now('UTC')->startOfHour());

        $this->info(sprintf(
            '連線：%s／區間（+8）：%s〜%s ＝ UTC [%s, %s)／模式：%s',
            DB::getDefaultConnection(),
            $fromDay->toDateString(),
            $toDay->toDateString(),
            $fromDay->copy()->utc()->toDateTimeString(),
            $hardEnd->toDateTimeString(),
            $this->option('dry-run') ? '乾跑' : '執行',
        ));

        for ($day = $fromDay->copy(); $day->lte($toDay); $day->addDay()) {
            $startUtc = $day->copy()->utc();
            $endUtc = min($day->copy()->addDay()->utc(), $hardEnd);

            if ($endUtc->lte($startUtc)) {
                continue;
            }

            $this->rebuildSegment(
                $startUtc->toDateTimeString(),
                $endUtc->toDateTimeString(),
                $day->toDateString(),
            );
        }

        return self::SUCCESS;
    }

    /**
     * 重建一段 UTC 區間；乾跑時只印出現有列數。
     */
    private function rebuildSegment(string $startUtc, string $endUtc, ?string $label = null): void
    {
        $prefix = $label === null ? '' : sprintf('%s（+8）：', $label);
        $old = $this->service->countMembers($startUtc, $endUtc);

        if ($this->option('dry-run')) {
            $this->info(sprintf('%s將刪除 %d 筆會員彙總後重建', $prefix, $old));

            return;
        }

        $startedAt = microtime(true);
        $written = $this->service->rebuild($startUtc, $endUtc);

        $this->info(sprintf(
            '%s刪 %d、寫會員彙總 %d 筆、代理彙總 %d 筆（%.1f 秒）',
            $prefix,
            $old,
            $written['members'],
            $written['agents'],
            microtime(true) - $startedAt,
        ));
    }
}
