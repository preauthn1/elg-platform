<?php

declare(strict_types=1);

namespace App\Services\Payment\Providers\CCPayment;

use App\Enums\CashFlowEnum;
use App\Enums\DepositManualReviewReasonEnum;
use App\Enums\DepositManualReviewResultEnum;
use App\Enums\OrderStatusEnum;
use App\Exceptions\ApiException;
use App\Helpers\Common;
use App\Models\AgentCashFlow;
use App\Models\DepositManualReview;
use App\Models\Order;
use App\Services\Payment\AbstractMethod;
use Exception;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 風險交易退款同步：查 getRiskyRefundRecordList（api.md §6.6），依退款結果更新風險交易充值訂單與存款人工確認紀錄。
 *
 * 為什麼要排程查：營運在金流商後台處理風險交易只能「退款」，處理後金流商不發 webhook，
 * 原存款的回查結果也不會變（integration.md「風險交易的後續處理」）。呼叫端是 ccpayment:sync-risky-refunds。
 *
 * 每張待處理單的判定（只看 Success 的退款紀錄；地址逐字比對，只去掉前後空白）：
 *
 * | 退款紀錄 | 動作 |
 * |---|---|
 * | 還沒有、或只有 Processing | 不動，下一輪再查 |
 * | 只有 Failed | 不動，告警 |
 * | 一筆 Success，退款地址＝原存款付款地址 fromAddress | 充值訂單改狀態 2，該列寫退回付款人、不可上分 |
 * | 一筆 Success，退款地址在營運方白名單 | 充值訂單維持狀態 4，該列寫退到營運方、可上分（營運接著按手動上分） |
 * | 一筆 Success，兩者都不符 | 不動，告警 |
 * | 兩筆以上 Success | 不動，告警（一筆存款不該退兩次） |
 * | （不看退款）列仍待處理，但訂單已不是狀態 4 或沒有三方單號 | 不動，告警，不計入查詢起點（有路徑繞過了鎖） |
 *
 * 前兩種情況同時寫金流商手續費＝原存款回查的 serviceFee × 訂單匯率（CCPaymentTrait::actualPaymentCost()），
 * 單上已有大於 0 的值不覆寫。風險退款費不記：退款紀錄只有整次退款的網路費，系統拿不到每筆存款的實際值。
 *
 * 一筆批量退款的 fromDeposit 會列多筆存款，每張單各自判定、各自寫自己的服務費。
 *
 * 查詢範圍：本商戶最早一筆待處理列的建立時間往前多抓 1 小時，最多回看 90 天；沒有待處理列就不呼叫金流商。
 * 付款地址與服務費以「逐筆重新回查原存款」取得，不讀 orders.remark：remark 是自由文字欄，舊代理後台手動上分會覆寫它。
 *
 * 併發與冪等：呼叫金流商都在交易外。寫入時在交易內先鎖充值訂單、再鎖該列，重查「該列仍待處理且訂單仍是狀態 4」
 * 才寫，所以重跑、或與手動操作同時發生都不會重複寫入。告警以 Cache 去重 24 小時。
 *
 * @knowledge rules/provider-ccpayment
 */
class RiskyRefundSync extends AbstractMethod
{
    use CCPaymentTrait;

    /** 營運方收款地址白名單：agent_cash_flows.info 的鍵，值為字串陣列 */
    public const MERCHANT_ADDRESSES_KEY = 'RISKY_REFUND_MERCHANT_ADDRESSES';

    private const STATUS_SUCCESS = 'Success';

    private const STATUS_FAILED = 'Failed';

    /** 查詢起點往前多抓的重疊區間（秒），涵蓋建單時間與到帳時間的落差 */
    private const OVERLAP_SECONDS = 3600;

    /** 最多回看的天數（SDK 文件寫法，未實測） */
    private const MAX_LOOKBACK_DAYS = 90;

    /** 翻頁保險絲：單次最多讀這麼多筆退款紀錄，超過代表待處理單積太久 */
    private const MAX_RECORDS = 5000;

