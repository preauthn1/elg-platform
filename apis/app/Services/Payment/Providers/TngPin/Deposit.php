<?php

declare(strict_types=1);

namespace App\Services\Payment\Providers\TngPin;

use App\Enums\DepositResponseType;
use App\Enums\General\CacheTtl;
use App\Enums\General\ContextKeyEnum;
use App\Enums\General\HttpCode;
use App\Enums\OrderStatusEnum;
use App\Enums\TngPinResponseCode;
use App\Exceptions\ApiException;
use App\Facades\BotNotify;
use App\Logging\HttpLoggerCustom;
use App\Models\AgentCashFlow;
use App\Models\Order;
use App\Services\Payment\AbstractMethod;
use App\Services\Payment\Data\DepositRequestData;
use App\Services\Payment\Data\DepositResponseData;
use App\Services\Payment\Enums\OrderRequestActionEnum;
use App\Services\Payment\Enums\OrderRequestResultEnum;
use Exception;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;

class Deposit extends AbstractMethod
{
    use TngPinTrait;

    protected AgentCashFlow $agentCashFlow;

    protected array $info;

    public function __construct(AgentCashFlow $agentCashFlow)
    {
        $this->agentCashFlow = $agentCashFlow;
        $this->setInfo($agentCashFlow->info);
    }

    public function handle(DepositRequestData $request): DepositResponseData
    {
        $order = $request->order;

        // 渠道
        $telco = $order->orderType->key;

        // PIN 碼驗證與提取
        $pin = $this->extractPinFromOrder($order);

        $params = $this->buildDepositParams($order, $telco, $pin);
        $maskedPin = $this->maskPin($pin);

        // in-flight 並發鎖（取代 reservePinSlot 的並發保護）：Telco PIN 不可逆，須防同 PIN 並發雙送到三方。
        // key 用 sha256(pin) 不放原文（憑證不得進 cache key / log）；含 agent_cash_flow_id 防跨租戶誤擋。
        // TTL 為 crash 安全網；定案（成功 / 4xx 失敗）即釋放，timeout 模糊不釋放、留 TTL 擋模糊期重送雙扣。
        $lock = Cache::lock(
            'tngpin:inflight:' . $this->agentCashFlow->id . ':' . hash('sha256', $pin),
            CacheTtl::DEFAULT->value
        );

        if (!$lock->get()) {
            throw new ApiException(
                'A submission with this PIN is already in progress.',
                HttpCode::HTTP_LOCKED->value
            );
        }

        $url = rtrim((string) $this->info['APIUrl'], '/') . '/submitPIN';

        // HTTP 狀態與對端 IP 取的是「最後一次出站」，送出前先清掉殘留（見 HttpLoggerCustom）
        HttpLoggerCustom::forgetLastOutbound();

        try {
            $result = $this->sendRequest('/submitPIN', $params);
        } catch (Exception $e) {
            // 🔴 送 PIN 失敗分兩種，兩種都要留紀錄：確定性拒絕訂單會被標 FAILED，
            // 模糊失敗訂單停在 CREATED、三方可能已扣款，後者正是事後最需要查的那種。
            // ⚠️ $params 含 PIN（支付憑證），本表存原文不遮罩，禁止經 API／後台暴露（見 migration 註解）。
            $this->logExchange(
                action: OrderRequestActionEnum::DEPOSIT_CREATE,
                url: $url,
                request: $params,
                response: $e->getMessage(),
                result: OrderRequestResultEnum::EXCEPTION,
                order: $order,
                sourceIp: HttpLoggerCustom::lastOutboundRemoteIp(),
                httpStatus: $this->httpStatusOf($e) ?? HttpLoggerCustom::lastOutboundHttpStatus(),
            );

            // sendRequest 拋例外代表沒拿到三方同步回應：
            // - HTTP_BAD_REQUEST(4xx 非 408) → 三方確定性拒絕，標 FAILED + 釋放鎖讓玩家換 PIN 重試。
            // - timeout / 5xx / 未分類 → 模糊失敗，三方可能已收 PIN 扣款，保留 CREATED + 告警人工確認。
            //   不釋放鎖、留 TTL 擋模糊期同 PIN 重送雙扣；third_party_order_no 維持空，若三方稍後 callback，
            //   DepositCallback 窄域 Phase 2 以 extra_content PIN 反查孤兒並回填真 transaction_id 自動對帳。
            $isDefinitive = $e instanceof ApiException && $e->getCode() === HttpCode::HTTP_BAD_REQUEST->value;

            if ($isDefinitive) {
                $order->update(['status' => OrderStatusEnum::FAILED->value]);
                $lock->release();
                Log::error('TngPin 三方確定性拒絕(HTTP 4xx)，訂單標 FAILED', [
                    'order_id' => $order->id,
                    'order_no' => $order->order_no,
                    'pin' => $maskedPin,
                    'error' => $e->getMessage(),
                ]);
            } else {
                Log::error('TngPin 送出 PIN 後模糊失敗，訂單保留 CREATED 等待人工確認', [
                    'order_id' => $order->id,
                    'order_no' => $order->order_no,
                    'pin' => $maskedPin,
                    'error' => $e->getMessage(),
                ]);
                BotNotify::channel('error')->send(sprintf(
                    'TngPin 送 PIN 後模糊失敗，需人工確認三方狀態：order_no=%s, pin=%s, error=%s',
                    $order->order_no, $maskedPin, $e->getMessage()
                ));
            }
            throw $e;
        }

        // 拿到同步回應不等於這筆成功：result=error 與 status=2 都是三方判失敗（見 finalizeSyncResult()），
        // 一律記 OK 的話，事後從這張表看三方拒絕跟正常受理長得一樣。
        $rejected = ($result['result'] ?? '') !== 'success' || ($result['status'] ?? '') === '2';

        $this->logExchange(
            action: OrderRequestActionEnum::DEPOSIT_CREATE,
            url: $url,
            request: $params,
            response: $result,
            result: $rejected ? OrderRequestResultEnum::PROVIDER_REJECTED : OrderRequestResultEnum::OK,
            order: $order,
            thirdPartyOrderNo: (string) ($result['transaction_id'] ?? '') ?: null,
            sourceIp: HttpLoggerCustom::lastOutboundRemoteIp(),
            httpStatus: HttpLoggerCustom::lastOutboundHttpStatus(),
        );

        // 紀錄交易資訊（success 形狀帶 transaction_id，寫入 third_party_order_no）
        $this->recordTransactionInfo($order, $params, $result);

        // 同步終態判定：result=error → FAILED（§7 gate）；status=2 與其餘 success 維持 CREATED 交 callback
        $this->finalizeSyncResult($order, $result, $maskedPin);

        // 有同步回應（成功形狀已定案）→ 釋放鎖
        $lock->release();

        return $this->processDepositResponse($order, $result);
    }

