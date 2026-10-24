<?php

declare(strict_types=1);

namespace App\Services\Payment\Providers\PayMate;

use App\Enums\General\HttpCode;
use App\Exceptions\ApiException;
use App\Helpers\Common;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

trait PayMateTrait
{
    protected string $apiUrlField = 'API_URL';

    protected string $merchantNoField = 'MERCHANT_NO';

    protected string $md5KeyField = 'MD5_KEY';

    /** info 中覆寫商戶顯示名的欄位（SE PayCenter 協定多商戶共用本實作，見 DriverTrait） */
    protected string $driverLabelField = 'DRIVER_LABEL';

    /**
     * 以 info[DRIVER_LABEL] 覆寫商戶顯示名
     *
     * 本實作供 SE PayCenter 協定的多組商戶共用（PayMate／SpeedPay），未配置本欄位者
     * 沿用 Provider 目錄名（即 PayMate 商戶行為不變）。
     */
    protected function driverLabelOverride(): ?string
    {
        $label = $this->info[$this->driverLabelField] ?? null;

        return is_string($label) && $label !== '' ? $label : null;
    }

    /**
     * 發送通用 Request（見 api.md 1. 通用 Request）；信封 resCode 拒絕即拋，
     * 供代收 / 查單等同步失敗即中止的流程
     *
     * @param  string  $path  端點路徑，如 /qrh5、/payout
     * @param  array  $params  業務參數（將組成 JSON 後 Base64 Encode 放入 request）
     */
    protected function sendRequest(string $path, array $params): array
    {
        $result = $this->sendRequestRaw($path, $params);

        if (!isset($result['resCode']) || $result['resCode'] !== '000') {
            Log::error($this->getDriverName() . ' response error', ['result' => $result]);

            throw new ApiException(sprintf(
                '%s error: %s',
                $this->getDriverName(),
                $result['resMsg'] ?? 'Unknown error'
            ));
        }

        return $this->unwrapEnvelope($result);
    }

    /**
     * 發送 PayMate API 請求（僅 HTTP 層檢查，回傳信封原樣、不驗 resCode）。
     *
     * 供代付 performRequest 使用：只有 HTTP 非 2xx（結果未知）才拋例外，
     * 信封層拒絕（resCode != 000）原樣回傳交 interpretResult 判定同步失敗，
     * 避免明確拒絕被誤判為結果未知而讓訂單停留在 21（PAYMENT_SUBMITTED）。
     */
    protected function sendRequestRaw(string $path, array $params): array
    {
        $action = $this->getDriverName() . '_sendRequest';
        $url = rtrim($this->info[$this->apiUrlField], '/') . $path;

        Log::debug($this->getDriverName() . ' request params', Common::maskData($params));

        $data = $this->buildRequest($params);

        $result = Http::logAction($action)
            ->asForm()
            ->post($url, $data)
            ->throw()
            ->json();

        return $result ?? [];
    }

    /**
     * 驗證信封完整性（merchantNo / 驗簽）並解出內層 response；
     * 完整性存疑＝結果未知，一律拋例外
     */
    protected function unwrapEnvelope(array $result): array
    {
        if (!isset($result['merchantNo']) || $result['merchantNo'] !== $this->info[$this->merchantNoField]) {
            throw new ApiException($this->getDriverName() . ' merchant number mismatch');
        }

        if (!$this->verifyResponseSign($result)) {
            throw new ApiException($this->getDriverName() . ' response signature verification failed');
        }

        // 內層 base64/JSON 解不出＝結果未知，一律拋（不得回空陣列讓呼叫端誤判為明確失敗）
        $decoded = json_decode(base64_decode((string) ($result['response'] ?? '')), true);

        if (!is_array($decoded)) {
            throw new ApiException($this->getDriverName() . ' response payload decode failed');
        }

        return $decoded;
    }

    /**
     * 組成通用 Request（version + merchantNo + request(Base64 JSON) + signMsg）
     */
    protected function buildRequest(array $params): array
    {
        $data = [
            'version' => '1.0',
            'merchantNo' => $this->info[$this->merchantNoField],
            'request' => base64_encode(json_encode($params)),
        ];

        $data['signMsg'] = $this->generateSign($data);

        return $data;
    }

    /**
     * 生成 signMsg：欄位依名稱字首升冪排序，name:value 以 & 拼接，
     * 最後拼上 key:{MD5_KEY}，UTF-8 MD5，轉大寫（見 api.md 1.1／2.1 簽名規則）
     */
    protected function generateSign(array $fields): string
    {
        ksort($fields, SORT_STRING);

        $signStr = '';
        foreach ($fields as $key => $value) {
            $signStr .= $key . ':' . $value . '&';
        }
        $signStr .= 'key:' . $this->info[$this->md5KeyField];

        return strtoupper(md5($signStr));
    }

    /**
     * 驗證通用 Response 的 signMsg（見 api.md 2.1）
     */
    protected function verifyResponseSign(array $result): bool
    {
        $fields = [
            'merchantNo' => $result['merchantNo'] ?? '',
            'resCode' => $result['resCode'] ?? '',
            'resMsg' => $result['resMsg'] ?? '',
            'response' => $result['response'] ?? '',
            'version' => $result['version'] ?? '',
        ];

        return hash_equals($this->generateSign($fields), (string) ($result['signMsg'] ?? ''));
    }

    /**
     * 驗證 Notify 回調的 signMsg（見 api.md 4.2）
     */
    protected function verifyNotifySign(array $input): bool
    {
        $fields = [
            'content' => $input['content'] ?? '',
            'notifyTime' => $input['notifyTime'] ?? '',
            'version' => $input['version'] ?? '',
        ];

        return hash_equals($this->generateSign($fields), (string) ($input['signMsg'] ?? ''));
    }

    /**
     * 解碼 Notify 的 content（Base64 JSON，見 api.md 4.1）
     */
    protected function decodeNotifyContent(string $content): array
    {
        $decoded = json_decode(base64_decode($content), true);

        if (!is_array($decoded)) {
            throw new ApiException($this->getDriverName() . ' notify content decode failed', HttpCode::HTTP_INTERNAL_SERVER_ERROR->value);
        }

        Log::debug($this->getDriverName() . ' notify content decoded', Common::maskData($decoded));

        return $decoded;
    }
}
