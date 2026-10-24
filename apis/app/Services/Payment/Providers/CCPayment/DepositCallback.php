<?php

declare(strict_types=1);

namespace App\Services\Payment\Providers\CCPayment;

use App\Enums\DepositManualReviewReasonEnum;
use App\Enums\OrderStatusEnum;
use App\Exceptions\ApiException;
use App\Facades\BotNotify;
use App\Helpers\Common;
use App\Models\AgentCashFlow;
use App\Models\AgentCashFlowOrderType;
use App\Models\DepositManualReview;
use App\Models\Member;
use App\Models\MemberCryptoWallet;
use App\Models\Order;
use App\Services\OrderService;
use App\Services\Payment\AbstractMethod;
use App\Services\Payment\Data\DepositCallbackResponseData;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 代收回調：永久地址模式的 DirectDeposit（api.md §5.4）與風險地址回調（api.md §5.9）。
 *
 * 兩種回調打同一個網址（deposit/notify/{agent_cash_flow_id}），信封不同：風險地址回調沒有 type，
 * 只有 chainId／address／riskAt；以「有沒有 riskAt」分流。兩種都先驗簽（api.md §5.7，三種回調同一套）。
 *
 * DirectDeposit 的判定矩陣：
 *
 * | 回查結果 | 動作 |
 * |---|---|
 * | 同 recordId 已有存款單 | 冪等：不回查、回 ack，回傳既有單（狀態 1 時由 controller 補入帳，其餘不動） |
 * | referenceId 不是我方格式 | 不建單、告警、ack（款已在我方帳戶，人工歸屬） |
 * | referenceId 指到的支付通道不存在或不屬於本金流商戶 | 不建單、告警、ack（不回查） |
 * | status 非 Success | 不建單、ack（Processing 等下一次回調；Failed 只記錄） |
 * | 代幣或鏈別不等於支付通道的（幣, 鏈）設定（CCPaymentTrait::channelSpec） | 不建單、告警、ack（別種幣或別條鏈，系統無法定價，營運到金流商後台處理） |
 * | 地址在表裡不屬於 referenceId 的會員 | 不建單、告警、ack（資料異常） |
 * | Success 且 isFlaggedAsRisky | 建單為狀態 4 待人工確認、同交易寫存款人工確認紀錄（待處理、不可上分）、告警；不寫金流商手續費 |
 * | Success 且非風險 | 建單、狀態 1，交 controller 入帳；金額＝回查的實收數量；金流商手續費＝回查的服務費 × 訂單匯率 |
 *
 * 金流商手續費（orders.payment_cost）不走共用流程的「點數 × 成本費率」：CCPayment 的服務費是階梯式浮動，
 * 以回查的 serviceFee 為準（CCPaymentTrait::actualPaymentCost()）。服務費缺漏或不是數字時不寫、告警，
 * 入帳時由 PaymentService::paymentCostOf() 退回成本費率算法。風險交易單建單時不寫：服務費要等營運在
 * 金流商後台退款成功才扣，建單當下不知道會不會發生；退款成功後由 ccpayment:sync-risky-refunds 寫入。
 *
 * 風險交易的後續：營運在金流商後台只能退款，金流商不發 webhook。存款人工確認紀錄（DepositManualReview）
 * 擋住舊代理後台的手動上分，ccpayment:sync-risky-refunds 查退款紀錄後更新訂單與該列
 * （integration.md「風險交易的後續處理」）。
 *
 * 🔴 廠商硬性要求：不可只信 webhook，一律以 getAppDepositRecord 回查結果判定（api.md §5.4 廠商警告二）；
 * 🔴 風險交易不可自動入帳（api.md §5.4 廠商警告一）。
 *
 * 併發與冪等：存款單以 orders.third_party_order_no = recordId 為冪等鍵；建單交易的第一個讀是
 * 鎖定會員主鍵列，之後的一般讀快照才含別人剛 commit 的單（RR 隔離級別，見 .claude/rules/php.md）。
 * 冪等檢查刻意不對 orders 下 FOR UPDATE：對不存在的非唯一鍵下鎖會拿 gap lock，並發插入會 deadlock。
 * 回查（HTTP）放在交易之外，不持鎖等外部回應。
 *
 * 存款單的 ip 欄位由 OrderService::createOrder 取當下請求 IP，也就是金流商的發送 IP，不是會員的。
 *
 * ! 驗簽刻意不檢查 Timestamp 時效，理由見 CCPaymentTrait::verifyWebhookSignature()。
 *
 * @knowledge rules/payment-cost-summary
 * @knowledge rules/provider-ccpayment
 */
