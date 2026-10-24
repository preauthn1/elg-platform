<?php

declare(strict_types=1);

namespace App\Console\Commands\CashFlow\CCPayment;

use App\Enums\CashFlowEnum;
use App\Enums\DepositManualReviewReasonEnum;
use App\Enums\DepositManualReviewResultEnum;
use App\Models\AgentCashFlow;
use App\Models\DepositManualReview;
use App\Services\Payment\Providers\CCPayment\RiskyRefundSync;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * CCPayment 風險交易退款同步：依金流商的退款紀錄更新風險交易充值訂單，判定規則見 RiskyRefundSync。
 *
 * 只處理有待處理風險交易列的金流商戶，沒有就不呼叫金流商。商戶不看啟用狀態：
 * 停用商戶底下的待處理單一樣要等退款結果。單一商戶失敗只記錄，不擋其他商戶。
 *
 * @knowledge rules/provider-ccpayment
 */
class SyncRiskyRefundsCommand extends Command
{
    protected $signature = 'ccpayment:sync-risky-refunds
        {--dry-run : 只列出判定結果，不寫入也不告警}';

    protected $description = 'CCPayment 風險交易退款同步：查退款紀錄，退回付款人的單改狀態 2、退到營運方白名單的單改為可手動上分';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $exitCode = self::SUCCESS;

        // 共用表的原因值是通用名稱，限定 CCPayment，別家寫入同一原因時不會被這支命令拿去打 CCPayment
        $agentCashFlowIds = DepositManualReview::query()
            ->where('cash_flow_id', CashFlowEnum::DEPOSIT_CCPAYMENT)
            ->where('reason', DepositManualReviewReasonEnum::RISKY_TRANSACTION->value)
            ->where('result', DepositManualReviewResultEnum::PENDING->value)
            ->distinct()
            ->pluck('agent_cash_flow_id');

        if ($agentCashFlowIds->isEmpty()) {
            $this->line('沒有待處理的風險交易充值訂單');

            return self::SUCCESS;
        }

        foreach (AgentCashFlow::query()->whereIn('id', $agentCashFlowIds)->get() as $agentCashFlow) {
            try {
                $summary = (new RiskyRefundSync($agentCashFlow))->sync($dryRun);
            } catch (Throwable $e) {
                $exitCode = self::FAILURE;
                $this->reportFailure($agentCashFlow, $e->getMessage());

                continue;
            }

            $this->line(sprintf('金流商戶 %d：', $agentCashFlow->id));
            foreach ($summary['lines'] as $line) {
                $this->line('  ' . $line);
            }

            if ($summary['failed'] !== []) {
                $exitCode = self::FAILURE;
                $this->reportFailure($agentCashFlow, '充值訂單 ' . implode(', ', $summary['failed']) . ' 處理失敗');
            }
        }

        return $exitCode;
    }

    private function reportFailure(AgentCashFlow $agentCashFlow, string $error): void
    {
        Log::error('CCPayment sync risky refunds failed', [
            'agent_cash_flow_id' => $agentCashFlow->id,
            'error' => $error,
        ]);
        $this->error(sprintf('金流商戶 %d 處理失敗：%s', $agentCashFlow->id, $error));
    }
}
