<?php

declare(strict_types=1);

namespace App\Services\Payment\Providers\EasyPay;

use App\Exceptions\DepositOrderException;
use App\Facades\BotNotify;
use App\Helpers\Common;
use App\Models\AgentCashFlow;
use App\Services\Payment\AbstractMethod;
use App\Services\Payment\Data\DepositCallbackResponseData;
use App\Services\Payment\Enums\OrderRequestActionEnum;
use App\Services\Payment\Enums\OrderRequestResultEnum;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * @knowledge rules/payment-callback-common-checks
 */
class DepositCallback extends AbstractMethod
{
    use EasyPayTrait;

    protected AgentCashFlow $agentCashFlow;

    protected array $info;

    protected string|array $notifySuccessText = 'success';

    protected string|array $notifyFailText = 'fail';

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

            // 🔴 交易外記錄：寫在交易內的話，回調被拒絕或 rollback 會連紀錄一起沒。
            // 🔴 用 $request->all() 不是 validate() 的結果：後者只留規則裡列的欄位，
            //    三方多送的（簽名、額外狀態碼）會被丟掉，而那正是事後要查的東西。
            $this->logExchange(
                action: OrderRequestActionEnum::DEPOSIT_CALLBACK,
                url: $request->fullUrl(),
                request: $request->all(),
                response: $response?->response,
                result: $this->result,
                requestHeaders: $request->headers->all(),
                order: $this->loggedOrder,
                orderNo: (string) $request->input('tx_id'),
                thirdPartyOrderNo: (string) $request->input('order_id'),
                sourceIp: Common::getIp(),
                httpStatus: $response?->httpStatusCode,
            );
        }
    }

    private function process(Request $request): DepositCallbackResponseData
    {
        // 防線 1：來源 IP（fail-closed，規則見 CallbackSourceGuardTrait）。
        //
        // 驗簽是防線 2，但它按商戶開關、預設關閉，沒開的商戶回調不帶簽名 ⇒ 擋不住偽造回調，
        // 來源 IP 對那些商戶仍是唯一的防線。所以擋在 validate 之前：連欄位格式都還沒看，
        // 就不該讓不明來源的輸入影響任何狀態。
        $ip = Common::getIp();
        if (!$this->isCallbackSourceAllowed($ip, 'deposit callback')) {
            $this->result = OrderRequestResultEnum::IP_NOT_WHITELISTED;

            return $this->buildDepositFailResponse(
                errorMessage: 'Callback rejected',
                logMessage: 'deposit callback rejected: source IP not in whitelist',
                logContext: ['ip' => $ip],
            );
        }

        // 防線 2：回調簽名（只在三方真的帶了 mdfive_val 時才驗，見 EasyPayTrait::isCallbackSignatureValid）
        if (!$this->isCallbackSignatureValid($request->all())) {
            $this->result = OrderRequestResultEnum::INVALID_SIGNATURE;

            return $this->buildDepositFailResponse(
                errorMessage: 'Callback rejected',
                logMessage: 'deposit callback rejected: invalid signature',
                logContext: ['ip' => $ip],
            );
        }

        // 驗證請求參數格式
        $input = $request->validate([
            'tx_id' => 'required|string',
            'transferror_name' => 'required|string',
            'status' => 'required|string',
            'order_id' => 'required|string',
            'final_amount' => 'required|string',
            'order_amount' => 'required|string',
            'service_change' => 'required|string',
        ]);

        try {
            DB::beginTransaction();

            // 查詢訂單（加鎖）
            $order = $this->findAndLockDepositOrder($input['tx_id']);
            $this->loggedOrder = $order;

            try {
                // 檢查訂單狀態
                $this->checkDepositOrderStatus($order);
            } catch (DepositOrderException $e) {
                DB::rollBack();

                // 查無訂單（含渠道不符）與已處理過，兩者的 result 不同
                $this->result = $order === null
                    ? OrderRequestResultEnum::ORDER_NOT_FOUND
                    : OrderRequestResultEnum::ALREADY_PROCESSED;

                return $this->handleDepositOrderException($e, $order);
            }

            // 依官方文檔 status enum 三態判斷（代收 callback：COMPLETE / REJECT / OVERTIME / PENDING）：
            // COMPLETE=代收成功（終態）→ 入帳
            // REJECT=代收失敗（終態）→ 標失敗
            // 其餘（PENDING / OVERTIME / 未知）=非終態 → 不動訂單、回 ack 等後續終態回調，
            // 避免把「處理中」誤判失敗而漏入帳；失敗只認明確碼 REJECT，與全站慣例（ZPay 等）一致
            if ($input['status'] === 'COMPLETE') {
                // 更新訂單為成功（會自動檢查金額是否匹配）
                $this->updateDepositOrderSuccess(
                    order: $order,
                    thirdPartyOrderNo: $input['order_id'],
                    callbackData: $input,
                    amount: $input['order_amount']
                );
            } elseif ($input['status'] === 'REJECT') {
                // 更新訂單為失敗
                $this->updateDepositOrderFailed(
                    order: $order,
                    thirdPartyOrderNo: $input['order_id'],
                    callbackData: $input
                );
            } else {
                // 非終態：不動訂單，回 ack 等後續終態回調
                DB::rollBack();

                Log::info($this->getDriverName() . ' deposit callback non-final status, keep current order status', [
                    'order_no' => $order->order_no,
                    'status' => $input['status'],
                ]);

                $this->result = OrderRequestResultEnum::NOT_FINALIZED;

                return $this->buildDepositSuccessResponse($order);
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
}