class DepositCallback extends AbstractMethod
{
    use CCPaymentTrait;

    private const TYPE_DIRECT_DEPOSIT = 'DirectDeposit';

    private const STATUS_SUCCESS = 'Success';

    /** 廠商要求成功回應 HTTP 200 且 body 為 Success（api.md §5.2） */
    protected string|array $notifySuccessText = 'Success';

    protected string|array $notifyFailText = 'Fail';

    public function __construct(
        protected AgentCashFlow $agentCashFlow
    ) {
        $this->setInfo($agentCashFlow->info);
    }

    public function handle(Request $request): DepositCallbackResponseData
    {
        $this->assertWebhookRequest($request, 'deposit callback');

        if ($this->isRiskyAddressWebhook($request)) {
            return $this->handleRiskyAddress($request);
        }

        return $this->handleDirectDeposit($request);
    }

    // =========================================================================
    // DirectDeposit
    // =========================================================================

    private function handleDirectDeposit(Request $request): DepositCallbackResponseData
    {
        $input = $request->validate([
            'type' => 'required|string',
            'msg' => 'required|array',
            'msg.recordId' => 'required|string',
            'msg.referenceId' => 'required|string',
            'msg.status' => 'required|string',
        ]);

        if ($input['type'] !== self::TYPE_DIRECT_DEPOSIT) {
            throw new ApiException(sprintf(
                '%s deposit callback got unexpected type: %s',
                $this->getDriverName(),
                $input['type']
            ));
        }

        $recordId = (string) $input['msg']['recordId'];

        // 冪等：同一筆存款已建過單就直接 ack，不再回查
        $existing = $this->findOrderByRecordId($recordId);
        if ($existing !== null) {
            Log::info($this->getDriverName() . ' deposit callback already processed', [
                'record_id' => $recordId,
                'order_no' => $existing->order_no,
                'status' => $existing->status,
            ]);

            return $this->buildDepositSuccessResponse($existing);
        }

        $parsed = ReferenceId::parse((string) $input['msg']['referenceId']);
        if ($parsed === null) {
            $this->alertAndLog('referenceId 不是我方格式，款項已在金流商帳戶內，請人工歸屬', [
                'record_id' => $recordId,
                'reference_id' => $input['msg']['referenceId'],
            ]);

            return $this->buildDepositSuccessResponse();
        }

        $channel = $this->findOwnChannel($parsed['channel_id']);
        if ($channel === null) {
            $this->alertAndLog('referenceId 指到的支付通道不存在、或不屬於本金流商戶，未入帳', [
                'record_id' => $recordId,
                'member_id' => $parsed['member_id'],
                'channel_id' => $parsed['channel_id'],
            ]);

            return $this->buildDepositSuccessResponse();
        }

        // 廠商要求：回查後才可定案（見類別註解）。回查放在交易之外，不持鎖等 HTTP
        $record = $this->queryDepositRecord($recordId)['data']['record'] ?? null;
        if (!is_array($record)) {
            throw new ApiException($this->getDriverName() . ' deposit record query returned unexpected shape');
        }

        $fields = $this->extractRecordFields($record);

        if ($fields['status'] !== self::STATUS_SUCCESS) {
            // Processing 等下一次回調；Failed 沒有款可入帳；未知值一律不入帳
            Log::info($this->getDriverName() . ' deposit record not final, no order created', [
                'record_id' => $recordId,
                'queried_status' => $fields['status'],
            ]);

            return $this->buildDepositSuccessResponse();
        }

        $spec = $this->channelSpec($channel);
        if ($fields['coin_id'] !== $spec['coin_id'] || $fields['chain'] !== $spec['chain']) {
            $this->alertAndLog('收到非本支付通道代幣或鏈別的存款，未入帳，請到金流商後台人工處理', [
                'record_id' => $recordId,
                'coin_id' => $fields['coin_id'],
                'coin_symbol' => $record['coinSymbol'] ?? null,
                'chain' => $fields['chain'],
                'expected_coin_id' => $spec['coin_id'],
                'expected_chain' => $spec['chain'],
                'amount' => $fields['amount'],
                'member_id' => $parsed['member_id'],
                'channel_id' => $channel->id,
            ]);

            return $this->buildDepositSuccessResponse();
        }

        return $this->settleDeposit($recordId, $parsed, $channel, $record, $fields);
    }