    /** 告警去重時窗（秒） */
    private const ALERT_TTL = 86400;

    /**
     * @throws ApiException 不是 CCPayment 代收商戶：共用表的原因值是通用名稱，別家寫入同一原因時不能拿它的憑證打 CCPayment
     */
    public function __construct(
        protected AgentCashFlow $agentCashFlow
    ) {
        if ((int) $agentCashFlow->cash_flow_id !== CashFlowEnum::DEPOSIT_CCPAYMENT) {
            throw new ApiException(sprintf('agent cash flow %d is not a CCPayment deposit merchant', $agentCashFlow->id));
        }

        $this->setInfo($agentCashFlow->info);
    }

    /**
     * 同步本商戶的待處理風險交易單。
     *
     * @param  bool  $dryRun  只判定、不寫入也不告警
     * @return array{lines: list<string>, failed: list<int>} 每張待處理單的處理說明，以及處理失敗的充值訂單 id
     */
    public function sync(bool $dryRun = false): array
    {
        $pending = DepositManualReview::query()
            ->where('agent_cash_flow_id', $this->agentCashFlow->id)
            ->where('reason', DepositManualReviewReasonEnum::RISKY_TRANSACTION->value)
            ->where('result', DepositManualReviewResultEnum::PENDING->value)
            ->orderBy('created_at')
            ->get();

        $lines = [];
        $orders = Order::query()
            ->whereIn('id', $pending->pluck('order_id'))
            ->get(['id', 'status', 'third_party_order_no'])
            ->keyBy('id');

        // 列仍待處理、訂單卻已不是狀態 4（或沒有三方單號），代表有路徑繞過了鎖：告警並排除，
        // 不讓它把查詢起點一直釘在最早那天、每輪白打回查
        $workable = $pending->filter(function (DepositManualReview $review) use ($orders, $dryRun, &$lines): bool {
            $order = $orders->get($review->order_id);
            if ($order !== null && (int) $order->status === OrderStatusEnum::WAITING_MANUAL_DEPOSIT->value
                && (string) $order->third_party_order_no !== '') {
                return true;
            }

            $this->alertOnce($dryRun, 'stuck-review', (string) $review->order_id, '存款人工確認紀錄仍待處理，但充值訂單已不是待手動確認上分或沒有三方單號，排程不處理，請人工查核', [
                'order_id' => $review->order_id,
                'order_status' => $order?->status,
                'record_id' => $order?->third_party_order_no,
            ]);
            $lines[] = sprintf('充值訂單 %d：紀錄待處理但訂單狀態不符，未處理、已告警', $review->order_id);

            return false;
        });

        if ($workable->isEmpty()) {
            return ['lines' => $lines, 'failed' => []];
        }

        $endAt = now()->timestamp;
        $startAt = max(
            $workable->first()->created_at->timestamp - self::OVERLAP_SECONDS,
            $endAt - self::MAX_LOOKBACK_DAYS * 86400
        );
        $refundsByDeposit = $this->refundsByDeposit($startAt, $endAt);

        // 單張失敗不擋其他單：記進 failed，由命令把這個商戶記為失敗
        $failures = [];
        foreach ($workable as $review) {
            $recordId = (string) $orders->get($review->order_id)->third_party_order_no;

            try {
                $outcome = $this->syncOne($review, $recordId, $refundsByDeposit[$recordId] ?? [], $dryRun);
            } catch (Exception $e) {
                $failures[] = $review->order_id;
                $outcome = '處理失敗：' . $e->getMessage();
                Log::error($this->getDriverName() . ' risky refund sync failed', [
                    'order_id' => $review->order_id,
                    'error' => $e->getMessage(),
                ]);
            }

            $lines[] = sprintf('充值訂單 %d：%s', $review->order_id, $outcome);
        }

        return ['lines' => $lines, 'failed' => $failures];
    }

