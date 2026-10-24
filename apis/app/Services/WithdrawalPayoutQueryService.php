<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\Payment\PayoutQueryOutcome;
use App\Enums\WithdrawalOrderStatusEnum;
use App\Facades\BotNotify;
use App\Models\AgentCashFlow;
use App\Models\WithdrawalOrder;
use App\Models\WithdrawalPayoutRecord;
use App\Services\Payment\Contracts\SupportsPayoutQuery;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 停留 21 訂單的三方查單自動查證（target/tickets#4137 擴充點）。
 *
 * timeout／pod 中斷停留在代付請求中(21)的訂單，若其 provider 實作了
 * SupportsPayoutQuery，於人工介入前先以三方查單 API 查證：
 *  - 有單（FOUND_ACCEPTED）→ 補回三方單號（訂單＋最新 record 雙欄）並轉 22
 *  - 查無／未知／不支援／無 record → 不改單，回 false 交呼叫端照舊告警
 *
 * 併發防護：定案走短事務、鎖序 record→order（與回調一致），寫入前複核
 * 「record 仍為最新一筆」與「訂單仍為 21」，查證期間被回調／人工／重新發起
 * 搶先定案時一律讓路。
 */
class WithdrawalPayoutQueryService
{
    public function __construct(
        protected PaymentService $paymentService,
    ) {}

    /**
     * 嘗試自動查證停留 21 的訂單。回傳 true＝已自動定案（轉 22）。
     */
    public function tryResolveSubmitted(WithdrawalOrder $order): bool
    {
        $record = WithdrawalPayoutRecord::query()
            ->where('withdrawal_order_id', $order->id)
            ->orderByDesc('id')
            ->first();

        // 無 record＝HTTP 未送出，三方必然查無此單，無從查證（人工走 mark-failed）
        if (!$record) {
            return false;
        }

        $agentCashFlow = AgentCashFlow::query()
            ->with(['cashFlow', 'currency'])
            ->find($record->agent_cash_flow_id);

        if (!$agentCashFlow) {
            return false;
        }

        try {
            $provider = $this->paymentService->getProvider($agentCashFlow);

            if (!$provider instanceof SupportsPayoutQuery) {
                return false;
            }

            $result = $provider->queryPayout($record);
        } catch (\Throwable $e) {
            Log::warning('Withdrawal payout auto query failed', [
                'order_id' => $order->id,
                'record_id' => $record->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }

        if ($result->outcome !== PayoutQueryOutcome::FOUND_ACCEPTED) {
            Log::info('Withdrawal payout auto query not conclusive, leave to manual resolution', [
                'order_id' => $order->id,
                'record_id' => $record->id,
                'outcome' => $result->outcome->name,
            ]);

            return false;
        }

        // 查到有單但未附三方單號＝資訊不足，不自動轉 22：掃描器只掃 21，
        // 缺單號的 22 會脫離掃描且三家雙條件對單商對不上回調，寧可維持 21 繼續告警交人工
        if ($result->thirdPartyOrderNo === '') {
            Log::info('Withdrawal payout auto query found order but no third party order no, leave to manual resolution', [
                'order_id' => $order->id,
                'record_id' => $record->id,
            ]);

            return false;
        }

        return $this->settleFound($order->id, $record->id, $result->thirdPartyOrderNo);
    }

    /**
     * 查到有單：短事務內補單號轉 22（鎖序 record→order，讓路規則同人工／回調路徑）。
     */
    private function settleFound(int $orderId, int $recordId, string $thirdPartyOrderNo): bool
    {
        $resolved = DB::transaction(function () use ($orderId, $recordId, $thirdPartyOrderNo): bool {
            $record = WithdrawalPayoutRecord::query()->whereKey($recordId)->lockForUpdate()->first();

            if (!$record) {
                return false;
            }

            // record 必須仍是該單最新一筆：查證期間若已重新發起（新 record），本結果屬舊 attempt，讓路
            $hasNewerRecord = WithdrawalPayoutRecord::query()
                ->where('withdrawal_order_id', $orderId)
                ->where('id', '>', $recordId)
                ->exists();

            if ($hasNewerRecord) {
                return false;
            }

            $order = WithdrawalOrder::query()->whereKey($orderId)->lockForUpdate()->first();

            // 訂單已離開 21（回調／人工搶先定案）＝讓路
            if (!$order || (int) $order->status !== WithdrawalOrderStatusEnum::PAYMENT_SUBMITTED->value) {
                return false;
            }

            // 時序守衛（防 ABA：狀態 21→29→重新發起又回到 21，值相同、實為兩次發起）：
            // 訂單 updated_at 晚於本 record 的 created_at＝建檔後這個 21 又被重寫
            // （被回調定為 29 後重新發起、且新發起尚未建檔），本查單結果屬前一次發起，讓路
            if ($order->updated_at !== null
                && $record->created_at !== null
                && $order->updated_at->gt($record->created_at)) {
                Log::warning('Withdrawal payout auto resolve skipped, order rewritten after record creation', [
                    'order_id' => $orderId,
                    'record_id' => $recordId,
                    'order_updated_at' => (string) $order->updated_at,
                    'record_created_at' => (string) $record->created_at,
                ]);

                return false;
            }

            // 雙欄回填（同人工補單號語意；空單號已於呼叫端拒絕）
            $record->update(['third_party_transaction_no' => $thirdPartyOrderNo]);
            $order->update([
                'status' => WithdrawalOrderStatusEnum::PAYMENT_PROCESSING->value,
                'third_party_order_no' => $thirdPartyOrderNo,
                // 清掉發起側暫存的 attempt token（查單定案情境無同步回應可放）
                'third_party_response' => null,
            ]);

            Log::warning('Withdrawal payout auto resolved via provider query', [
                'order_id' => $orderId,
                'record_id' => $recordId,
                'third_party_order_no' => $thirdPartyOrderNo,
            ]);

            return true;
        });

        if ($resolved) {
            // 事務已提交，通知失敗只記 log（訂單已回到等回調軌道）
            try {
                BotNotify::send(sprintf(
                    '停留代付請求中(21)的訂單 %d 經三方查單 API 自動查證有單，已補回三方單號（%s）轉代付處理中(22)等回調',
                    $orderId,
                    $thirdPartyOrderNo !== '' ? $thirdPartyOrderNo : '查單未提供',
                ));
            } catch (\Throwable $notifyError) {
                Log::error('Withdrawal payout auto resolve notification failed after commit', [
                    'order_id' => $orderId,
                    'error' => $notifyError->getMessage(),
                ]);
            }
        }

        return $resolved;
    }
}
