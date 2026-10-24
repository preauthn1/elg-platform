<?php

declare(strict_types=1);

namespace App\Services\Payment\Providers\TngPin;

use App\Enums\OrderStatusEnum;
use App\Exceptions\ApiException;
use App\Exceptions\DepositOrderException;
use App\Facades\BotNotify;
use App\Helpers\Common;
use App\Models\AgentCashFlow;
use App\Models\AgentCashFlowOrderType;
use App\Models\Order;
use App\Services\Payment\AbstractMethod;
use App\Services\Payment\Data\DepositCallbackResponseData;
use App\Services\Payment\Enums\OrderRequestActionEnum;
use App\Services\Payment\Enums\OrderRequestResultEnum;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * {
 * 'result': 'success',
 * 'message': 'Transaction successful',
 * 'detail_message': 'Reload Successful.',
 * 'status': '1',
 * 'amount': '5.00',
 * 'transaction_id': '4C00B61A6FDFCB6393F6',
 * 'pin': '1234567890123456',
 * 'merchant_ref': 'ORDER_3344',
 * 'signature': 'cb5a2e14fe4dd91b158847981e35d791',
 * 'transaction_date': '2021-06-01 12:53:55',
 * 'completion_date': '2021-06-01 12:54:03'
 * }
 *
 * @knowledge rules/agent-self-deposit
 * @knowledge rules/deposit-order-fees
 * @knowledge rules/payment-callback-common-checks
 */
class DepositCallback extends AbstractMethod
{
    use TngPinTrait;

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