    /**
     * @param  list<array<string, mixed>>  $refunds  對到這筆存款的退款紀錄
     */
    private function syncOne(DepositManualReview $review, string $recordId, array $refunds, bool $dryRun): string
    {
        $succeeded = array_values(array_filter($refunds, fn (array $refund): bool => ($refund['status'] ?? '') === self::STATUS_SUCCESS));

        if (count($succeeded) > 1) {
            $this->alertOnce($dryRun, 'multiple-refunds', $recordId, '一筆風險存款對到兩筆以上成功的退款紀錄，未更新訂單，請人工查核', [
                'order_id' => $review->order_id,
                'record_id' => $recordId,
                'refund_record_ids' => array_column($succeeded, 'recordId'),
            ]);

            return '對到多筆成功退款，未更新、已告警';
        }

        if ($succeeded === []) {
            $failed = array_filter($refunds, fn (array $refund): bool => ($refund['status'] ?? '') === self::STATUS_FAILED);
            if ($failed !== [] && count($failed) === count($refunds)) {
                $this->alertOnce($dryRun, 'refund-failed', $recordId, '風險存款的退款失敗，訂單仍不可上分，請到金流商後台確認', [
                    'order_id' => $review->order_id,
                    'record_id' => $recordId,
                    'refund_record_ids' => array_column($failed, 'recordId'),
                ]);

                return '退款失敗，未更新、已告警';
            }

            return $refunds === [] ? '還沒有退款紀錄' : '退款處理中';
        }

        $refund = $succeeded[0];
        $refundTo = $this->addressOf($refund['toAddress'] ?? null);

        // 付款地址與服務費以重新回查原存款為準（金流商確認處理後回查結果不變）
        $deposit = $this->queryDepositRecord($recordId)['data']['record'] ?? null;
        if (!is_array($deposit)) {
            throw new ApiException($this->getDriverName() . ' deposit record query returned unexpected shape');
        }
        $payer = $this->addressOf($deposit['fromAddress'] ?? null);

        $result = match (true) {
            $payer !== '' && $refundTo === $payer => DepositManualReviewResultEnum::REFUNDED_TO_PAYER,
            $refundTo !== '' && in_array($refundTo, $this->merchantAddresses(), true) => DepositManualReviewResultEnum::REFUNDED_TO_MERCHANT,
            default => null,
        };

        if ($result === null) {
            $this->alertOnce($dryRun, 'unknown-address', $recordId, '風險存款退到既不是付款人、也不在營運方白名單的地址，訂單仍不可上分，請人工查核', [
                'order_id' => $review->order_id,
                'record_id' => $recordId,
                'refund_record_id' => $refund['recordId'] ?? null,
            ]);

            return '退款地址不符，未更新、已告警';
        }

        if ($dryRun) {
            return '將寫入 ' . $result->value;
        }

        $proof = [
            'refund_record_id' => is_scalar($refund['recordId'] ?? null) ? (string) $refund['recordId'] : '',
            'refund_status' => self::STATUS_SUCCESS,
            'refund_to_address' => $refundTo,
            'payer_address' => $payer,
        ];

        return $this->resolve($review->order_id, $result, $proof, $deposit['serviceFee'] ?? null);
    }

