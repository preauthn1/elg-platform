<?php

declare(strict_types=1);

namespace App\Services\Payment\Providers\Ap8Pay;

use App\Exceptions\ApiException;
use App\Helpers\Common;
use App\Models\AgentCashFlow;
use App\Models\Order;
use App\Services\Payment\AbstractMethod;
use App\Services\Payment\Data\QueryOrderRequestData;
use App\Services\Payment\Data\QueryOrderResponseData;
use App\Services\Payment\Enums\OrderRequestActionEnum;
use App\Services\Payment\Enums\OrderRequestResultEnum;
use App\Services\Payment\Enums\ProviderOrderStatusEnum;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Ap8Pay 代收查單：GET {APIUrl}/merchants/deposits/{taskCode}
 *
 * 官方規格 https://developer.autopay.dev/membership/v1/ （openapi `getDeposit`）。
 *
 * 🔴 taskCode 是建單響應 `data.code`，由 Deposit 建單當下寫進 orders.third_party_order_no；
 * 本類只認 taskCode，**沒有以商戶訂單號查單的端點**，taskCode 為空時直接拋例外，
 * 禁止改用 order_no 去兜 URL（三方會回 404，會被誤讀成「查無此單」）。
 *
 * 查詢屬唯讀，禁止在本類內改動訂單（{@see \App\Services\Payment\Contracts\SupportsDepositQuery}）。
 */
class QueryOrder extends AbstractMethod
{
    use Ap8PayTrait;

    public function __construct(AgentCashFlow $agentCashFlow)
    {
        $this->agentCashFlow = $agentCashFlow;
        $this->setInfo($agentCashFlow->info);
    }

    public function handle(QueryOrderRequestData $data): QueryOrderResponseData
    {
        $order = $data->order instanceof Order ? $data->order : null;

        return $this->fetch((string) $data->order->third_party_order_no, $order);
    }

    /**
     * 以三方 taskCode 查單。
     *
     * 回傳的 orderNo 是三方記載的商戶訂單號（`data.uniqueCode`），呼叫端必須自行比對
     * 是否等於我方訂單號——taskCode 來源若不可信（例如取自回調 body），只有這道比對
     * 能擋掉「拿別單的 taskCode 換別單狀態」。
     *
     * @throws ApiException taskCode 為空、三方連線失敗或信封缺 data 時拋出，不回半套資料
     */
    public function fetch(string $taskCode, ?Order $order = null): QueryOrderResponseData
    {
        if ($taskCode === '') {
            throw new ApiException($this->getDriverName() . ' query error: taskCode is empty');
        }

        $apiUrl = rtrim($this->info['APIUrl'], '/');
        $url = $apiUrl . '/merchants/deposits/' . rawurlencode($taskCode);
        $headers = $this->buildHeaders();

        try {
            $result = $this->sendRequest($url);
        } catch (Throwable $e) {
            // 三方回 404（查無此單，實測舊單都是）或連不上：留下紀錄再往外拋
            $this->logExchange(
                action: OrderRequestActionEnum::DEPOSIT_QUERY,
                url: $url,
                request: null,
                response: $e instanceof RequestException ? $e->response->body() : $e->getMessage(),
                result: $this->providerFailureOf($e, 'error.type'),
                order: $order,
                thirdPartyOrderNo: $taskCode,
                requestHeaders: $headers,
                httpStatus: $this->httpStatusOf($e),
            );

            throw $e;
        }

        $data = $result['data'] ?? null;
        if (!is_array($data) || !isset($data['uniqueCode'], $data['status'])) {
            $this->logExchange(
                action: OrderRequestActionEnum::DEPOSIT_QUERY,
                url: $url,
                request: null,
                response: $result,
                result: OrderRequestResultEnum::EXCEPTION,
                order: $order,
                thirdPartyOrderNo: $taskCode,
                requestHeaders: $headers,
            );

            throw new ApiException($this->getDriverName() . ' query error: invalid response');
        }

        $this->logExchange(
            action: OrderRequestActionEnum::DEPOSIT_QUERY,
            url: $url,
            request: null,
            response: $result,
            result: OrderRequestResultEnum::OK,
            order: $order,
            orderNo: (string) $data['uniqueCode'],
            thirdPartyOrderNo: $taskCode,
            requestHeaders: $headers,
        );

        // 三方金額是外部浮點，入境即正規化，禁止直接 (string) 轉型
        $amount = isset($data['amount']) && $data['amount'] !== ''
            ? Common::numAdd('0', (string) $data['amount'], 4)
            : null;

        return QueryOrderResponseData::from([
            'type' => 'deposit',
            'orderNo' => (string) $data['uniqueCode'],
            'orderStatus' => $this->mapStatus((string) $data['status']),
            'amount' => $amount,
        ]);
    }

    /**
     * 三方 status 對照（openapi enum：PENDING / PROCESSING / SUCCESS / FAILED）。
     * 未知值一律 ERROR，不可當成任何終態。
     */
    private function mapStatus(string $status): ProviderOrderStatusEnum
    {
        return match ($status) {
            'SUCCESS' => ProviderOrderStatusEnum::SUCCESS,
            'FAILED' => ProviderOrderStatusEnum::FAIL,
            'PROCESSING' => ProviderOrderStatusEnum::PROCESSING,
            'PENDING' => ProviderOrderStatusEnum::PENDING,
            default => ProviderOrderStatusEnum::ERROR,
        };
    }

    /**
     * @return array<string, string>
     */
    protected function buildHeaders(): array
    {
        return ['api-key' => $this->info['HeaderApiKey']];
    }

    protected function sendRequest(string $url): array
    {
        return Http::logAction('Ap8Pay_QueryOrder')
            ->withHeaders($this->buildHeaders())
            ->get($url)
            ->throw()
            ->json() ?? [];
    }
}
