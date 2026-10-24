<?php

declare(strict_types=1);

namespace App\Console\Commands\CashFlow\CCPayment;

use App\Enums\CashFlowEnum;
use App\Enums\WithdrawalOrderStatusEnum;
use App\Facades\BotNotify;
use App\Models\AgentCashFlow;
use App\Models\Order;
use App\Models\WithdrawalOrder;
use App\Services\Payment\Providers\CCPayment\Reconciliation;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * CCPayment 回調補送：存款漏單與代付卡 22 的單，請金流商重送 webhook。
 *
 * 為什麼是「重送 webhook」而不是自己補單：入帳與定案只留回調一條寫入路徑，判定矩陣不用寫兩次。
 * 三種漏單情形都靠本命令補：回調漏收、解綁後 notifyUrl 是否清除未知、風險地址的存款不發回調。
 *
 * 存款側：拉最近 N 小時本支付通道代幣的存款紀錄，status 為 Success 且 orders.third_party_order_no
 * 找不到的就是缺漏；第一次看到就請金流商重送（依 arrivedAt 分小時窗，重送 API 時段上限 1 小時），
 * 重送過仍缺才告警。Processing 的紀錄金流商之後會自己發回調，不算缺漏。
 *
 * 代付側：停在代付處理中(22)超過 2 小時且有三方單號的提款單，請金流商重送 ApiWithdrawal 回調，
 * 由回調的三態判定定案；重送過仍停在 22 才告警。本命令不改單。
 *
 * 去重靠 Cache：重送與告警各記 24 小時，避免每 30 分鐘一輪把同一筆重送或告警到爆。
 * 金流商的限流門檻未知，排程頻率先保守（每 30 分鐘）。
 *
 * @knowledge rules/provider-ccpayment
 */
class ResendMissingWebhooksCommand extends Command
{
    /** 同一筆紀錄的重送去重時窗（秒） */
    private const RESEND_TTL = 86400;

    /** 同一批仍缺紀錄的告警去重時窗（秒） */
    private const ALERT_TTL = 86400;

    /** 重送 API 的時段上限（秒） */
    private const WINDOW_SECONDS = 3600;

    /** 代付停在 22 多久算卡單（金流商建議 2 小時無 Success 回調就主動查） */
    private const STUCK_HOURS = 2;

    private const STATUS_SUCCESS = 'Success';

    protected $signature = 'ccpayment:resend-missing-webhooks
        {--hours=2 : 存款側回看的小時數，拉這段時間的存款紀錄比對}
        {--dry-run : 只列出缺漏，不呼叫重送也不告警}';

    protected $description = 'CCPayment 回調補送：存款漏單與代付卡 22 超過 2 小時的單，請金流商重送 webhook，仍缺才告警';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $hours = max(1, (int) $this->option('hours'));
        $exitCode = self::SUCCESS;

        foreach ($this->activeCashFlows(CashFlowEnum::DEPOSIT_CCPAYMENT) as $agentCashFlow) {
            try {
                $this->reconcileDeposits($agentCashFlow, $hours, $dryRun);
            } catch (Throwable $e) {
                $exitCode = self::FAILURE;
                $this->reportFailure($agentCashFlow, 'deposit', $e);
            }
        }

        foreach ($this->activeCashFlows(CashFlowEnum::WITHDRAW_CCPAYMENT) as $agentCashFlow) {
            try {
                $this->resendStuckWithdrawals($agentCashFlow, $dryRun);
            } catch (Throwable $e) {
                $exitCode = self::FAILURE;
                $this->reportFailure($agentCashFlow, 'withdrawal', $e);
            }
        }

        return $exitCode;
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, AgentCashFlow>
     */
    private function activeCashFlows(int $cashFlowId)
    {
        return AgentCashFlow::query()
            ->where('cash_flow_id', $cashFlowId)
            ->where('status', 1)
            ->get();
    }