    /**
     * 交易內鎖單、重查後寫入；呼叫金流商已在交易外做完。
     *
     * @param  array<string, string>  $proof
     */
    private function resolve(int $orderId, DepositManualReviewResultEnum $result, array $proof, mixed $serviceFee): string
    {
        $missingFee = false;

        try {
            DB::beginTransaction();

            $order = Order::query()->lockForUpdate()->find($orderId);
            $review = DepositManualReview::query()->where('order_id', $orderId)->lockForUpdate()->first();

            if ($order === null || $review === null || !$review->isPending()
                || (int) $order->status !== OrderStatusEnum::WAITING_MANUAL_DEPOSIT->value) {
                DB::rollBack();

                return '已不是待處理或狀態 4，略過';
            }

            $updates = [];
            if ($result === DepositManualReviewResultEnum::REFUNDED_TO_PAYER) {
                $updates['status'] = OrderStatusEnum::FAILED->value;
            }

            if (Common::numCompare(Common::numAdd('0', (string) ($order->payment_cost ?? 0), 4), '0', 4) <= 0) {
                $paymentCost = $this->actualPaymentCost($serviceFee, (string) ($order->rate ?? 1));
                if ($paymentCost === null) {
                    $missingFee = true;
                } else {
                    $updates['payment_cost'] = $paymentCost;
                }
            }

            if ($updates !== []) {
                $order->update($updates);
            }
            $review->resolve($result, $proof);

            DB::commit();
        } catch (Exception $e) {
            DB::rollBack();

            throw $e;
        }

        if ($missingFee) {
            $this->alertAndLog('風險存款已依退款結果更新，但原存款回查的服務費缺漏或不是數字，金流商手續費未寫入', [
                'order_id' => $orderId,
                'result' => $result->value,
                'service_fee' => $serviceFee,
            ]);
        }

        Log::info($this->getDriverName() . ' risky deposit resolved by refund record', [
            'order_id' => $orderId,
            'result' => $result->value,
            'refund_record_id' => $proof['refund_record_id'],
        ]);

        return '已寫入 ' . $result->value;
    }

    /**
     * 拉時間範圍內的退款紀錄，依 fromDeposit 的存款 recordId 分組；一筆批量退款會出現在多筆存款底下。
     *
     * @return array<string, list<array<string, mixed>>>
     */
    private function refundsByDeposit(int $startAt, int $endAt): array
    {
        $grouped = [];
        $count = 0;
        $nextId = '';

        do {
            $params = ['startAt' => $startAt, 'endAt' => $endAt];
            if ($nextId !== '') {
                $params['nextId'] = $nextId;
            }

            $data = $this->extractData($this->sendRequest('/getRiskyRefundRecordList', $params), '/getRiskyRefundRecordList');

            $page = $data['records'] ?? [];
            if (!is_array($page)) {
                throw new ApiException($this->getDriverName() . ' risky refund record list returned unexpected records shape');
            }

            foreach ($page as $refund) {
                if (!is_array($refund)) {
                    continue;
                }
                $count++;

                foreach (is_array($refund['fromDeposit'] ?? null) ? $refund['fromDeposit'] : [] as $deposit) {
                    $depositRecordId = is_array($deposit) && is_scalar($deposit['recordId'] ?? null) ? (string) $deposit['recordId'] : '';
                    if ($depositRecordId !== '') {
                        $grouped[$depositRecordId][] = $refund;
                    }
                }
            }

            if ($count > self::MAX_RECORDS) {
                throw new ApiException(sprintf('%s risky refund records exceed %d, narrow the time range', $this->getDriverName(), self::MAX_RECORDS));
            }

            $nextId = is_scalar($data['nextId'] ?? null) ? (string) $data['nextId'] : '';
        } while ($nextId !== '');

        return $grouped;
    }

    /**
     * 營運方收款地址白名單；沒設定或格式不對時當成空清單（退到任何非付款人地址都告警）。
     *
     * @return list<string>
     */
    private function merchantAddresses(): array
    {
        $addresses = $this->info[self::MERCHANT_ADDRESSES_KEY] ?? [];
        if (!is_array($addresses)) {
            return [];
        }

        return array_values(array_filter(array_map(fn (mixed $address): string => $this->addressOf($address), $addresses), fn (string $address): bool => $address !== ''));
    }

    private function addressOf(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }

    /**
     * 同一筆存款同一種狀況 24 小時只告警一次；預演不告警。
     *
     * @param  array<string, mixed>  $context
     */
    private function alertOnce(bool $dryRun, string $kind, string $recordId, string $message, array $context): void
    {
        if ($dryRun) {
            return;
        }

        $key = sprintf('ccpayment:risky-refund-alert:%s:%d:%s', $kind, $this->agentCashFlow->id, $recordId);
        if (!Cache::add($key, 1, self::ALERT_TTL)) {
            return;
        }

        try {
            $this->alertAndLog($message, $context);
        } catch (Exception $e) {
            Cache::forget($key);

            throw $e;
        }
    }
}
