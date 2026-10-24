<?php

declare(strict_types=1);

namespace App\Console\Commands\Order;

use App\Services\Summary\PaymentCostSummaryService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * 每小時排程：重建最近 N 個整點小時的支付成本彙總（event 3 代收／8 代付）target/tickets#4369。
 *
 * 取代 MySQL EVENT Event_Summary_event_reocrd_by_payment（六站每小時）與 Event_Summary_event_reocrd_by_payment_W
 * （Yes2Win 每日）。窗口與原代收 SP 一致：[現在整點 − N 小時, 現在整點)，回看 3 小時包容晚到的回調；
 * 範圍全站（Admin 代理線 /1）。部署後必須停用各站上述 EVENT，否則兩個寫入者對同一批小時桶刪除重建、
 * 且原代付 SP 不先刪舊列會撞主鍵報錯。
 *
 * php artisan summary:payment-cost            # 前 3 個整點小時
 * php artisan summary:payment-cost --hours=6
 *
 * @knowledge rules/payment-cost-summary
 */
class SummarizePaymentCost extends Command
{
    protected $signature = 'summary:payment-cost
        {--hours=3 : 回看的整點小時數（含剛結束的那一小時）}
        {--agent=Admin : 只計此代理線以下的會員（預設全站）}';

    protected $description = '每小時重建最近 N 小時的支付成本彙總（summary_event_records event 3／8）';

    public function __construct(private readonly PaymentCostSummaryService $service)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $hours = max(1, (int) $this->option('hours'));
        $agentPath = $this->service->agentPathPrefix((string) $this->option('agent'));

        if ($agentPath === null) {
            $this->error(sprintf('找不到代理 %s', (string) $this->option('agent')));

            return self::FAILURE;
        }

        $endUtc = Carbon::now('UTC')->startOfHour();
        $startUtc = $endUtc->copy()->subHours($hours);

        $this->info(sprintf('連線：%s／窗口 UTC [%s, %s)／代理線：%s', DB::getDefaultConnection(), $startUtc->toDateTimeString(), $endUtc->toDateTimeString(), $agentPath));

        foreach (PaymentCostSummaryService::EVENT_LABELS as $eventId => $label) {
            $startedAt = microtime(true);
            $written = $this->service->rebuild($eventId, $startUtc->toDateTimeString(), $endUtc->toDateTimeString(), $agentPath);
            $this->info(sprintf('%s（event %d）：會員彙總 %d 筆、代理彙總 %d 筆（%.1f 秒）', $label, $eventId, $written['members'], $written['agents'], microtime(true) - $startedAt));
        }

        return self::SUCCESS;
    }
}
