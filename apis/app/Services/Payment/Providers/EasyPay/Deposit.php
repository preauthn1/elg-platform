<?php

declare(strict_types=1);

namespace App\Services\Payment\Providers\EasyPay;

use App\Enums\DepositResponseType;
use App\Exceptions\ApiException;
use App\Helpers\Common;
use App\Logging\HttpLoggerCustom;
use App\Models\AgentCashFlow;
use App\Services\Payment\AbstractMethod;
use App\Services\Payment\Data\DepositRequestData;
use App\Services\Payment\Data\DepositResponseData;
use App\Services\Payment\Enums\OrderRequestActionEnum;
use App\Services\Payment\Enums\OrderRequestResultEnum;
use Illuminate\Support\Facades\Log;
use Throwable;

class Deposit extends AbstractMethod
{
    use EasyPayTrait;

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

        // 支付渠道：paynow / bank_order
        $paymentChannel = $order->orderType->key ?? '';

        $params = $this->buildDepositParams($request);
        $path = '/deposit/' . $paymentChannel;
        $url = rtrim((string) $this->info[$this->apiUrl], '/') . $path;

        // HTTP 狀態與對端 IP 取的是「最後一次出站」，送出前先清掉殘留（見 HttpLoggerCustom）
        HttpLoggerCustom::forgetLastOutbound();

        try {
            $result = $this->sendRequest($path, $params);
        } catch (Throwable $e) {
            // 到得了這裡的只剩「三方沒給出可解讀的業務信封」：連不上、逾時、502 的 HTML 錯誤頁。
            // 帶得出碼的失敗（含 HTTP 400 的 SERVICE_UNDER_MAINTENANCE）走下面 status !== 1 那段。
            // httpStatus 優先取例外帶出來的狀態；連線層斷掉時沒有 response，才退回 macro 記下的那次。
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

            throw $e;
        }

        // 檢查響應狀態 - 嚴格判斷：必須 status === 1 才視為成功。
        // 本家用 HTTP 400 送業務錯誤，sendRequest() 不再把非 2xx 轉成例外，所以那些也落在這裡，
        // result 記得到三方的碼（SERVICE_UNDER_MAINTENANCE 等）而不是 HTTP 原文。
        if (!isset($result['status']) || $result['status'] !== 1) {
            $errorMessage = $result['code'] ?? 'Unknown error';
            Log::error($this->getDriverName() . ' deposit API error', [
                'response' => $result,
            ]);

            $this->logExchange(
                action: OrderRequestActionEnum::DEPOSIT_CREATE,
                url: $url,
                request: $params,
                response: $result,
                result: OrderRequestResultEnum::providerFailure((string) $errorMessage),
                order: $order,
                sourceIp: HttpLoggerCustom::lastOutboundRemoteIp(),
                httpStatus: HttpLoggerCustom::lastOutboundHttpStatus(),
            );

            throw new ApiException($this->getDriverName() . ' deposit error: ' . $errorMessage);
        }

        $data = $result['data'] ?? [];

        $this->logExchange(
            action: OrderRequestActionEnum::DEPOSIT_CREATE,
            url: $url,
            request: $params,
            response: $result,
            result: OrderRequestResultEnum::OK,
            order: $order,
            thirdPartyOrderNo: (string) ($data['order_id'] ?? '') ?: null,
            sourceIp: HttpLoggerCustom::lastOutboundRemoteIp(),
            httpStatus: HttpLoggerCustom::lastOutboundHttpStatus(),
        );

        // 紀錄三方訂單號
        $order->update([
            'third_party_order_no' => $data['order_id'],
        ]);

        return DepositResponseData::from([
            'type' => DepositResponseType::URL_REDIRECT,
            'url' => $data['payment_details']['redirect_url'] ? urldecode($data['payment_details']['redirect_url']) : '',
            'orderNo' => $order->order_no,
            'providerOrderNo' => $data['order_id'] ?: '',
        ]);
    }

    /**
     * 構建代收請求參數
     */
    private function buildDepositParams(DepositRequestData $request): array
    {
        $member = $request->member;
        $order = $request->order;
        $callbackUrl = $request->callbackUrl;
        $returnUrl = $request->returnUrl;

        $params = [
            'amount' => Common::numFloor($order->amount, 2),
            'tx_id' => $order->order_no,
            'transferror_name' => $member->username,
            'callback_url' => $callbackUrl,
            'redirect_url' => $returnUrl,
        ];

        // 代收 bank_order 與 kuaizhuan_order 參與簽名的欄位相同，且**不含必填的 transferror_name**
        return $this->signParams($params, ['amount', 'tx_id', 'callback_url', 'redirect_url']);
    }
}
