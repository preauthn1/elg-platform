<?php

declare(strict_types=1);

namespace App\Services\Payment\Providers\CCPayment;

use App\Exceptions\ApiException;
use App\Helpers\Common;
use App\Models\AgentCashFlow;
use App\Models\AgentCashFlowOrderType;
use App\Services\Payment\AbstractMethod;
use Illuminate\Support\Facades\Log;

/**
 * 對帳補送（api.md §4.3 存款清單、§5.6 主動重送 webhook）：唯讀加「請金流商重送」，不建單不改單。
 *
 * 入帳與定案只走回調這一條寫入路徑；本類別只找出缺漏、請金流商把 webhook 再送一次，
 * 由 DepositCallback／WithdrawalCallback 照常處理。呼叫端是 ccpayment:resend-missing-webhooks。
 *
 * 存款清單依商戶底下每條支付通道的（幣, 鏈）各拉一輪（CCPaymentTrait::channelSpec()）。單一通道壞掉
 * （key 格式不對、後台沒啟用該幣或鏈、三方拒絕該 coinId）只跳過並記錄，不讓一條壞通道停掉整個商戶的漏單偵測；
 * 有通道但每一組都拉不到才拋出，讓排程把這個商戶記為失敗。
 */
class Reconciliation extends AbstractMethod
{
    use CCPaymentTrait;

    public const TYPE_DIRECT_DEPOSIT = 'DirectDeposit';

    public const TYPE_API_WITHDRAWAL = 'ApiWithdrawal';

    /** 重送 API 單批 recordIds 上限（api.md §5.6） */
    private const RESEND_BATCH = 50;

    /** 翻頁保險絲：單次對帳跨所有通道累計最多讀這麼多筆，超過就是時間範圍開太大 */
    private const MAX_RECORDS = 5000;

    public function __construct(
        protected AgentCashFlow $agentCashFlow
    ) {
        $this->setInfo($agentCashFlow->info);
    }

    /**
     * 拉本商戶各支付通道的（幣, 鏈）在時間範圍內的存款紀錄：每組各查一輪，依 nextId 逐頁取完（每頁 20 筆）。
     *
     * 支付通道不濾 status：停用通道的舊地址仍收得到款、仍會入帳，漏單一樣要補。
     *
     * @return list<array<string, mixed>>
     *
     * @throws ApiException 有支付通道，但每一組（幣, 鏈）都拉不到清單
     */
    public function listDepositRecordsBetween(int $startAt, int $endAt): array
    {
        $records = [];
        $specs = $this->channelSpecs();
        $failures = 0;

        foreach ($specs as $spec) {
            try {
                $page = $this->listDepositRecordsFor($spec['coin_id'], $spec['chain'], $startAt, $endAt, self::MAX_RECORDS - count($records));
            } catch (ApiException $e) {
                $failures++;
                Log::error($this->getDriverName() . ' deposit record list failed for one channel, skipped', [
                    'agent_cash_flow_id' => $this->agentCashFlow->id,
                    'coin_id' => $spec['coin_id'],
                    'chain' => $spec['chain'],
                    'error' => $e->getMessage(),
                    'trace' => Common::formatTrace($e),
                ]);

                continue;
            }

            foreach ($page as $row) {
                $records[] = $row;
            }
        }

        if ($specs !== [] && $failures === count($specs)) {
            throw new ApiException(sprintf(
                '%s deposit record list failed for every channel of merchant %d',
                $this->getDriverName(),
                $this->agentCashFlow->id
            ));
        }

        return $records;
    }

    /**
     * 請金流商重送指定 recordIds 的 webhook；不看原本送成功與否（AllResult），單批最多 50 筆，
     * 時段由呼叫端保證不超過 1 小時。回傳金流商回報的成功重送數。
     *
     * @param  list<string>  $recordIds
     */
    public function resendWebhooksFor(string $transactionType, array $recordIds, int $startTimestamp, int $endTimestamp): int
    {
        $total = 0;

        foreach (array_chunk($recordIds, self::RESEND_BATCH) as $chunk) {
            $data = $this->resendWebhooks([
                'recordIds' => array_values($chunk),
                'startTimestamp' => $startTimestamp,
                'endTimestamp' => $endTimestamp,
                'webhookResult' => 'AllResult',
                'transactionType' => $transactionType,
            ]);

            $total += is_numeric($data['resendCount'] ?? null) ? (int) $data['resendCount'] : 0;
        }

        return $total;
    }

    /**
     * 本商戶所有支付通道的（幣, 鏈）去重後的集合；幣或鏈解析不出來的通道跳過並記錄，不中斷其餘通道。
     *
     * @return list<array{coin_id: int, chain: string}>
     */
    private function channelSpecs(): array
    {
        $specs = [];

        $channels = AgentCashFlowOrderType::query()
            ->with('orderType')
            ->where('agent_cash_flow_id', $this->agentCashFlow->id)
            ->get();

        foreach ($channels as $channel) {
            try {
                $spec = $this->channelSpec($channel);
            } catch (ApiException $e) {
                Log::warning($this->getDriverName() . ' payment channel skipped in reconciliation', [
                    'agent_cash_flow_id' => $this->agentCashFlow->id,
                    'channel_id' => $channel->id,
                    'order_type_key' => $channel->orderType?->key,
                    'error' => $e->getMessage(),
                ]);

                continue;
            }

            $specs[$spec['coin_id']][$spec['chain']] = $spec;
        }

        return array_values(array_merge([], ...array_map('array_values', $specs)));
    }

    /**
     * 拉一組（幣, 鏈）的存款紀錄，最多讀 $budget 筆（跨通道共用的保險絲餘額）。
     *
     * @return list<array<string, mixed>>
     */
    private function listDepositRecordsFor(int $coinId, string $chain, int $startAt, int $endAt, int $budget): array
    {
        if ($budget <= 0) {
            return [];
        }

        $records = [];
        $nextId = '';

        do {
            $params = [
                'coinId' => $coinId,
                'chain' => $chain,
                'startAt' => $startAt,
                'endAt' => $endAt,
            ];
            if ($nextId !== '') {
                $params['nextId'] = $nextId;
            }

            $data = $this->listDepositRecords($params);

            $page = $data['records'] ?? [];
            if (!is_array($page)) {
                throw new ApiException($this->getDriverName() . ' deposit record list returned unexpected records shape');
            }

            foreach ($page as $row) {
                if (is_array($row)) {
                    $records[] = $row;
                }
            }

            $nextId = is_scalar($data['nextId'] ?? null) ? (string) $data['nextId'] : '';
        } while ($nextId !== '' && count($records) < $budget);

        return $records;
    }
}
