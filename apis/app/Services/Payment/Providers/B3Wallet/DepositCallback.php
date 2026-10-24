<?php

declare(strict_types=1);

namespace App\Services\Payment\Providers\B3Wallet;

use App\Enums\General\HttpCode;
use App\Exceptions\DepositOrderException;
use App\Facades\BotNotify;
use App\Helpers\Common;
use App\Models\AgentCashFlow;
use App\Models\Order;
use App\Services\Payment\AbstractMethod;
use App\Services\Payment\Data\DepositCallbackResponseData;
use App\Services\Payment\Enums\OrderRequestActionEnum;
use App\Services\Payment\Enums\OrderRequestResultEnum;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 代收回調。
 *
 * 硬規則：
 * - 🔴 **查單一律在交易外**：先不鎖讀訂單、打查單，拿到結果才開短交易鎖單寫入。
 *   查單放在交易裡，三方一慢，訂單的行鎖就被拉成 HTTP 往返那麼久。
 * - 🔴 **查單用建單時存下的 BillId，不用回調帶的**：回調無簽名，`Param` 與 `BillId` 都是送回調的人填的。
 *   拿回調的 BillId 去查，等於讓對方把另一張已付款的帳單（同商戶）配到這張訂單上結算。
 *   兩者不一致直接拒絕。
 * - 🔴 **入帳金額只信查單**：查單回 PAID 卻沒帶 Amount 時不定案，不退回用回調的金額。
 *
 * @knowledge rules/provider-b3wallet
 */
class DepositCallback extends AbstractMethod
{
    use B3WalletTrait;

    protected AgentCashFlow $agentCashFlow;

    protected array $info;

    public function __construct(AgentCashFlow $agentCashFlow)
    {
        $this->agentCashFlow = $agentCashFlow;
        $this->setInfo($agentCashFlow->info);
    }

    public function handle(Request $request): DepositCallbackResponseData
    {
        // 進來時的交易層數：測試環境由 DatabaseTransactions 先開了一層，收尾只能收掉我們自己多開的
        $baseline = DB::transactionLevel();
        $response = null;

        try {
            return $response = $this->process($request);
        } finally {
            $this->closeDanglingTransaction($baseline);

            // 🔴 交易外記錄（理由見 RecordsThirdPartyExchangeTrait）；用 $request->all() 留原文
            $this->logExchange(
                action: OrderRequestActionEnum::DEPOSIT_CALLBACK,
                url: $request->fullUrl(),
                request: $request->all(),
                response: $response?->response,
                result: $this->result,
                requestHeaders: $request->headers->all(),
                order: $this->loggedOrder,
                orderNo: (string) $request->input('Param'),
                thirdPartyOrderNo: (string) $request->input('BillId'),
                sourceIp: Common::getIp(),
                httpStatus: $response?->httpStatusCode,
            );
        }
    }

    private function process(Request $request): DepositCallbackResponseData
    {
        // B3 Wallet 回調無簽名欄位，鑑權僅靠來源 IP 白名單（external 路由層把關）
        $input = $request->validate([
            'BillId' => 'required|string',
            'Amount' => 'required|numeric',
            'BuyerId' => 'nullable|string',
            'Paid' => 'required|boolean',
            'Param' => 'nullable|string',
            'ProductName' => 'required|string',
            'ShopName' => 'required|string',
        ]);

        // Param 於建單時已寫入我方 order_no，用於對應訂單（BillId 僅為三方單號）。
        // 不鎖：只為擋掉不需查單的情況，真正的讀改寫在下方交易內重取並鎖定。
        // 🔴 依賴 orders.order_no 的唯一索引，理由同 Ap8Pay\DepositCallback
        $order = Order::where('order_no', (string) $input['Param'])->first();
        $order = $order && $this->orderBelongsToCallbackChannel($order) ? $order : null;
        $this->loggedOrder = $order;

        try {
            $this->checkDepositOrderStatus($order);
        } catch (DepositOrderException $e) {
            $this->result = $order === null
                ? OrderRequestResultEnum::ORDER_NOT_FOUND
                : OrderRequestResultEnum::ALREADY_PROCESSED;

            return $this->handleDepositOrderException($e, $order);
        }

        $verified = $this->queryOwnBill($order, $input['BillId']);
        if ($verified instanceof DepositCallbackResponseData) {
            return $verified;
        }

        [$billId, $queried] = $verified;

        $billStatus = $queried['Status'] ?? null;
        $isPaid = in_array($billStatus, ['PAID', 'SETTLED'], true);

        if (!$isPaid && !in_array($billStatus, ['EXPIRED', 'CANCELLED'], true)) {
            // 查詢結果仍為 PENDING 或不明確：不 finalize，等待後續回調重試
            $this->result = OrderRequestResultEnum::NOT_FINALIZED;

            Log::info($this->getDriverName() . ' callback bill status not final, skip finalize', [
                'order_no' => $order->order_no,
                'bill_id' => $billId,
                'queried_status' => $billStatus,
            ]);

            return $this->buildDepositSuccessResponse($order);
        }

        if ($isPaid && !isset($queried['Amount'])) {
            $this->result = OrderRequestResultEnum::NO_AMOUNT;

            return $this->retryLater('deposit query returned PAID without amount', $order, $billId);
        }

        try {
            DB::beginTransaction();

            $order = $this->findAndLockDepositOrder($order->order_no);

            try {
                // 查單期間可能已被另一筆回調定案，鎖到之後再檢查一次
                $this->checkDepositOrderStatus($order);
            } catch (DepositOrderException $e) {
                DB::rollBack();

                $this->result = $order === null
                    ? OrderRequestResultEnum::ORDER_NOT_FOUND
                    : OrderRequestResultEnum::ALREADY_PROCESSED;

                return $this->handleDepositOrderException($e, $order);
            }

            if ($isPaid) {
                $this->updateDepositOrderSuccess(
                    order: $order,
                    thirdPartyOrderNo: $billId,
                    callbackData: $input,
                    amount: Common::numAdd('0', (string) $queried['Amount'], 4),
                );
            } else {
                $this->updateDepositOrderFailed(
                    order: $order,
                    thirdPartyOrderNo: $billId,
                    callbackData: $input,
                );
            }

            DB::commit();

            $this->result = OrderRequestResultEnum::OK;

            return $this->buildDepositSuccessResponse($order);
        } catch (Exception $e) {
            DB::rollBack();

            BotNotify::channel('error')->send($e->getMessage());

            throw $e;
        }
    }

