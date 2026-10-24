<?php

declare(strict_types=1);

namespace App\Console\Commands\Ggr;

use App\Console\Commands\Ggr\Concerns\ResolvesGgrPlanOption;
use App\Jobs\GgrPayoutJob;
use App\Services\Agent\GgrSettlement\PayoutService;
use Illuminate\Console\Command;

/**
 * GGR 發放觸發器（target/tickets#4121 批次七，決議⑧）。
 *
 * 排程每日 GMT+8 16:00 觸發（結算 15:00 後留緩衝）：dispatch GgrPayoutJob 把
 * ggr_settlements 的 UNPAID 列入帳到代理餘額。結算尚未完成的期本輪拉不到，
 * 由隔日發放自動補上（catch-up）；冪等由 PayoutService 的狀態守門保證。
 *
 * --direct 同步執行不入 queue（後台 Olympus 手動觸發用）：任一列發放失敗
 * 即以非 0 作收，admin_operation_logs 才不會把失敗標成成功。
 * --dry-run 唯讀列出將發放清單，不入帳、不改狀態。
 */
class GgrPayoutCommand extends Command
{
    use ResolvesGgrPlanOption;

    protected $signature = 'ggr:payout
        {--plan= : 只發指定方案 id 的結算列}
        {--direct : 同步執行不入 queue（後台補跑用，失敗回非 0）}
        {--dry-run : 唯讀列出將發放清單，不落庫}';

    protected $description = 'GGR 推廣代理發放：把 ggr_settlements 未發放列入帳到代理餘額（4121）';

    public function handle(PayoutService $service): int
    {
        $planId = $this->resolveGgrPlanOption();

        if ($planId === false) {
            return self::FAILURE;
        }

        if ($this->option('dry-run')) {
            $rows = $service->pendingRows($planId);
            $this->line(sprintf('[dry-run] 待發放 %d 列', count($rows)));

            foreach ($rows as $row) {
                $this->line(sprintf(
                    '  - settlement %d：%s 實發 %s（%s）',
                    $row['id'],
                    $row['agent_username'],
                    $row['amount'],
                    PayoutService::modeLabel($row['mode']),
                ));
            }

            return self::SUCCESS;
        }

        if (!$this->option('direct')) {
            GgrPayoutJob::dispatch($planId);
            $this->line('已派發 GgrPayoutJob');

            return self::SUCCESS;
        }

        $stats = $service->payoutPending($planId);

        $summary = [];
        foreach ($stats['counts'] as $mode => $count) {
            $summary[] = sprintf('%s %d', PayoutService::modeLabel($mode), $count);
        }
        $summary[] = sprintf('失敗 %d', $stats['failed']);

        $this->line('發放完成：' . implode('、', $summary));

        foreach ($stats['failures'] as $failure) {
            $this->error('  ' . $failure);
        }

        return $stats['failed'] === 0 ? self::SUCCESS : self::FAILURE;
    }
}