    private function reconcileDeposits(AgentCashFlow $agentCashFlow, int $hours, bool $dryRun): void
    {
        $reconciliation = new Reconciliation($agentCashFlow);
        $endAt = now()->timestamp;
        $startAt = $endAt - $hours * 3600;

        $arrivedAt = [];
        foreach ($reconciliation->listDepositRecordsBetween($startAt, $endAt) as $record) {
            $recordId = $record['recordId'] ?? null;
            if (($record['status'] ?? '') !== self::STATUS_SUCCESS || !is_scalar($recordId) || (string) $recordId === '') {
                continue;
            }

            $arrivedAt[(string) $recordId] = is_numeric($record['arrivedAt'] ?? null) ? (int) $record['arrivedAt'] : $endAt;
        }

        if ($arrivedAt === []) {
            $this->line(sprintf('金流商戶 %d：這段時間沒有 Success 存款紀錄', $agentCashFlow->id));

            return;
        }

        $known = Order::query()
            ->where('agent_cash_flow_id', $agentCashFlow->id)
            ->whereIn('third_party_order_no', array_keys($arrivedAt))
            ->pluck('third_party_order_no')
            ->all();

        $missing = array_values(array_diff(array_keys($arrivedAt), $known));

        if ($missing === []) {
            $this->line(sprintf('金流商戶 %d：%d 筆 Success 存款紀錄都有存款單', $agentCashFlow->id, count($arrivedAt)));

            return;
        }

        $this->line(sprintf('金流商戶 %d：%d 筆 Success 存款紀錄沒有存款單：%s', $agentCashFlow->id, count($missing), implode(', ', $missing)));

        if ($dryRun) {
            return;
        }

        [$toResend, $stillMissing] = $this->splitByResendMark($agentCashFlow, 'deposit', $missing);

        // 依到帳時間分小時窗：重送 API 的 startTimestamp 到 endTimestamp 最多相隔 1 小時
        $windows = [];
        foreach ($toResend as $recordId) {
            $windowStart = intdiv($arrivedAt[$recordId], self::WINDOW_SECONDS) * self::WINDOW_SECONDS;
            $windows[$windowStart][] = $recordId;
        }

        foreach ($windows as $windowStart => $recordIds) {
            $count = $reconciliation->resendWebhooksFor(
                Reconciliation::TYPE_DIRECT_DEPOSIT,
                $recordIds,
                $windowStart,
                $windowStart + self::WINDOW_SECONDS
            );

            Log::info('CCPayment deposit webhooks resend requested', [
                'agent_cash_flow_id' => $agentCashFlow->id,
                'record_ids' => $recordIds,
                'resend_count' => $count,
            ]);
            $this->line(sprintf('  已請金流商重送 %d 筆存款回調（金流商回報 %d 筆）', count($recordIds), $count));
        }

        if ($stillMissing !== []) {
            $this->alertOnce($agentCashFlow, 'deposit', $stillMissing, sprintf(
                'CCPayment 金流商戶 %d 有 %d 筆存款重送回調後仍沒有存款單，請人工查核：%s',
                $agentCashFlow->id,
                count($stillMissing),
                implode(', ', $stillMissing)
            ));
        }
    }

