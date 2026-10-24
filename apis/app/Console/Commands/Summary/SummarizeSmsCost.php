<?php

declare(strict_types=1);

namespace App\Console\Commands\Summary;

use App\Facades\BotNotify;
use App\Helpers\Common;
use App\Services\Summary\SmsCostSummaryService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * 簡訊成本彙總（summary_event_records／summary_event_record_agents 的 event 4）target/tickets#4424。
 *
 * 取代 MySQL EVENT `Event_Summary_event_reocrd_by_sms` ＋ `SP_summary_event_record_by_sms`。
 * **部署後必須停用各站該 EVENT**，兩個寫入者不可並存——它們會對同一批小時桶各自刪除重建。
 *
 * 一支命令兩種模式，共用 {@see SmsCostSummaryService}，口徑只有一份：
 *
 * ```bash
 * php artisan summary:event-record-sms                              # 排程用：前 3 個整點小時
 * php artisan summary:event-record-sms --hours=6
 * php artisan summary:event-record-sms --from=2026-08-25 --dry-run  # 回補：先看區間與現有列數
 * php artisan summary:event-record-sms --from=2026-08-25 --to=2026-09-23
 * ```
 *
 * ⚠️ 本指令加總的是 `sms_send_records.cost`（發送當下凍結的成本）。欄位上線前的歷史列
 * `cost` 是 0，要先跑 `sms:backfill-cost` 回補，否則這段區間會算成 0。每次執行都會印出
 * 該區間還有幾筆沒回補，非 0 就代表結果偏低。
 */
class SummarizeSmsCost extends Command
{
    protected $signature = 'summary:event-record-sms
        {--hours=3 : 回看的整點小時數（含剛結束的那一小時）；指定 --from 時忽略}
        {--from= : 回補起日（+8 日曆日，YYYY-MM-DD）；指定後改走逐日回補模式}
        {--to= : 回補迄日（+8 日曆日，含；預設今天）}
        {--dry-run : 只列出將重建的區間與現有列數，不寫入}';

    protected $description = '重建簡訊成本彙總 summary_event_records／summary_event_record_agents（event 4）';

    /** 回補模式的日曆日時區（與報表、看板的 +8 日界一致） */
    private const string TZ = '+08:00';

    public function __construct(private readonly SmsCostSummaryService $service)
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
                '[系統通知] summary:event-record-sms 失敗（連線 %s）：%s',
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
     * 回看 3 小時沿用原 SP 的視窗，包容晚到的發送紀錄狀態更新。
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
     *
     * 逐日一個交易，而非整段吞成一個：長區間的刪除加寫入若共用一個交易，鎖會壓在兩張大表上太久；
     * 逐日切分後每天各自完整，中途斷掉也不會留下半套資料。
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
        $unfrozen = $this->service->countUnfrozen($startUtc, $endUtc);

        if ($this->option('dry-run')) {
            $this->info(sprintf('%s將刪除 %d 筆會員彙總後重建', $prefix, $old));
            $this->warnUnfrozen($prefix, $unfrozen);

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
        $this->warnUnfrozen($prefix, $unfrozen);
    }

    /**
     * 區間內還有未凍結成本的發送時出聲：那些列的 `cost` 是 0，彙總會偏低。
     */
    private function warnUnfrozen(string $prefix, int $unfrozen): void
    {
        if ($unfrozen > 0) {
            $this->warn(sprintf(
                '%s⚠ 該區間尚有 %d 筆成功發送未回補成本（sms_gateway_info_id = 0），彙總金額會偏低；請先跑 sms:backfill-cost',
                $prefix,
                $unfrozen,
            ));
        }
    }
}
