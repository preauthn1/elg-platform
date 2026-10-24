<?php

declare(strict_types=1);

namespace App\Services\Payment\Providers\Ap8Pay;

use App\Exceptions\DepositOrderException;
use App\Facades\BotNotify;
use App\Helpers\Common;
use App\Models\AgentCashFlow;
use App\Models\Order;
use App\Services\Payment\AbstractMethod;
use App\Services\Payment\Data\DepositCallbackResponseData;
use App\Services\Payment\Data\QueryOrderResponseData;
use App\Services\Payment\Enums\OrderRequestActionEnum;
use App\Services\Payment\Enums\OrderRequestResultEnum;
use App\Services\Payment\Enums\ProviderOrderStatusEnum;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Ap8Pay 代收回調。
 *
 * 🔴 **回調 body 沒有簽名，內容不能當成事實**（三方規格就沒有驗簽機制）。本類的三道防線
 * 缺一不可，禁止為了讓測試好過而放寬任何一道（target/tickets#4446）：
 * 1. 來源 IP 必須在 `cash_flows.whitelist` 內——fail-closed，白名單為空一律拒絕。
 * 2. 訂單必須屬於這次回調的金流商戶（`agent_cash_flow_id`）——否則 Ap8Pay 的回調網址
 *    可以拿來結掉別家金流商的訂單。
 * 3. 狀態與金額一律以向三方反查（{@see QueryOrder}）的結果為準。出處是母單 #4446 需求 4
 *    原文「以反查結果的狀態與金額為準」，以及它的預期結果「反查結果是未付款，這筆單不上分」
 *    ——回調說 SUCCESS、反查說未付款時不得上分。
 *
 * ⚠️ 不採信的範圍**只有「用 status 決定上不上分」與「用 amount 決定上多少分」**這兩件事，
 * 不是整包 body 都不看，照字面讀成「一個欄位都不能用」會找不到可行的實作：
 * - `uniqueCode` 決定去查哪一筆訂單，照用；真偽由「查回來的 uniqueCode 必須等於本單」把關。
 * - `code` 在建單沒落庫 taskCode 的舊單上，是查單唯一的鍵（新單一律用 orders.third_party_order_no）。
 * - `amount` 僅在訂單判定為 FAILED、且查單沒帶金額時，退回寫進 orders.amount 留存；
 *   該筆不上分，所以不影響金額正確性。SUCCESS 分支查不到金額一律拒絕，不退回用回調金額。
 *
 * @knowledge rules/deposit-order-fees
 * @knowledge rules/payment-callback-common-checks
 */
class DepositCallback extends AbstractMethod
{
    use Ap8PayTrait;

    protected string|array $notifySuccessText = 'OK';

    protected string|array $notifyFailText = 'FAIL';

    public function __construct(AgentCashFlow $agentCashFlow, protected QueryOrder $queryOrder)
    {
        $this->agentCashFlow = $agentCashFlow;
        $this->setInfo($agentCashFlow->info);
    }

    /** 本次實際拿去查單的 taskCode，僅供往返紀錄使用 */
    private ?string $loggedTaskCode = null;