    /**
     * 回查紀錄的欄位正規化：數值入境即 Common::numAdd，型別不符一律當成缺值。
     *
     * @param  array<string, mixed>  $record
     * @return array{status: string, coin_id: int, chain: string, to_address: string, amount: string, is_risky: bool}
     */
    private function extractRecordFields(array $record): array
    {
        return [
            'status' => is_scalar($record['status'] ?? null) ? (string) $record['status'] : '',
            'coin_id' => is_numeric($record['coinId'] ?? null) ? (int) $record['coinId'] : 0,
            'chain' => is_scalar($record['chain'] ?? null) ? (string) $record['chain'] : '',
            'to_address' => is_scalar($record['toAddress'] ?? null) ? trim((string) $record['toAddress']) : '',
            'amount' => Common::numAdd('0', is_scalar($record['amount'] ?? null) ? (string) $record['amount'] : '0', 4),
            'is_risky' => (bool) ($record['isFlaggedAsRisky'] ?? false),
        ];
    }

    /**
     * 交易內建單：鎖會員 → 核對收款地址 → 冪等二查 → 建單（風險交易留狀態 4）。
     *
     * @param  array{channel_id: int, member_id: int}  $parsed
     * @param  array<string, mixed>  $record
     * @param  array{status: string, coin_id: int, chain: string, to_address: string, amount: string, is_risky: bool}  $fields
     */
    private function settleDeposit(string $recordId, array $parsed, AgentCashFlowOrderType $channel, array $record, array $fields): DepositCallbackResponseData
    {
        try {
            DB::beginTransaction();

            $member = $this->lockDepositMember($recordId, $parsed, $channel, $fields['to_address']);
            if ($member === null) {
                DB::rollBack();

                return $this->buildDepositSuccessResponse();
            }

            // 鎖後再查一次冪等鍵：兩個同 recordId 的回調同時進來時，後到的在這裡看到先到的單
            $existing = $this->findOrderByRecordId($recordId);
            if ($existing !== null) {
                DB::rollBack();

                return $this->buildDepositSuccessResponse($existing);
            }

            if (Common::numCompare($fields['amount'], '0', 4) <= 0) {
                DB::rollBack();
                $this->alertAndLog('回查的實收數量不是正數，未入帳', [
                    'record_id' => $recordId,
                    'member_id' => $member->id,
                    'amount' => $fields['amount'],
                ]);

                return $this->buildDepositSuccessResponse();
            }

            $order = $this->createDepositOrder($channel, $member, $fields['amount'], $recordId);

            if ($fields['is_risky']) {
                // 廠商要求：風險交易不可自動入帳，款不在我方餘額；留單待人工，PM 拍板前的預設處置
                $order->update([
                    'status' => OrderStatusEnum::WAITING_MANUAL_DEPOSIT->value,
                    'third_party_order_no' => $recordId,
                    'remark' => Common::safeJsonEncode($record),
                ]);

                // 同一個交易寫入待處理列：舊代理後台看到這列就擋手動上分，建單與上鎖之間沒有空窗
                DepositManualReview::createPending($order, $this->agentCashFlow, DepositManualReviewReasonEnum::RISKY_TRANSACTION);

                DB::commit();

                $this->alertAndLog('存款被金流商判定為風險交易，款項不在商戶餘額，已建單待人工確認，請到金流商後台處理', [
                    'record_id' => $recordId,
                    'order_no' => $order->order_no,
                    'member_id' => $member->id,
                    'amount' => $fields['amount'],
                ]);

                return $this->buildDepositSuccessResponse($order);
            }

            $this->updateDepositOrderSuccess(
                order: $order,
                thirdPartyOrderNo: $recordId,
                callbackData: $record,
                amount: $fields['amount'],
            );

            // 金流商手續費先寫在單上，入帳時 PaymentService::paymentCostOf() 沿用、不以成本費率覆寫
            $paymentCost = $this->actualPaymentCost($record['serviceFee'] ?? null, (string) ($order->rate ?? 1));
            if ($paymentCost !== null) {
                $order->update(['payment_cost' => $paymentCost]);
            }

            DB::commit();

            if ($paymentCost === null) {
                $this->alertAndLog('回查的服務費缺漏或不是數字，已照常入帳，金流商手續費改用成本費率計算', [
                    'record_id' => $recordId,
                    'order_no' => $order->order_no,
                    'service_fee' => $record['serviceFee'] ?? null,
                ]);
            }

            Log::info($this->getDriverName() . ' deposit order created from callback', [
                'record_id' => $recordId,
                'order_no' => $order->order_no,
                'member_id' => $member->id,
                'amount' => $fields['amount'],
            ]);

            return $this->buildDepositSuccessResponse($order);
        } catch (Exception $e) {
            DB::rollBack();

            BotNotify::channel('error')->send($e->getMessage());

            throw $e;
        }
    }

