<?php

declare(strict_types=1);

namespace App\Services\Payment\Providers\B3Wallet;

use App\Enums\DepositResponseType;
use App\Logging\HttpLoggerCustom;
use App\Models\AgentCashFlow;
use App\Services\Payment\AbstractMethod;
use App\Services\Payment\Data\DepositRequestData;
use App\Services\Payment\Data\DepositResponseData;
use App\Services\Payment\Enums\OrderRequestActionEnum;
use App\Services\Payment\Enums\OrderRequestResultEnum;
use Throwable;

/**
 * @knowledge rules/provider-b3wallet
 */
class Deposit extends AbstractMethod
{
    use B3WalletTrait;

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

        $params = $this->buildDepositParams($request);

        $url = rtrim($this->info[$this->apiUrlField], '/') . '/api/v1/bills/create';

        // HTTP 狀態與對端 IP 取的是「最後一次出站」，送出前先清掉殘留（見 HttpLoggerCustom）
        HttpLoggerCustom::forgetLastOutbound();

        // 非 2xx（含 AMOUNT_BELOW_MIN / AMOUNT_ABOVE_MAX / AMOUNT_LIMITS_NOT_DEFINED_FOR_CURRENCY 等）
        // 由 sendRequest 的 ->throw() 轉為 ApiException，此處僅需處理成功案例
        try {
            $result = $this->sendRequest(
                'POST',
                '/api/v1/bills/create',
                $params,
                ['Idempotency-Key' => $order->order_no]
            );
        } catch (Throwable $e) {
            // sendRequest 把 RequestException 包成 ApiException，錯誤碼與 HTTP 狀態要從原例外取。
            // 本家 4xx 的碼在 error.code（實測 NO_AVAILABLE_PAYMENT_ACCOUNT），取不到才退回 exception
            $origin = $e->getPrevious() ?? $e;

            $this->logExchange(
                action: OrderRequestActionEnum::DEPOSIT_CREATE,
                url: $url,
                request: $params,
                response: $e->getMessage(),
                result: $this->providerFailureOf($origin, 'error.code', 'Error'),
                order: $order,
                sourceIp: HttpLoggerCustom::lastOutboundRemoteIp(),
                httpStatus: $this->httpStatusOf($origin) ?? HttpLoggerCustom::lastOutboundHttpStatus(),
            );

            throw $e;
        }

        // 🔴 不傳 requestHeaders：本家的 Authorization 是固定的 PAYMENT_KEY:PAYMENT_PASS，
        // 往返紀錄表存原文不遮罩，記進去等於把商戶帳密明文寫進 DB
        $this->logExchange(
            action: OrderRequestActionEnum::DEPOSIT_CREATE,
            url: $url,
            request: $params,
            response: $result,
            result: OrderRequestResultEnum::OK,
            order: $order,
            thirdPartyOrderNo: (string) ($result['BillId'] ?? '') ?: null,
            sourceIp: HttpLoggerCustom::lastOutboundRemoteIp(),
            httpStatus: HttpLoggerCustom::lastOutboundHttpStatus(),
        );

        $order->update(['third_party_order_no' => $result['BillId'] ?? '']);

        return DepositResponseData::from([
            'type' => DepositResponseType::URL_REDIRECT,
            'url' => $result['BillUrl'] ?? '',
            'html' => null,
            'orderNo' => $order->order_no,
            'providerOrderNo' => $result['BillId'] ?? '',
        ]);
    }

    /**
     * 構建代收請求參數
     *
     * 收款方式跟著會員所選的支付通道走：order_types.key 即三方 `DepositInstruction`
     * （`PAYNOW` / `BANK_TRANSFER` / `CRYPTO`），鏈上收款再以 `.` 後綴帶鏈別
     * （`CRYPTO.TRON`），通道藍圖見 Data/providers/B3Wallet.php。
     *
     * 三方「新接入必傳 DepositInstruction」，故 key 缺失時退回 PAYNOW 而非不傳。
     */
    private function buildDepositParams(DepositRequestData $request): array
    {
        $order = $request->order;

        [$instruction, $chain] = array_pad(
            explode('.', (string) ($order->orderType->key ?? 'PAYNOW'), 2),
            2,
            null
        );

        $params = [
            'ShopName' => $this->agentCashFlow->name,
            'ProductName' => 'Deposit',
            'Amount' => (float) $order->amount,
            'DepositInstruction' => $instruction,
            'Currency' => $this->agentCashFlow->currency->code,
            'Param' => $order->order_no,
            'CallbackUrl' => $request->callbackUrl,
            'RedirectUrl' => $request->returnUrl,
        ];

        // Chain 僅 DepositInstruction=CRYPTO 時有效，其餘方式傳了會被三方以 400 擋下
        if ($chain !== null) {
            $params['Chain'] = $chain;
        }

        return $params;
    }
}