    /**
     * 決定拿哪張帳單去查、查單、驗證查回來的是本單的帳單。
     *
     * @return array{0: string, 1: array<string, mixed>}|DepositCallbackResponseData 通過時回 [帳單號, 查單結果]，
     *                                                                               擋下時回要給三方的響應
     */
    private function queryOwnBill(Order $order, string $callbackBillId): array|DepositCallbackResponseData
    {
        $billId = (string) $order->third_party_order_no;
        if ($billId !== '' && $billId !== $callbackBillId) {
            $this->result = OrderRequestResultEnum::QUERY_ANOTHER_ORDER;

            return $this->rejectAnotherBill($order, $callbackBillId);
        }

        // 建單沒存到 BillId（建單響應遺失）時才退回用回調的值，此時歸屬只剩查回來的 Param 能證明，必須帶且相符
        $hasStoredBillId = $billId !== '';
        $billId = $hasStoredBillId ? $billId : $callbackBillId;

        // 無簽名機制，不可只信回調 Paid，須以查詢接口二次確認（api.md「關鍵規則」第 3 點）
        try {
            $queried = $this->queryBill($billId);
        } catch (Exception $e) {
            $this->result = OrderRequestResultEnum::QUERY_FAILED;

            return $this->retryLater('deposit callback query failed: ' . $e->getMessage(), $order, $billId);
        }

        // 查回來的必須正是送去查的那張（BillId 直接接進 URL 路徑，防 `../` 之類把查詢導到別支 API），
        // 且建單沒存到 BillId 時，查回的 Param 必須帶且是本單
        $queriedParam = $queried['Param'] ?? null;
        if (($queried['BillId'] ?? null) !== $billId
            || ($queriedParam === null ? !$hasStoredBillId : (string) $queriedParam !== $order->order_no)) {
            $this->result = OrderRequestResultEnum::QUERY_ANOTHER_ORDER;

            return $this->rejectAnotherBill($order, $billId);
        }

        return [$billId, $queried];
    }

    /**
     * 這次定不了案、要三方晚點重送：回 HTTP 500 並發告警。
     *
     * 🔴 本家重送只看 HTTP 狀態（api.md「回調通知」：商戶只需回 2xx），回 2xx 的 fail 文本它就不再送了，
     * 訂單會停在原狀沒人知道。查單失敗、查單結果不完整都走這裡；確定是偽造或錯配的則回 2xx，不要它重送。
     */
    private function retryLater(string $reason, Order $order, string $billId): DepositCallbackResponseData
    {
        Log::error($this->getDriverName() . ' ' . $reason, ['order_no' => $order->order_no, 'bill_id' => $billId]);

        BotNotify::channel('error')->send(sprintf('%s %s，訂單:%s 帳單:%s', $this->getDriverName(), $reason, $order->order_no, $billId));

        return DepositCallbackResponseData::from([
            'response' => $this->notifyFailText,
            'errorMessage' => $reason,
            'order' => $order,
            'httpStatusCode' => HttpCode::HTTP_INTERNAL_SERVER_ERROR->value,
        ]);
    }

    /**
     * 回調的帳單不是這張訂單的帳單：正常流程不會發生，是偽造或錯配的訊號，發告警並回 FAIL。
     */
    private function rejectAnotherBill(Order $order, string $billId): DepositCallbackResponseData
    {
        $context = [
            'order_no' => $order->order_no,
            'stored_bill_id' => $order->third_party_order_no,
            'callback_bill_id' => $billId,
        ];

        BotNotify::channel('warn')->send(sprintf(
            '[代收回調遭拒：帳單不屬於此訂單]%s%s%s%s',
            PHP_EOL,
            $this->getDriverName(),
            PHP_EOL,
            Common::safeJsonEncode($context)
        ));

        return $this->buildDepositFailResponse(
            errorMessage: 'Bill does not belong to order',
            logContext: $context,
            order: $order,
        );
    }
}