    /**
     * 從訂單中提取 PIN 碼
     */
    private function extractPinFromOrder($order): string
    {
        // extra_content 可能為 null/非字串/非合法 JSON，需防呆避免 json_decode() TypeError
        $extraRaw = $order->extra_content;
        $extraJson = is_string($extraRaw) ? $extraRaw : ($extraRaw === null ? '' : (string) $extraRaw);

        $extraContent = [];
        if ($extraJson !== '') {
            try {
                $decoded = json_decode($extraJson, true, 512, JSON_THROW_ON_ERROR);
                $extraContent = is_array($decoded) ? $decoded : [];
            } catch (\JsonException $e) {
                // 輕量日誌：只記錄必要欄位，避免把敏感內容寫進 log
                Log::warning('TngPin 充值：extra_content 不是合法 JSON', [
                    'order_no' => $order->order_no ?? null,
                    'order_id' => $order->id ?? null,
                    'agent_cash_flow_id' => $order->agent_cash_flow_id ?? null,
                    'error' => $e->getMessage(),
                ]);
                $extraContent = [];
            }
        }

        $pin = (string) ($extraContent['pin'] ?? '');

        if ($pin === '') {
            Log::warning('TngPin 充值：訂單缺少 PIN 碼', [
                'order_no' => $order->order_no ?? null,
                'order_id' => $order->id ?? null,
                'agent_cash_flow_id' => $order->agent_cash_flow_id ?? null,
            ]);

            throw new ApiException('The order information is missing a PIN code.');
        }

        return $pin;
    }

    /**
     * 構建代收請求參數
     */
    private function buildDepositParams($order, string $telco, string $pin): array
    {
        return [
            'payment_key' => $this->info['ApiKey'],
            'payment_pass' => $this->info['ApiPass'],
            'telco' => $telco,
            'pin' => $pin,
            // merchant_ref 送每單唯一的 order_no（對齊舊系統 / 工單 #3865）：送固定商戶號會在三方開啟
            // 「Unique Merchant Reference」時被當重複送單拒絕，且簽章失去逐單唯一性。checkSign 取回傳值無需改。
            'merchant_ref' => $order->order_no,
            'ip_address' => $order->ip,
            // wait_result=false（boolean，對齊舊系統 / README 建議）：true 會同步等結果易超時；
            // false 立即回 status=3 並由背景 callback 回報最終狀態，避免長連線超時造成孤兒訂單。
            'wait_result' => false,
        ];
    }