    private function resendStuckWithdrawals(AgentCashFlow $agentCashFlow, bool $dryRun): void
    {
        $reconciliation = new Reconciliation($agentCashFlow);
        $threshold = now()->subHours(self::STUCK_HOURS);

        $orders = WithdrawalOrder::query()
            ->where('status', WithdrawalOrderStatusEnum::PAYMENT_PROCESSING->value)
            ->where('updated_at', '<=', $threshold)
            ->where('third_party_order_no', '!=', '')
            ->whereHas('payoutRecords', fn ($query) => $query->where('agent_cash_flow_id', $agentCashFlow->id))
            ->get(['id', 'third_party_order_no', 'updated_at']);

        if ($orders->isEmpty()) {
            $this->line(sprintf('金流商戶 %d：沒有停在代付處理中超過 %d 小時的提款單', $agentCashFlow->id, self::STUCK_HOURS));

            return;
        }

        $this->line(sprintf(
            '金流商戶 %d：%d 筆提款單停在代付處理中超過 %d 小時：%s',
            $agentCashFlow->id,
            $orders->count(),
            self::STUCK_HOURS,
            $orders->pluck('id')->implode(', ')
        ));

        if ($dryRun) {
            return;
        }

        $recordIds = $orders->pluck('third_party_order_no')->map(fn ($value): string => (string) $value)->all();
        [$toResend, $stillStuck] = $this->splitByResendMark($agentCashFlow, 'withdrawal', $recordIds);

        foreach ($orders as $order) {
            $recordId = (string) $order->third_party_order_no;
            if (!in_array($recordId, $toResend, true)) {
                continue;
            }

            // 金流商建單時間約等於我方送出代付的時間，以提款單最後更新時間前後各半小時當時段
            $center = $order->updated_at?->timestamp ?? now()->timestamp;
            $count = $reconciliation->resendWebhooksFor(
                Reconciliation::TYPE_API_WITHDRAWAL,
                [$recordId],
                $center - intdiv(self::WINDOW_SECONDS, 2),
                $center + intdiv(self::WINDOW_SECONDS, 2)
            );

            Log::info('CCPayment withdrawal webhook resend requested', [
                'agent_cash_flow_id' => $agentCashFlow->id,
                'withdrawal_order_id' => $order->id,
                'record_id' => $recordId,
                'resend_count' => $count,
            ]);
            $this->line(sprintf('  提款單 %d：已請金流商重送代付回調（金流商回報 %d 筆）', $order->id, $count));
        }

        if ($stillStuck !== []) {
            $stuckOrderIds = $orders->whereIn('third_party_order_no', $stillStuck)->pluck('id')->implode(', ');
            $this->alertOnce($agentCashFlow, 'withdrawal', $stillStuck, sprintf(
                'CCPayment 金流商戶 %d 有 %d 筆提款單重送回調後仍停在代付處理中，請人工查核：提款單 %s',
                $agentCashFlow->id,
                count($stillStuck),
                $stuckOrderIds
            ));
        }
    }

    /**
     * 以 Cache::add 原子預占把紀錄分成「這輪要重送」與「已重送過仍缺」。
     *
     * @param  list<string>  $recordIds
     * @return array{0: list<string>, 1: list<string>}
     */
    private function splitByResendMark(AgentCashFlow $agentCashFlow, string $side, array $recordIds): array
    {
        $toResend = [];
        $stillMissing = [];

        foreach ($recordIds as $recordId) {
            $key = sprintf('ccpayment:resend:%s:%d:%s', $side, $agentCashFlow->id, $recordId);
            if (Cache::add($key, 1, self::RESEND_TTL)) {
                $toResend[] = $recordId;
            } else {
                $stillMissing[] = $recordId;
            }
        }

        return [$toResend, $stillMissing];
    }

    /**
     * 同一批仍缺紀錄 24 小時只告警一次；通知送出失敗就歸還去重 key，下輪重試。
     *
     * @param  list<string>  $recordIds
     */
    private function alertOnce(AgentCashFlow $agentCashFlow, string $side, array $recordIds, string $message): void
    {
        sort($recordIds);
        $key = sprintf('ccpayment:resend-alert:%s:%d:%s', $side, $agentCashFlow->id, md5(implode(',', $recordIds)));

        if (!Cache::add($key, 1, self::ALERT_TTL)) {
            return;
        }

        try {
            BotNotify::channel('warn')->send($message);
        } catch (Throwable $e) {
            Cache::forget($key);
            throw $e;
        }

        $this->warn('  ' . $message);
    }

    private function reportFailure(AgentCashFlow $agentCashFlow, string $side, Throwable $e): void
    {
        Log::error('CCPayment resend missing webhooks failed', [
            'agent_cash_flow_id' => $agentCashFlow->id,
            'side' => $side,
            'error' => $e->getMessage(),
        ]);
        $this->error(sprintf('金流商戶 %d（%s）處理失敗：%s', $agentCashFlow->id, $side, $e->getMessage()));
    }
}