            // 🔴 交易外記錄（理由見 RecordsThirdPartyExchangeTrait）。本家的失敗是拋 ApiException，
            // finally 照樣會執行，所以被擋／驗簽失敗的那些也留得下來（$response 為 null）。
            $this->logExchange(
                action: OrderRequestActionEnum::DEPOSIT_CALLBACK,
                url: $request->fullUrl(),
                request: $request->all(),
                response: $response?->response,
                result: $this->result,
                requestHeaders: $request->headers->all(),
                order: $this->loggedOrder,
                orderNo: (string) $request->input('merchant_ref'),
                thirdPartyOrderNo: (string) $request->input('transaction_id'),
                sourceIp: Common::getIp(),
                httpStatus: $response?->httpStatusCode,
            );
        }
    }

    private function process(Request $request): DepositCallbackResponseData
    {
        // 憑證未配置時 checkSign() 的 ApiKey 段為空、簽名可離線計算，
        // 攻擊者可偽造成功回調直接上分 ⇒ 先擋掉整個回調。
        // 對外沿用與簽名錯誤相同的例外，不洩露憑證未配置（真實原因由守衛記 log）。
        if ($this->findMissingCredentials(['ApiKey'], 'deposit callback') !== []) {
            $this->result = OrderRequestResultEnum::CREDENTIALS_NOT_CONFIGURED;

            throw new ApiException('Signature verification failed');
        }

        // 來源 IP 白名單（簽章以外的第二道防線，fail-closed，規則見 CallbackSourceGuardTrait）。
        //
        // 🔴 改動點（target/tickets#4449 需求 3）：原本讀 info['CallbackAllowIps'] 且**未設定就放行**，
        // 現改為讀 cash_flows.whitelist 且未設定一律拒絕。TngPin 的充值面額由回調決定、無法以
        // 訂單預期金額交叉驗證，放行一筆偽造回調就是直接上分，所以不保留 fail-open 的退路。
        // 上線前必須先把各站的 IP 配好，否則該站的 TngPin 回調會全部被擋（工單已另行提醒 PM）。
        $ip = Common::getIp();
        if (!$this->isCallbackSourceAllowed($ip, 'deposit callback')) {
            $this->result = OrderRequestResultEnum::IP_NOT_WHITELISTED;

            throw new ApiException('Callback source IP not allowed');
        }

        // 驗證請求參數格式
        $input = $request->validate([
            'status' => 'required',
            'amount' => 'required',
            'transaction_id' => 'required',
            // merchant_ref = 我方 order_no（送單時帶出、三方原樣回填，見 Deposit::buildDepositParams 與
            // checkSign 簽名公式）。用 sometimes 不強制：缺漏時退回以 transaction_id 匹配，不直接 422。
            'merchant_ref' => 'sometimes|string',
            'pin' => 'sometimes|string',
            'signature' => 'required',
        ]);

        // 驗證簽名
        if ($this->checkSign($request->all(), $this->info) === false) {
            $this->result = OrderRequestResultEnum::INVALID_SIGNATURE;

            throw new ApiException('Signature verification failed');
        }

        $transactionId = $input['transaction_id'];
        $merchantRef = (string) ($input['merchant_ref'] ?? '');
        $pin = (string) ($input['pin'] ?? '');

        try {
            DB::beginTransaction();

            // 三層匹配（merchant_ref → transaction_id → PIN 孤兒），細節與鎖定策略見該方法
            $order = $this->findAndLockOrderByCallbackKeys($merchantRef, $transactionId, $pin);

            $this->loggedOrder = $order;

            if (!$order) {
                Log::warning($this->getDriverName() . ' 訂單不存在 #' . $transactionId);
            } else {
                // third_party_order_no 此時只可能是空或真 txn（不再可能是 PIN），無需 mask 特判
                Log::withContext([
                    'order' => [
                        'order_no' => $order->order_no,
                        'third_party_order_no' => $order->third_party_order_no,
                        'cash_flow_id' => $order->cash_flow_id,
                        'agent_cash_flow_id' => $order->agent_cash_flow_id,
                        'currency_id' => $order->currency_id,
                    ],
                ]);
            }

            try {
                // 檢查訂單狀態：僅 status=0 (CREATED) 的訂單允許被回調修改
                $this->checkDepositOrderStatus($order);
            } catch (DepositOrderException $e) {
                DB::rollBack();

                $this->result = $order === null
                    ? OrderRequestResultEnum::ORDER_NOT_FOUND
                    : OrderRequestResultEnum::ALREADY_PROCESSED;

                return $this->handleDepositOrderException($e, $order);
            }

            // 孤兒回填 / 防誤綁——Phase 0 以 order_no 命中後，依訂單既有 third_party_order_no 分三路：
            // - 已綁同一 txn（Phase 1 命中、或 Phase 0 命中已寫 txn 的正常單）：不動。
            // - 為空（Phase 0 / Phase 2 命中的 timeout 孤兒）：回填為 callback 帶的真 transaction_id。
            // - 非空且為「不同 txn」：異常。order_no 唯一、Phase 0 不可能誤匹配，故這是「訂單已綁另一筆三方
            //   交易、callback 卻帶不同 txn」——不可改綁（會覆寫已記錄的三方交易）。記 error 並拋出讓交易
            //   rollback（外層 catch 會 BotNotify 告警），等人工查，不靜默改綁。
            $existingTxn = (string) ($order->third_party_order_no ?? '');
            if ($existingTxn === '') {
                Log::info($this->getDriverName() . ' callback 回填 third_party_order_no', [
                    'order_id' => $order->id,
                    'order_no' => $order->order_no,
                    'new_third_party_order_no' => $transactionId,
                ]);
                $order->third_party_order_no = $transactionId;
            } elseif ($existingTxn !== $transactionId) {
                Log::error($this->getDriverName() . ' callback transaction_id 與訂單已綁 txn 不符，拒絕改綁', [
                    'order_id' => $order->id,
                    'order_no' => $order->order_no,
                    'existing_third_party_order_no' => $existingTxn,
                    'callback_transaction_id' => $transactionId,
                ]);

                throw new ApiException('Order is already bound to a different transaction');
            }

            $status = (string) $input['status'];

            // status=3 (Processing)：三方仍在處理，維持訂單 CREATED 等待下一次 callback。
            // 不可落入下方 else 被標 FAILED(2)，否則後續真正成功的 callback 會被 checkDepositOrderStatus 以
            // 'Order already processed' 擋下 → 已扣款訂單不上分（對齊舊系統 callback 的 status=3 處理）。
            if ($status === '3') {
                Log::info($this->getDriverName() . ' callback 處理中 (status=3)，維持 CREATED 等待下次 callback', [
                    'order_id' => $order->id,
                    'order_no' => $order->order_no,
                ]);

                // third_party_order_no 上方已回填；此處記錄這次（遮罩後的）callback 內容
                $order->remark = Common::safeJsonEncode($this->maskSensitive($request->all()));
                $order->save();

                DB::commit();

                $this->result = OrderRequestResultEnum::NOT_FINALIZED;

                return DepositCallbackResponseData::from([
                    'response' => [
                        'status' => '0',
                        'message' => 'Processing',
                    ],
                    'order' => $order,
                ]);
            }

            // 訂單狀態判斷 - TngPin 特殊邏輯：需要計算 tax 和 point
            if ($status === '1') {
                $agentCashFlowOrderType = AgentCashFlowOrderType::query()
                    ->where('agent_cash_flow_id', $order->agent_cash_flow_id)
                    ->where('order_type_id', $order->order_type_id)
                    ->first();

                if (!$agentCashFlowOrderType) {
                    Log::error('AgentCashFlowOrderType not found', [
                        'agent_cash_flow_id' => $order->agent_cash_flow_id,
                        'order_type_id' => $order->order_type_id,
                    ]);
                    throw new Exception('AgentCashFlowOrderType not found');
                }

                // 三方回傳的 amount 為字串（如 "5.00"），進入運算前先正規化到 4 位精度，
                // 並一律以 Common 數值運算取代原生浮點 *（禁止原生浮點運算 / 外部 float 直接運算）。
                $amount = Common::numAdd('0', (string) $input['amount'], 4);
                $point = Common::numMul($amount, $this->agentCashFlow->rate, 4);
                $percentage = Common::numMul($point, $agentCashFlowOrderType->tax_percentage, 4);
                $tax = Common::numAdd($agentCashFlowOrderType->tax, $percentage); // 手續費
                $point = Common::numSub($point, $tax, 4); // 實際獲得點數
                // 更新點數與手續費
                $order->amount = $amount;
                $order->tax = $tax;
                $order->point = $point;
                // point 變了；payment_cost 在後續 updateMemberBalance 成功時依最終 point 寫入
                $order->status = 1;
            } else {
                // status=2 (Failed) 或其他未知狀態 → 標記為失敗（保留舊系統對未知狀態的 warning log）
                if ($status !== '2') {
                    Log::warning($this->getDriverName() . ' callback 未知狀態，標記為失敗', [
                        'order_id' => $order->id,
                        'order_no' => $order->order_no,
                        'callback_status' => $status,
                    ]);
                }
                $order->status = 2;
            }
            $order->remark = Common::safeJsonEncode($this->maskSensitive($request->all()));
            $order->save();

            DB::commit();

            $this->result = OrderRequestResultEnum::OK;

            return DepositCallbackResponseData::from([
                'response' => [
                    'status' => '0',
                    'message' => 'Success',
                ],
                'order' => $order,
            ]);
        } catch (Exception $e) {
            DB::rollBack();

            BotNotify::channel('error')->send($e->getMessage());

            throw $e;
        }
    }

    /**
     * 依回調帶的三個鍵逐層找單並鎖定，依鍵的可靠度與「何時被填」排序。
     *
     * 🔴 **只能在交易內呼叫**：三層都用 lockForUpdate()，鎖的生命週期靠呼叫端的交易撐著。
     * 三層皆限定 agent_cash_flow_id 防跨租戶撞號。
     *
     * Phase 0（主匹配）：merchant_ref = 我方 order_no。order_no 創單即存在且 unique 索引，無論訂單
     * 是否已拿到三方 txn 都能單列精準鎖定 → 同時涵蓋正常單與 timeout 孤兒，且不會踩無索引掃描的
     * lock wait timeout。這是大多數 callback 的命中層。
     *
     * Phase 1（後備）：transaction_id 精準匹配（third_party_order_no 語意 = transaction_id，代收成功
     * 才寫入）。涵蓋 (a) 在途舊單——其 merchant_ref 為固定商戶號而非 order_no，Phase 0 不命中；
     * (b) merchant_ref 缺漏的防呆。不限 status（讓重發 callback 走 checkDepositOrderStatus 的 200 冪等）。
     *
     * Phase 2（最後手段，窄域安全網，僅 timeout 孤兒）：Deposit sendRequest timeout 時三方可能已收
     * PIN 扣款、本地卻拿不到 txn（third_party_order_no 為空、PIN 仍存於 extra_content），且 merchant_ref
     * 與 transaction_id 皆對不上的極端情況才會落到這層（正常情況 Phase 0 已以 order_no 命中）。嚴格限
     * 「third_party_order_no 為空 + CREATED + 同 cashflow」再以 extra_content PIN 比對；已有真 txn 的訂單
     * 因 third_party_order_no 非空而不落入此分支 → 不覆蓋已歸屬訂單。多候選不自動歸屬（log error、等人工）。
     *
     * 鎖定策略（兩段式，避免寬範圍 FOR UPDATE）：extra_content->pin 無索引，若直接對此查詢掛
     * lockForUpdate()，InnoDB 會鎖住整段被掃描的列（該 cashflow 下所有 CREATED 訂單，REPEATABLE
     * READ 下還含 gap lock），與同商戶併發 callback 互卡造成 lock wait timeout（1205）。故先「無鎖」
     * 撈候選 id，命中單筆後僅以主鍵精準鎖定該列，再重新驗證窄域條件防競態。
     *
     * Known limitations（皆限本 PIN 路徑、且本路徑僅在 merchant_ref 與 transaction_id 皆 miss 的 legacy
     * 孤兒才觸發；有 merchant_ref 的當前情境由 Phase 0/1 處理，同一 txn 重送走 order_no/txn 命中既有終態
     * → 冪等 200，不同 txn 則由呼叫端的回填防誤綁分支以 conflict 例外擋下）：
     * (1) 併發重送時若候選已被另一交易處理成終態，重新驗證落空 → 回失敗（非冪等）；三方 retry 後由
     *     Phase 0/1 命中既有終態訂單轉為冪等 200，自我修復。
     * (2) 「無鎖撈候選」與「主鍵鎖定」之間若新增同 PIN orphan，多候選 guard 為 snapshot，無法保證鎖後
     *     仍唯一；同 cashflow 同 PIN 並發雙孤兒屬極端，沿用 fail-closed（多候選不自動歸屬）。
     */
    private function findAndLockOrderByCallbackKeys(string $merchantRef, string $transactionId, string $pin): ?Order
    {
        if ($merchantRef !== '') {
            $order = Order::query()
                ->where('agent_cash_flow_id', $this->agentCashFlow->id)
                ->where('order_no', $merchantRef)
                ->lockForUpdate()
                ->first();

            if ($order) {
                return $order;
            }
        }

        $order = Order::query()
            ->where('agent_cash_flow_id', $this->agentCashFlow->id)
            ->where('third_party_order_no', $transactionId)
            ->lockForUpdate()
            ->first();

        if ($order || $pin === '') {
            return $order;
        }

        $candidateIds = $this->findOrphanCandidateIds($pin);

        if ($candidateIds->count() > 1) {
            Log::error($this->getDriverName() . ' callback PIN fallback 多筆候選，拒絕自動歸屬', [
                'pin' => $this->maskPin($pin),
                'transaction_id' => $transactionId,
                'candidate_count' => $candidateIds->count(),
                'candidate_order_ids' => $candidateIds->all(),
            ]);

            return null;
        }

        if ($candidateIds->isEmpty()) {
            return null;
        }

        // 以主鍵鎖定命中的單列後重新驗證：若另一併發交易已先處理（回填 txn / 改狀態），
        // 窄域條件不再成立 → first() 回 null，呼叫端照「訂單不存在」處理，不自動歸屬，
        // 由三方續 retry callback。
        return Order::query()
            ->where('id', $candidateIds->first())
            ->where('agent_cash_flow_id', $this->agentCashFlow->id)
            ->where('status', OrderStatusEnum::CREATED->value)
            ->where(function ($query) {
                $query->where('third_party_order_no', '')
                    ->orWhereNull('third_party_order_no');
            })
            ->where('extra_content->pin', $pin)
            ->lockForUpdate()
            ->first();
    }

    /**
     * 查詢 timeout 孤兒候選訂單 id（刻意不加鎖）。
     *
     * 窄域條件：同 cashflow + CREATED + third_party_order_no 為空 + extra_content PIN 相符。
     * 不掛 lockForUpdate()——extra_content->pin 無索引，FOR UPDATE 會鎖住整段掃描範圍造成併發 callback
     * 互卡；改由呼叫端以主鍵精準鎖定命中的單列並重新驗證。抽成獨立方法亦便於測試覆蓋鎖定後競態落空的分支。
     *
     * @return Collection<int, int>
     */
    protected function findOrphanCandidateIds(string $pin): Collection
    {
        return Order::query()
            ->where('agent_cash_flow_id', $this->agentCashFlow->id)
            ->where('status', OrderStatusEnum::CREATED->value)
            ->where(function ($query) {
                $query->where('third_party_order_no', '')
                    ->orWhereNull('third_party_order_no');
            })
            ->where('extra_content->pin', $pin)
            ->pluck('id');
    }
}