    public function handle(Request $request): DepositCallbackResponseData
    {
        // 驗證請求參數格式
        $input = $request->validate([
            'code' => 'required|string',
            'status' => 'required|string',
            'uniqueCode' => 'required|string',
            'amount' => 'required|string',
            'details' => 'nullable|string',
        ]);

        // 進來時的交易層數：測試環境由 RefreshDatabase 先開了一層，收尾只能收掉我們自己多開的
        $baseline = DB::transactionLevel();
        $response = null;

        try {
            return $response = $this->process($input);
        } finally {
            $this->closeDanglingTransaction($baseline);

            // 🔴 **交易外記錄**：寫在交易內的話，回調被拒絕或 rollback 會連紀錄一起沒，
            // 剛好丟掉最想留的那筆。被防線擋下時我方回的 body 一律是 FAIL，
            // 從 response 分不出是哪一道擋的——那正是 result 這欄存在的理由。
            $this->logExchange(
                action: OrderRequestActionEnum::DEPOSIT_CALLBACK,
                url: $request->fullUrl(),
                // 用 all() 而非 $input：validate 只留宣告過的欄位，會丟掉三方實際有送的
                // type／uniqueKey，而日誌要的就是「對方到底送了什麼」
                request: $request->all(),
                response: $response?->response,
                result: $this->result,
                order: $this->loggedOrder,
                orderNo: (string) $input['uniqueCode'],
                thirdPartyOrderNo: $this->loggedTaskCode ?? (string) $input['code'],
                sourceIp: Common::getIp(),
                httpStatus: $response?->httpStatusCode,
                // 存原始 header 才能複驗 Common::getIp() 從 X-Forwarded-For 解出來的 IP 對不對
                requestHeaders: $request->headers->all(),
            );
        }
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function process(array $input): DepositCallbackResponseData
    {

        // 防線 1：來源 IP（fail-closed，白名單的單一來源與取 IP 的方式見 CallbackSourceGuardTrait）
        $ip = Common::getIp();
        if (!$this->isCallbackSourceAllowed($ip, 'deposit callback')) {
            $this->result = OrderRequestResultEnum::IP_NOT_WHITELISTED;

            return $this->rejectForged('callback source IP not in whitelist', [
                'ip' => $ip,
                'orderNo' => $input['uniqueCode'],
            ]);
        }

        // 取得訂單（不鎖；只為確認歸屬與取出三方 taskCode，真正的讀改寫在下方交易內重取並鎖定）。
        //
        // 🔴 **這裡依賴 `orders.order_no` 的唯一索引**（`orders_order_no_unique`）：本次不鎖的讀
        // 與交易內 findAndLockDepositOrder() 的鎖定讀是兩次查詢，order_no 若可重複，這兩次可能
        // 讀到不同的列——防線 2 驗的是 A、實際上分的卻是 B。索引若被移除，這段不會報錯，
        // 只會安靜地變成可繞過，所以禁止在未還原唯一性的前提下動那個索引。
        $order = Order::where('order_no', $input['uniqueCode'])->first();
        $this->loggedOrder = $order;
        if (!$order) {
            $this->result = OrderRequestResultEnum::ORDER_NOT_FOUND;

            return $this->buildDepositFailResponse(
                errorMessage: 'Order not found',
                logMessage: 'deposit callback order not found',
                logContext: ['orderNo' => $input['uniqueCode']],
            );
        }

        // 防線 2：訂單歸屬——訂單必須屬於這次回調網址上的那家金流商（cash_flow_id，
        // 經 orders.agent_cash_flow_id → agent_cash_flows.cash_flow_id 取得）。
        //
        // 🔴 **這道與共用的 {@see DepositCallbackTrait::orderBelongsToCallbackChannel()} 規則相同，
        // 刻意不收斂掉**（工單 target/tickets#4449 需求 4 把同一條規則推到全部 33 家）：本家在這裡
        // 提前擋，是為了不對「別家金流商的訂單」多打一次三方查單（防線 3）；共用那道在交易內的
        // findAndLockDepositOrder()，對本家而言是擋不掉才會碰到的最後一層。
        // 另外本家要把判定寫進往返紀錄（ANOTHER_CHANNEL），共用方法回的是 null、分不出原因。
        // 比金流商而不比商戶（ACF）的原因見共用方法：回調網址固定在三方後台、我方重建 ACF 後
        // 回調會打到舊 ACF。`orders.cash_flow_id` 欄位代收建單路徑從沒寫過（見 OrderService::createOrder），
        // 永遠是 NULL，禁止直接拿它來比。
        $orderCashFlowId = $order->agentCashFlow?->cash_flow_id;
        if ($orderCashFlowId === null || (int) $orderCashFlowId !== (int) $this->agentCashFlow->cash_flow_id) {
            $this->result = OrderRequestResultEnum::ANOTHER_CHANNEL;

            return $this->rejectForged('callback order belongs to another payment channel', [
                'orderNo' => $order->order_no,
                'orderAgentCashFlowId' => (int) $order->agent_cash_flow_id,
                'callbackAgentCashFlowId' => (int) $this->agentCashFlow->id,
                'orderCashFlowId' => $orderCashFlowId === null ? null : (int) $orderCashFlowId,
                'callbackCashFlowId' => (int) $this->agentCashFlow->cash_flow_id,
            ], $order);
        }

        // 防線 3：狀態與金額以反查為準
        // 🔴 **落庫的 taskCode 一旦存在就不覆寫**（下方 updateDepositOrder* 寫回的也是這個值）：
        // 回調 body 的 code 是送回調的人填的，拿它蓋掉建單時我方與三方之間產生的查詢鍵，
        // 等於讓對方改寫我們用來驗證他的憑據。釘在 test_stored_task_code_wins_over_callback_code。
        //
        // 只有舊單（本次改動之前建立、建單沒落庫）才退回用 $input['code']，而且它寫進 DB 的時機
        // 在查單**之後**、且隔著下方「查回來的 uniqueCode 必須等於本單」那道比對——能通過就表示
        // AP8 以這個 code 回報了本筆單，是三方背書過的值，不是未驗證的輸入。
        $storedTaskCode = (string) $order->third_party_order_no;
        $taskCode = $storedTaskCode !== '' ? $storedTaskCode : (string) $input['code'];
        $this->loggedTaskCode = $taskCode;

        try {
            $remote = $this->queryOrder->fetch($taskCode, $order);
        } catch (Exception $e) {
            $this->result = OrderRequestResultEnum::QUERY_FAILED;

            // 查不到不等於失敗：回 FAIL 讓三方依重試排程再送，訂單維持原狀
            return $this->buildDepositFailResponse(
                errorMessage: 'Deposit query failed',
                logMessage: 'deposit callback query failed: ' . $e->getMessage(),
                logContext: ['orderNo' => $order->order_no, 'taskCode' => $taskCode],
                order: $order,
            );
        }

        if ($remote->orderNo !== $order->order_no) {
            $this->result = OrderRequestResultEnum::QUERY_ANOTHER_ORDER;

            return $this->rejectForged('deposit query returned another merchant order', [
                'orderNo' => $order->order_no,
                'taskCode' => $taskCode,
                'queriedOrderNo' => $remote->orderNo,
            ], $order);
        }

        $finalStatus = $this->resolveFinalStatus($remote, $order, $input);
        if ($finalStatus instanceof DepositCallbackResponseData) {
            return $finalStatus;
        }

        try {
            DB::beginTransaction();

            // 查詢訂單（加鎖）
            $order = $this->findAndLockDepositOrder($input['uniqueCode']);

            try {
                // 檢查訂單狀態
                $this->checkDepositOrderStatus($order);
            } catch (DepositOrderException $e) {
                DB::rollBack();

                // Ap8Pay 特殊響應格式
                if ($e->getCode() === 200) {
                    $this->result = OrderRequestResultEnum::ALREADY_PROCESSED;

                    return DepositCallbackResponseData::from([
                        'response' => 'Order already deposit finished',
                        'errorMessage' => $e->getMessage(),
                        'order' => $order,
                    ]);
                }

                return DepositCallbackResponseData::from([
                    'response' => $e->getMessage(),
                    'errorMessage' => $e->getMessage(),
                    'order' => $order,
                ]);
            }

            // Ap8Pay 特殊：總是更新金額和匯率（無論成功或失敗），金額取自反查結果
            $rate = $this->agentCashFlow->rate ?? 1;
            $amount = Common::numAdd('0', (string) ($remote->amount ?? $input['amount']), 4);
            $point = Common::numMul($amount, (string) $rate);

            if ($finalStatus === ProviderOrderStatusEnum::SUCCESS) {
                // 反查金額即權威金額，無可比對的對象，故不帶 amount 做比對
                $this->updateDepositOrderSuccess(
                    order: $order,
                    thirdPartyOrderNo: $taskCode,
                    callbackData: $input,
                    amount: null
                );
            } else {
                // 更新訂單為失敗
                $this->updateDepositOrderFailed(
                    order: $order,
                    thirdPartyOrderNo: $taskCode,
                    callbackData: $input
                );
            }

            // Ap8Pay 特殊：更新額外欄位（成功或失敗都要更新）
            $order->update([
                'amount' => $amount,
                'rate' => $rate,
                'point' => $point,
                // point 變了；payment_cost 在後續 updateMemberBalance 成功時依最終 point 寫入
            ]);

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
     * 判定可落地的終態；尚未終局或缺金額時回傳 FAIL 響應讓三方重送，訂單維持原狀。
     */
    private function resolveFinalStatus(
        QueryOrderResponseData $remote,
        Order $order,
        array $input
    ): ProviderOrderStatusEnum|DepositCallbackResponseData {
        if (!in_array($remote->orderStatus, [ProviderOrderStatusEnum::SUCCESS, ProviderOrderStatusEnum::FAIL], true)) {
            $this->result = OrderRequestResultEnum::NOT_FINALIZED;

            return $this->buildDepositFailResponse(
                errorMessage: 'Deposit not finalized',
                logMessage: 'deposit callback ignored: provider status is ' . $remote->orderStatus->name,
                logContext: ['orderNo' => $order->order_no, 'callbackStatus' => $input['status']],
                order: $order,
            );
        }

        // 成功卻查不到金額就沒有可信的上分依據，寧可讓三方重送。
        //
        // 🔴 **這條踩到就要有人立刻看到**：三方查單在未付款時不回 amount 欄位（2026-10-05 以測試商戶
        // 實測，官方 spec 的範例卻把 amount 與 PENDING 畫在一起，該欄位的 spec 不可信），所以
        // 「SUCCESS 會不會帶 amount」在真的付過一筆之前沒有被驗證過。萬一 SUCCESS 也不帶，
        // 這裡會擋掉每一筆代收——玩家錢付了卻不上分，而且三方重送 6 次後就不再送。
        // 故不是只記 log：踩到一律告警，由人判斷是改接受查單金額缺漏、還是回頭找廠商。
        if ($remote->orderStatus === ProviderOrderStatusEnum::SUCCESS && $remote->amount === null) {
            $this->result = OrderRequestResultEnum::NO_AMOUNT;

            BotNotify::channel('warn')->send(sprintf(
                '%s 代收查單回報 SUCCESS 但沒有金額，該筆未上分（訂單號：%s），請人工確認玩家是否已付款',
                $this->getDriverName(),
                $order->order_no,
            ));

            return $this->buildDepositFailResponse(
                errorMessage: 'Deposit query returned no amount',
                logMessage: 'deposit callback rejected: query returned SUCCESS without amount',
                logContext: ['orderNo' => $order->order_no],
                order: $order,
            );
        }

        return $remote->orderStatus;
    }

    /**
     * 回調疑似偽造：記錄、告警並回 FAIL。
     */
    private function rejectForged(string $reason, array $context, ?Order $order = null): DepositCallbackResponseData
    {
        BotNotify::channel('warn')->send(sprintf(
            '%s 代收回調遭拒：%s%s%s',
            $this->getDriverName(),
            $reason,
            PHP_EOL,
            Common::safeJsonEncode($context)
        ));

        return $this->buildDepositFailResponse(
            errorMessage: 'Callback rejected',
            logMessage: 'deposit callback rejected: ' . $reason,
            logContext: $context,
            order: $order,
        );
    }
}
