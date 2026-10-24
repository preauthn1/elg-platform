<?php

declare(strict_types=1);

namespace App\Console\Commands\Order;

use App\Services\Summary\PaymentCostSummaryService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * 重算指定 +8 日期區間的支付成本彙總（event 3 代收／8 代付）target/tickets#4361。
 *
 * 與每小時排程 summary:payment-cost 共用 PaymentCostSummaryService，口徑只有一份；
 * 本命令只負責把 +8 日曆日換成 UTC 區間、印出概況。
 *
 * 用法（每個白標各跑一次，依 whitelabel-container-exec 注入 DB_CONNECTION）：
 *   php artisan order:recalc-payment-cost-summary --from=2026-09-01 --dry-run
 *   php artisan order:recalc-payment-cost-summary --from=2026-08-01 --to=2026-08-31 --agent=Admin
 *
 * @knowledge rules/payment-cost-summary
 */
class RecalcPaymentCostSummary extends Command
{
    protected $signature = 'order:recalc-payment-cost-summary
        {--from= : 起日（+8 日曆日，YYYY-MM-DD，必填）}
        {--to= : 迄日（+8 日曆日，含；預設今天）}
        {--agent=Admin : 只計此代理線以下的會員（Yes2Win 舊排程用 yes2win）}
        {--type=all : deposit＝只重算代收、withdrawal＝只重算代付、all＝兩者}
        {--dry-run : 只列出區間與將刪除的列數，不寫入}';

    protected $description = '重算 summary_event_records／summary_event_record_agents 的支付成本（event 3／8）';

    private const string TZ = '+08:00';

    public function __construct(private readonly PaymentCostSummaryService $service)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $type = (string) $this->option('type');
        $from = (string) $this->option('from');
        $to = (string) ($this->option('to') ?: Carbon::now(self::TZ)->toDateString());
        $dryRun = (bool) $this->option('dry-run');

        if ($from === '' || !in_array($type, ['deposit', 'withdrawal', 'all'], true)) {
            $this->error('--from 必填（YYYY-MM-DD），--type 必須是 deposit、withdrawal 或 all');

            return self::FAILURE;
        }

        $fromDay = Carbon::parse($from, self::TZ)->startOfDay();
        $toDay = Carbon::parse($to, self::TZ)->startOfDay();

        if ($toDay->lt($fromDay)) {
            $this->error('--to 不可早於 --from');

            return self::FAILURE;
        }

        $agentPath = $this->service->agentPathPrefix((string) $this->option('agent'));

        if ($agentPath === null) {
            $this->error(sprintf('找不到代理 %s', (string) $this->option('agent')));

            return self::FAILURE;
        }

        // 小時桶以 UTC 整點為界，迄點不含：迄日隔天 00:00(+8) 與現在整點取較早者
        $startUtc = $fromDay->copy()->utc()->toDateTimeString();
        $endUtc = min($toDay->copy()->addDay()->utc(), Carbon::now('UTC')->startOfHour())->toDateTimeString();

        $this->info(sprintf(
            '連線：%s／區間（+8）：%s〜%s ＝ UTC [%s, %s)／代理線：%s／模式：%s',
            DB::getDefaultConnection(),
            $fromDay->toDateString(),
            $toDay->toDateString(),
            $startUtc,
            $endUtc,
            $agentPath,
            $dryRun ? '乾跑' : '執行',
        ));

        $events = match ($type) {
            'deposit' => [PaymentCostSummaryService::DEPOSIT_EVENT],
            'withdrawal' => [PaymentCostSummaryService::WITHDRAWAL_EVENT],
            default => array_keys(PaymentCostSummaryService::EVENT_LABELS),
        };

        foreach ($events as $eventId) {
            $label = PaymentCostSummaryService::EVENT_LABELS[$eventId];
            $old = $this->service->countMembers($eventId, $startUtc, $endUtc, $agentPath);

            if ($dryRun) {
                $this->info(sprintf('%s（event %d）：將刪除 %d 筆會員彙總後重建', $label, $eventId, $old));

                continue;
            }

            $startedAt = microtime(true);
            $written = $this->service->rebuild($eventId, $startUtc, $endUtc, $agentPath);
            $this->info(sprintf('%s（event %d）：刪 %d、寫會員彙總 %d 筆、代理彙總 %d 筆（%.1f 秒）', $label, $eventId, $old, $written['members'], $written['agents'], microtime(true) - $startedAt));
        }

        return self::SUCCESS;
    }
}
