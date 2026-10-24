<?php

declare(strict_types=1);

namespace App\Services\Payment\Providers\Ap8Pay;

use App\Enums\DepositResponseType;
use App\Exceptions\ApiException;
use App\Logging\HttpLoggerCustom;
use App\Models\AgentCashFlow;
use App\Services\Payment\AbstractMethod;
use App\Services\Payment\Data\DepositRequestData;
use App\Services\Payment\Data\DepositResponseData;
use App\Services\Payment\Enums\OrderRequestActionEnum;
use App\Services\Payment\Enums\OrderRequestResultEnum;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class Deposit extends AbstractMethod
{
    use Ap8PayTrait;

    protected AgentCashFlow $agentCashFlow;

    public function __construct(AgentCashFlow $agentCashFlow)
    {
        $this->agentCashFlow = $agentCashFlow;
        $this->setInfo($agentCashFlow->info);
    }

    public function handle(DepositRequestData $request): DepositResponseData
    {
        $order = $request->order;
        $apiUrl = rtrim($this->info['APIUrl'], '/');
        $url = $apiUrl . '/merchants/deposits';

        $params = $this->buildDepositParams($request);
        $headers = $this->buildDepositHeaders();

        // HTTP 狀態與對端 IP 取的是「最後一次出站」，送出前先清掉殘留（見 HttpLoggerCustom）
        HttpLoggerCustom::forgetLastOutbound();

        try {
            $result = $this->sendRequest($url, $params, $headers);
        } catch (Throwable $e) {
            // 連不上或三方回非 2xx：留下往返紀錄再往外拋，否則這次失敗在 DB 完全沒痕跡。
            // 本家的錯誤碼在 error.type（與查單同一組，見 QueryOrder），非 2xx 時照樣取得到。
            $this->logExchange(
                action: OrderRequestActionEnum::DEPOSIT_CREATE,
                url: $url,
                request: $params,
                requestHeaders: $headers,
                response: $e->getMessage(),
                result: $this->providerFailureOf($e, 'error.type'),
                order: $order,
                sourceIp: HttpLoggerCustom::lastOutboundRemoteIp(),
                httpStatus: $this->httpStatusOf($e) ?? HttpLoggerCustom::lastOutboundHttpStatus(),
            );

            throw $e;
        }

        // 檢查響應數據 - 嚴格判斷：必須有 data.url
        if (!isset($result['data']['url'])) {
            $this->logExchange(
                action: OrderRequestActionEnum::DEPOSIT_CREATE,
                url: $url,
                request: $params,
                requestHeaders: $headers,
                response: $result,
                result: OrderRequestResultEnum::NO_URL,
                order: $order,
                sourceIp: HttpLoggerCustom::lastOutboundRemoteIp(),
                httpStatus: HttpLoggerCustom::lastOutboundHttpStatus(),
            );

            throw new ApiException($this->getDriverName() . ' deposit error: url not found');
        }

        // 🔴 data.code 是三方 taskCode，**必須**在此落庫（target/tickets#4446）。
        // 在我方與三方之間產生、送回調的人碰不到，所以只有它能當反查（{@see QueryOrder}）的
        // 查詢鍵；回調無簽名，改拿回調 body 的 code 去查，等於讓被驗證的一方自己挑驗證材料。
        // 本欄位原本要等回調才寫（{@see \App\Services\Payment\Traits\DepositCallbackTrait::updateDepositOrderSuccess()}），
        // 建單時是空字串（{@see \App\Services\OrderService::createOrder()}）。
        //
        // 官方 spec 把 code 列在建單回應的 required 內，缺了屬三方異常；此處仍不拋例外，
        // 擋下去玩家就付不了款，改記 warning——該單之後只能退回用回調的 code 查，少一層防偽。
        $taskCode = (string) ($result['data']['code'] ?? '');

        $this->logExchange(
            action: OrderRequestActionEnum::DEPOSIT_CREATE,
            url: $url,
            request: $params,
            requestHeaders: $headers,
            response: $result,
            result: OrderRequestResultEnum::OK,
            order: $order,
            thirdPartyOrderNo: $taskCode !== '' ? $taskCode : null,
            sourceIp: HttpLoggerCustom::lastOutboundRemoteIp(),
            httpStatus: HttpLoggerCustom::lastOutboundHttpStatus(),
        );

        if ($taskCode === '') {
            Log::warning($this->getDriverName() . ' deposit response missing data.code (taskCode)', [
                'order_no' => $order->order_no,
            ]);
        } else {
            $order->update(['third_party_order_no' => $taskCode]);
        }

        return DepositResponseData::from([
            'type' => DepositResponseType::URL_REDIRECT,
            'url' => $result['data']['url'],
            'orderNo' => $order->order_no,
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

        // 🔴 **刻意不送 amount**：spec 有這個選填欄位，但 2026-10-05 在 Dev 以測試商戶實測，
        // 帶了之後三方收銀台的金額輸入框仍是空的（連預填都沒有），玩家照樣輸入任意金額。
        // 也就是這個參數對 AP8 完全沒作用，送了只會讓後人誤以為我方約束得住金額。
        //
        // 連帶結論：本渠道**不存在「下單金額」這個概念**，金額一律由玩家在三方頁面決定、
        // 回調帶回來。要防大額只能在收到金額之後把關，不能在建單時把關（target/tickets#4446 §3）。
        return [
            'apiKey' => $this->info['MerchantApiKey'],
            'uniqueCode' => $order->order_no,
            'senderName' => $member->username,
            'senderCode' => (string) $member->id,
            'callbackUrl' => $callbackUrl,
            'redirectUrl' => $returnUrl,
        ];
    }

    /**
     * 構建代收請求頭
     */
    private function buildDepositHeaders(): array
    {
        return [
            'api-key' => $this->info['HeaderApiKey'],
        ];
    }

    protected function sendRequest(string $url, array $params, array $headers): array
    {
        $result = Http::logAction('Ap8Pay_Deposit')
            ->withHeaders($headers)
            ->post($url, $params)
            ->throw()
            ->json();

        return $result;
    }
}