    /**
     * referenceId 指到的支付通道，必須屬於本金流商戶；不鎖（支付通道是設定，不是要改的資料）。
     */
    private function findOwnChannel(int $channelId): ?AgentCashFlowOrderType
    {
        $channel = AgentCashFlowOrderType::query()->with('orderType')->find($channelId);

        if ($channel === null || (int) $channel->agent_cash_flow_id !== (int) $this->agentCashFlow->id) {
            return null;
        }

        return $channel;
    }

    /**
     * 鎖定會員並核對回查的收款地址；核對失敗已告警，呼叫端只需 rollback。
     *
     * 交易內第一個讀就是鎖定會員主鍵列（會員級互斥），之後的一般讀快照才含別人剛 commit 的單。
     * 反查鍵（支付通道, address）不看 status：解綁後轉進舊地址的款仍會入帳。
     *
     * @param  array{channel_id: int, member_id: int}  $parsed
     */
    private function lockDepositMember(string $recordId, array $parsed, AgentCashFlowOrderType $channel, string $toAddress): ?Member
    {
        $member = Member::lockForUpdate()->find($parsed['member_id']);

        if ($member === null) {
            $this->alertAndLog('referenceId 指到的會員不存在，未入帳', [
                'record_id' => $recordId,
                'member_id' => $parsed['member_id'],
                'channel_id' => $channel->id,
            ]);

            return null;
        }

        $wallet = MemberCryptoWallet::query()
            ->where('agent_cash_flow_order_type_id', $channel->id)
            ->where('address', $toAddress)
            ->first();

        if ($wallet === null || (int) $wallet->member_id !== $member->id) {
            $this->alertAndLog('回查的收款地址在本支付通道找不到，或不屬於 referenceId 的會員，未入帳', [
                'record_id' => $recordId,
                'member_id' => $member->id,
                'channel_id' => $channel->id,
                'wallet_member_id' => $wallet?->member_id,
            ]);

            return null;
        }

        return $member;
    }

    /**
     * 憑回查結果建存款單，金額＝鏈上實收數量；低於通道下限照常建單只留紀錄（鏈上轉帳擋不住小額）。
     */
    private function createDepositOrder(AgentCashFlowOrderType $channel, Member $member, string $amount, string $recordId): Order
    {
        $orderType = $channel->orderType;
        if ($orderType === null) {
            throw new ApiException($this->getDriverName() . ' payment channel has no order type');
        }

        if ($channel->min_amount > 0 && Common::numCompare($amount, (string) $channel->min_amount, 4) < 0) {
            Log::info($this->getDriverName() . ' deposit below channel minimum, credited anyway', [
                'record_id' => $recordId,
                'amount' => $amount,
                'min_amount' => $channel->min_amount,
            ]);
        }

        $order = app(OrderService::class)->createOrder($orderType, $this->agentCashFlow, $member, $amount);
        if ($order === null) {
            throw new ApiException($this->getDriverName() . ' failed to create deposit order for record ' . $recordId);
        }

        return $order;
    }