    /**
     * 依三方同步回應形狀終結訂單狀態（§7 FAILED 硬 gate，已由官方文檔逐碼背書）。
     *
     * - result=error（無 status、無 transaction_id）：三方未受理，絕對失敗、永不 callback → FAILED
     *   （gate：官方文檔「validation/auth errors」不送 callback + production 證據 7077/0；PIN 未受理不扣款）。
     * - result=success + status=2（必帶 transaction_id）：官方文檔「Failed — terminal, Triggers Callback」、
     *   status 模型中僅 status=3 為非終態、無翻成 status=1 路徑 → 同步即標 FAILED + 寫 txn（不留 CREATED 孤兒）。
     *   遲到的 status=2 callback 由 checkDepositOrderStatus(CREATED-only) 以 200 冪等擋下。
     * - result=success + status=1/3/其他（帶 transaction_id）：成功待入帳 / 處理中 → 維持 CREATED 等 callback。
     *
     * transaction_id 已由 recordTransactionInfo 寫入 third_party_order_no（success 形狀必有）。
     */
    private function finalizeSyncResult(Order $order, array $result, string $maskedPin): void
    {
        // result != success（含無 status 的 PIN 格式錯）→ 絕對失敗（§7 gate：官方文檔證實 error 不 callback）
        if (($result['result'] ?? '') !== 'success') {
            $order->update(['status' => OrderStatusEnum::FAILED->value]);
            Log::error('TngPin 三方同步回 result=error，訂單標 FAILED', [
                'order_id' => $order->id,
                'order_no' => $order->order_no,
                'pin' => $maskedPin,
                'message' => $result['message'] ?? '',
            ]);

            return;
        }

        // result=success + status=2 → 官方文檔證實為終態失敗 → 同步即標 FAILED（txn 已寫入供遲到 callback 冪等命中）
        if (($result['status'] ?? '') === '2') {
            $order->update(['status' => OrderStatusEnum::FAILED->value]);
            Log::error('TngPin 三方同步回 status=2 交易失敗，訂單標 FAILED', [
                'order_id' => $order->id,
                'order_no' => $order->order_no,
                'pin' => $maskedPin,
                'message' => $result['detail_message'] ?? $result['message'] ?? '',
            ]);

            return;
        }

        // result=success + status=1/3/其他 → 成功待入帳 / 處理中，維持 CREATED 等 callback
    }

    /**
     * 紀錄交易資訊
     */
    private function recordTransactionInfo($order, array $params, array $result): void
    {
        $transactionId = $result['transaction_id'] ?? '';
        $traceId = Context::getHidden(ContextKeyEnum::REQUEST_ID->value);
        $updates = [
            'remark' => json_encode([
                'transaction_id' => $transactionId,
                'trace_id' => $traceId,
                // 寫入 DB 前遮罩 payment_key/payment_pass/pin，避免支付憑證以明文留存於 remark
                'request' => $this->maskSensitive($params),
                'response' => $this->maskSensitive($result),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ];
        // 三方有回 transaction_id 才寫入 third_party_order_no；沒回則維持空值（PIN 占位已移除），
        // 留待後續 callback 以 merchant_ref(order_no) / extra_content PIN 反查孤兒並回填真 txn。
        if ($transactionId !== '') {
            $updates['third_party_order_no'] = $transactionId;
        }
        $order->update($updates);
    }

    /**
     * 處理代收響應 — 僅決定回前端的提示（type/code/message），絕不變更訂單狀態或入帳。
     *
     * TngPin 的訂單狀態與點數一律由三方回調（DepositCallback）決定：避免「同步路徑」與「回調路徑」
     * 對同一筆訂單做出不一致結果（例如同步搶先入帳、回調又重複處理）。wait_result=false 下三方同步
     * 通常只回 status=3（處理中），最終成功/失敗一律由 callback 補；故此處一律維持訂單 CREATED。
     */
    private function processDepositResponse($order, array $result): DepositResponseData
    {
        // 三方同步即回非 success（驗證/授權錯誤，如 Invalid Telco）→ 停留原頁顯示錯誤
        if (($result['result'] ?? '') !== 'success') {
            return DepositResponseData::from([
                'type' => DepositResponseType::ERROR,
                'orderNo' => $order->order_no,
                'code' => TngPinResponseCode::STAY_ON_PAGE,
                'message' => $result['message'] ?? 'Unknown error',
            ]);
        }

        // 三方同步即判失敗（status=2，如 Invalid PIN）→ 停留原頁顯示錯誤。
        // 本方法只組前端回應、不改訂單狀態；FAILED 終態由 finalizeSyncResult() 於同步階段標記
        // （status=2 已寫 txn，遲到的 status=2 callback 再以 checkDepositOrderStatus 冪等擋下）。
        if (($result['status'] ?? '') === '2') {
            return DepositResponseData::from([
                'type' => DepositResponseType::ERROR,
                'orderNo' => $order->order_no,
                'code' => TngPinResponseCode::STAY_ON_PAGE,
                'message' => $result['detail_message'] ?? $result['message'] ?? 'Transaction failed',
            ]);
        }

        // 其餘（status=3 處理中 / status=1 同步成功但仍待回調入帳 / 其他）：
        // 訂單維持 CREATED 等回調決定，前端回到付款頁顯示處理中訊息。
        return DepositResponseData::from([
            'type' => DepositResponseType::ERROR,
            'orderNo' => $order->order_no,
            'code' => TngPinResponseCode::RETURN_TO_PAYMENT,
            'message' => $result['detail_message'] ?? $result['message'] ?? 'Processing',
        ]);
    }
}
