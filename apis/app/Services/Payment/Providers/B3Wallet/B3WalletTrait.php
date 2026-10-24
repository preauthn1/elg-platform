<?php

declare(strict_types=1);

namespace App\Services\Payment\Providers\B3Wallet;

use App\Enums\General\HttpCode;
use App\Exceptions\ApiException;
use Exception;
use Illuminate\Support\Facades\Http;

/**
 * B3 Wallet 無簽名機制，鑑權僅靠 Authorization: PaymentKey:PaymentPass header + IP 白名單。
 * 回調亦無簽名欄位，冪等/真偽一律以查詢接口二次確認為準（見 api.md「關鍵規則與常見誤區」）。
 *
 * @knowledge rules/provider-b3wallet
 */
trait B3WalletTrait
{
    protected string $apiUrlField = 'API_URL';

    protected function authorizationHeader(): string
    {
        return $this->info['PAYMENT_KEY'] . ':' . $this->info['PAYMENT_PASS'];
    }

    /**
     * 發送請求
     *
     * @param  string  $method  GET|POST
     * @param  array  $params  POST 為 JSON body；GET 為 query string
     * @param  array  $headers  額外請求頭（例如 Idempotency-Key）
     */
    protected function sendRequest(string $method, string $endpoint, array $params = [], array $headers = []): array
    {
        $action = $this->getDriverName() . '_' . strtolower($method);
        $url = rtrim($this->info[$this->apiUrlField], '/') . $endpoint;

        try {
            $request = Http::logAction($action)
                ->withHeaders(array_merge(['Authorization' => $this->authorizationHeader()], $headers));

            $response = match (strtoupper($method)) {
                'GET' => $request->get($url, $params),
                'POST' => $request->asJson()->post($url, $params),
                default => throw new ApiException('Unsupported HTTP method: ' . $method),
            };

            return $response->throw()->json() ?? [];
        } catch (Exception $e) {
            throw new ApiException(
                sprintf('%s request failed: %s', $this->getDriverName(), $e->getMessage()),
                HttpCode::HTTP_INTERNAL_SERVER_ERROR->value,
                $e
            );
        }
    }

    /**
     * 查詢 Bill 狀態（代收回調無簽名，須以此二次確認真偽）
     */
    protected function queryBill(string $billId): array
    {
        return $this->sendRequest('GET', '/api/v1/bills/' . $billId);
    }

    /**
     * 查詢 Payout 狀態（代付回調無簽名，須以此二次確認真偽）
     */
    protected function queryPayout(string $payoutId): array
    {
        return $this->sendRequest('GET', '/api/v1/payouts/' . $payoutId);
    }
}