    // =========================================================================
    // 風險地址回調
    // =========================================================================

    private function isRiskyAddressWebhook(Request $request): bool
    {
        return !$request->has('type') && $request->has('riskAt');
    }

    /**
     * 風險地址：該列停用、立刻解綁、告警；換發新地址留到會員下次開儲值頁或點選取址（MemberCryptoWalletService 保底）。
     * 不在這裡立刻換發：解綁不釋放金流商的地址配額，替不再回來的會員換發只會白占配額。
     * 不自動通知會員（推播失敗率太高），由營運視情況用後台站內信手動發。chainId 只記錄不比對，鏈別以地址所屬的支付通道為準。
     */
    private function handleRiskyAddress(Request $request): DepositCallbackResponseData
    {
        $input = $request->validate([
            'address' => 'required|string',
            'riskAt' => 'required|integer',
            'chainId' => 'nullable|string',
        ]);

        $address = trim((string) $input['address']);

        $channels = AgentCashFlowOrderType::query()
            ->with('orderType')
            ->where('agent_cash_flow_id', $this->agentCashFlow->id)
            ->get();

        $wallet = MemberCryptoWallet::query()
            ->whereIn('agent_cash_flow_order_type_id', $channels->pluck('id'))
            ->where('address', $address)
            ->first();

        if ($wallet === null) {
            $this->alertAndLog('收到風險地址回調，但地址不在本金流商戶的任何支付通道內', [
                'address' => $address,
                'chain_id' => $input['chainId'] ?? null,
                'risk_at' => $input['riskAt'],
            ]);

            return $this->buildDepositSuccessResponse();
        }

        DB::transaction(function () use ($wallet): void {
            MemberCryptoWallet::query()
                ->whereKey($wallet->id)
                ->lockForUpdate()
                ->first()
                ?->update(['status' => 0]);
        });

        $unbound = $this->tryUnbind(
            $channels->firstWhere('id', (int) $wallet->agent_cash_flow_order_type_id),
            $address,
            (int) $wallet->member_id
        );

        $this->alertAndLog(
            $unbound
                ? '收款地址被金流商標記為風險地址，已停用並解綁；會員下次點選會拿到新地址，請視情況用站內信通知'
                : '收款地址被金流商標記為風險地址，已停用但解綁失敗；會員下次點選取址時會再試解綁，請留意',
            [
                'address' => $address,
                'member_id' => $wallet->member_id,
                'channel_id' => $wallet->agent_cash_flow_order_type_id,
                'chain_id' => $input['chainId'] ?? null,
                'risk_at' => $input['riskAt'],
            ]
        );

        return $this->buildDepositSuccessResponse();
    }

    /** 解綁失敗只記錄不拋出：會員下次點選取址時，服務層保底會再解綁一次。鏈別由地址所屬的支付通道決定 */
    private function tryUnbind(?AgentCashFlowOrderType $channel, string $address, int $memberId): bool
    {
        if ($channel === null) {
            Log::error($this->getDriverName() . ' risky address payment channel not found', [
                'address' => $address,
                'member_id' => $memberId,
            ]);

            return false;
        }

        try {
            $this->unbindAddress($this->channelChain($channel), $address);

            return true;
        } catch (Exception $e) {
            Log::error($this->getDriverName() . ' risky address unbinding failed', [
                'address' => $address,
                'member_id' => $memberId,
                'error' => $e->getMessage(),
                'trace' => Common::formatTrace($e),
            ]);

            return false;
        }
    }

    // =========================================================================
    // 共用
    // =========================================================================

    private function findOrderByRecordId(string $recordId): ?Order
    {
        return Order::query()
            ->where('agent_cash_flow_id', $this->agentCashFlow->id)
            ->where('third_party_order_no', $recordId)
            ->first();
    }
}
