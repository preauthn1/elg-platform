<?php

declare(strict_types=1);

namespace App\Services\Payment\Providers\MaxPay;

use App\Enums\OrderStatusEnum;
use App\Exceptions\ApiException;
use App\Exceptions\DepositOrderException;
use App\Helpers\Common;
use App\Models\AgentCashFlow;
use App\Services\Payment\AbstractMethod;
use App\Services\Payment\Data\DepositCallbackResponseData;
use App\Services\Payment\Enums\OrderRequestActionEnum;
use App\Services\Payment\Enums\OrderRequestResultEnum;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * @knowledge rules/payment-callback-common-checks
 */
class DepositCallback extends AbstractMethod
{
    use MaxPayTrait;

    protected array $info;

    protected string|array $notifySuccessText = 'OK';

    protected string|array $notifyFailText = 'FAIL';

    public function __construct(AgentCashFlow $agentCashFlow)
    {
        $this->agentCashFlow = $agentCashFlow;
        $this->setInfo($agentCashFlow->info);
    }

    public function handle(Request $request): DepositCallbackResponseData
    {
        // 進來時的交易層數：測試環境由 RefreshDatabase 先開了一層，收尾只能收掉我們自己多開的
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
                orderNo: (string) $request->input('ref_no'),
                thirdPartyOrderNo: (string) $request->input('transaction_reference'),
                sourceIp: Common::getIp(),
                httpStatus: $response?->httpStatusCode,
            );
        }
    }

    private function process(Request $request): DepositCallbackResponseData
    {
        // 防線 1：來源 IP（fail-closed，規則見 CallbackSourceGuardTrait）。
        //
        // MaxPay 的代收回調沒有驗簽（MaxPayTrait::generateHash 只用在建單與代付請求），
        // 金額也直接取回調自帶的 amount，所以來源 IP 是唯一擋得住偽造回調的防線。
        // 擋在 validate 之前：連欄位格式都還沒看，就不該讓不明來源的輸入影響任何狀態。
        $ip = Common::getIp();
        if (!$this->isCallbackSourceAllowed($ip, 'deposit callback')) {
            $this->result = OrderRequestResultEnum::IP_NOT_WHITELISTED;

            return $this->buildDepositFailResponse(
                errorMessage: 'Callback rejected',
                logMessage: 'deposit callback rejected: source IP not in whitelist',
                logContext: ['ip' => $ip],
            );
        }

        $callbackData = $request->validate([
            'status_id' => 'required|integer',
            'amount' => 'required|string',
            'ref_no' => 'required|string',
            'transaction_reference' => 'required|string',
        ]);

        Log::info('MaxPay 代收回調已接收', $callbackData);

        $status = $callbackData['status_id'] ?? '';
        $orderNo = $callbackData['ref_no'] ?? null;
        $thirdPartyOrderNo = (string) ($callbackData['transaction_reference'] ?? '');
        $callbackAmount = $callbackData['amount'] ?? null;

        if (!$orderNo) {
            throw new ApiException('Missing order id in callback data');
        }

        DB::beginTransaction();
        try {
            $order = $this->findAndLockDepositOrder($orderNo);
            $this->loggedOrder = $order;

            try {
                $this->checkDepositOrderStatus($order);
            } catch (DepositOrderException $e) {
                DB::rollBack();

                // 查無訂單（含渠道不符，見 DepositCallbackTrait）與已處理過，兩者的判定不同；
                // 不分開的話需求 4 擋下來的攻擊在紀錄上看起來跟程式出錯一樣
                $this->result = $order === null
                    ? OrderRequestResultEnum::ORDER_NOT_FOUND
                    : OrderRequestResultEnum::ALREADY_PROCESSED;

                return $this->handleDepositOrderException($e, $order);
            }

            $resultStatus = match ($status) {
                1 => OrderStatusEnum::SUCCESS->value,
                2 => OrderStatusEnum::FAILED->value,
                default => OrderStatusEnum::FAILED->value,
            };

            if ($resultStatus === OrderStatusEnum::SUCCESS->value) {
                $this->updateDepositOrderSuccess($order, $thirdPartyOrderNo, $callbackData, $callbackAmount);
            } elseif ($resultStatus === OrderStatusEnum::FAILED->value) {
                $this->updateDepositOrderFailed($order, $thirdPartyOrderNo, $callbackData);
            } else {
                $order->update([
                    'status' => $resultStatus,
                    'third_party_order_no' => $thirdPartyOrderNo ?: $order->third_party_order_no,
                    'remark' => Common::safeJsonEncode($callbackData),
                ]);
            }

            DB::commit();

            $this->result = OrderRequestResultEnum::OK;

            Log::info('MaxPay 代收回調處理完成', [
                'order_id' => $order->id,
                'order_no' => $orderNo,
                'status' => $resultStatus,
                'third_party_order_no' => $thirdPartyOrderNo,
            ]);

            return DepositCallbackResponseData::from([
                'order' => $order,
                'response' => $this->notifySuccessText,
                'httpStatusCode' => 200,
                'errorMessage' => null,
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('MaxPay 代收回調例外', [
                'order_no' => $orderNo,
                'error' => $e->getMessage(),
                'trace' => Common::formatTrace($e),
            ]);

            throw $e;
        }
    }
}
