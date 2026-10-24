<?php

declare(strict_types=1);

namespace App\Services\Payment\Providers\A3Pay;

use App\Exceptions\ApiException;
use App\Helpers\Common;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * A3Pay 共用層：信封（merchantCode／data／signature／version）、AES-256-CBC（PBKDF2-SHA1 衍生密鑰、IV 前置）、
 * RSA SHA-1 簽名與驗簽（規格見 docs/integrations/payments/A3Pay/api.md「加解密」）。
 *
 * @knowledge rules/provider-a3pay
 */
trait A3PayTrait
{
    protected const string FIELD_API_URL = 'API_URL';

    protected const string FIELD_MERCHANT_CODE = 'MERCHANT_CODE';

    protected const string FIELD_AES_KEY = 'AES_KEY';

    protected const string FIELD_RSA_PRIVATE_KEY = 'RSA_PRIVATE_KEY';

    protected const string FIELD_RSA_PUBLIC_KEY = 'RSA_PUBLIC_KEY';

    protected const string FIELD_CURRENCY = 'CURRENCY';

    // 回調回 success 三方才停止重送（api.md Payin／Payout Callback）
    protected string|array $notifySuccessText = 'success';

    protected string|array $notifyFailText = 'fail';

    /**
     * 發送請求並回傳解析後的 JSON。
     *
     * body 解不出 JSON 陣列時拋 ApiException：代付經 processPayout 時歸為結果未知（-1、訂單停 21）。
     * 禁止改成回空陣列（或任何假信封）交給 describeError 描述——空陣列會流進 interpretResult 被判成
     * 「明確拒絕」轉 29 可重送，是重複出款的來源。非 JSON 的原文（如 GetPayoutDetail 查無回的
     * `Not found`）由 logAction 記進 HTTP log，這裡只把狀態碼帶進訊息，讓它跟 describeError 的
     * `Forbidden (403)` 一樣看得出是什麼錯，而不必回頭翻 log。
     */
    protected function sendRequest(string $endpoint, array $data): array
    {
        $response = $this->sendRequestRaw($endpoint, $data);
        $result = $response->json();

        if (!is_array($result)) {
            throw new ApiException(sprintf(
                '%s unparseable response from %s (%d)',
                $this->getDriverName(),
                $endpoint,
                $response->status()
            ));
        }

        return $result;
    }

    /**
     * 發送請求並回傳原始 Response（代付以 HTTP 狀態碼區分明確拒絕與結果未知，需要狀態碼）。
     * Data 為 Data Parameter JSON 的 AES 密文，Signature 為同一份 JSON 明文的 RSA SHA-1 簽名。
     */
    protected function sendRequestRaw(string $endpoint, array $data): Response
    {
        $url = rtrim($this->info[self::FIELD_API_URL], '/') . $endpoint;

        Log::info(sprintf('%s 準備請求', $this->getDriverName()), [
            'url' => $url,
            'data' => Common::maskData($data),
        ]);

        // 簽名與密文必須用同一份明文：三方解密 Data 後對明文驗簽（api.md「Form Signature」）
        $json = json_encode($data);

        $params = [
            'merchantCode' => $this->info[self::FIELD_MERCHANT_CODE],
            'data' => $this->aesEncrypt($json, $this->info[self::FIELD_AES_KEY]),
            'signature' => $this->generateRsaSignature($json, $this->info[self::FIELD_RSA_PRIVATE_KEY]),
            'version' => 0,
        ];

        try {
            return Http::logAction($this->getDriverName() . '_sendRequest')
                ->asJson()
                ->post($url, $params);
        } catch (ConnectionException $e) {
            // 連線／逾時：統一包成 ApiException；代付走 processPayout 仍歸結果未知（-1、訂單停 21）
            throw new ApiException(sprintf('%s request failed: %s', $this->getDriverName(), $e->getMessage()), 0, $e);
        }
    }

    /**
     * 取回應 data 內的值：文件鍵名優先，退回實測的舊鍵名。
     *
     * 2026-09-24 dev 實打 CreatePayin：請求只吃文件的新欄位名（MID／PayinAmount…，舊名回「Invalid Request」），
     * 但回應 data 用的是舊鍵名（transactionId／url／qrCode／depositActualAmount），不是文件寫的
     * paymentOrderId／paymentURL／paymentQRCode／payinActualAmount（integration.md 落差 #4）。
     * 兩組都收，哪天廠商改回文件版也不會斷。
     */
    protected function dataValue(array $data, string $documented, string $legacy): mixed
    {
        $value = $data[$documented] ?? null;

        if ($value === null || $value === '') {
            $value = $data[$legacy] ?? null;
        }

        return $value;
    }

    /**
     * 把閘道回應的錯誤描述成一行。錯誤回應有三種形狀：
     *
     * 一、業務信封（A3Pay 應用層拒絕，HTTP 400）→ errorMessage 優先，否則串 errorList：
     *
     *   {"isSuccess":false,"successCode":400,"errorCode":"PMT10013","errorMessage":"Insufficient balance",
     *    "errorList":[{"ErrorCode":"E1001","ErrorDescription":"FromCustomerAccountHolderName is required"}],"data":null}
     *
     * 二、IIS ProblemDetails（請求沒進應用層就被前置閘道擋掉，例：出口 IP 不在白名單）→ 取 title (status)：
     *
     *   {"type":"https://example.invalid/html/rfc7231#section-6.5.3","title":"Forbidden","status":403,
     *    "traceId":"00-2452aaebe152d212c018222d90692b89-c1d04759137d5f4d-00"}
     *
     *   業務欄位一個都沒有，不認這個形狀就只吐 'Unknown error'，得回頭翻 log 才知道是 403
     *   （2026-09-30 VWSG 代收首次實打即如此）。業務信封用 successCode 不用 status，兩者不會同時出現。
     *
     * 三、非 JSON 純文字（GetPayoutDetail 查無回 `Not found`）刻意到不了這裡，理由見 sendRequest()：
     *     建單在那裡就拋 'unparseable response from <endpoint> (<status>)'，狀態碼同樣帶在訊息上；
     *     查單 Withdrawal::queryByTransactionNo() 自己判 is_array，形狀不符直接回 UNKNOWN。
     */
    protected function describeError(array $result): string
    {
        if (!empty($result['errorMessage'])) {
            return (string) $result['errorMessage'];
        }

        $items = [];
        foreach ((array) ($result['errorList'] ?? []) as $error) {
            $items[] = trim(sprintf('%s: %s', $error['ErrorCode'] ?? $error['errorCode'] ?? '', $error['ErrorDescription'] ?? $error['errorDescription'] ?? ''), ': ');
        }

        if ($items !== []) {
            return implode('; ', $items);
        }

        if (!empty($result['title'])) {
            return empty($result['status'])
                ? (string) $result['title']
                : sprintf('%s (%s)', $result['title'], $result['status']);
        }

        return 'Unknown error';
    }

    /**
     * AES-256-CBC 加密：PBKDF2-SHA1（password 與 salt 都是 AES key、1000 次）衍生 32 bytes 密鑰，隨機 IV 前置於密文，整體 base64
     */
    protected function aesEncrypt(string $plainText, string $aesKey): string
    {
        $key = hash_pbkdf2('sha1', $aesKey, $aesKey, 1000, 32, true);
        $iv = openssl_random_pseudo_bytes(16);
        $encrypted = openssl_encrypt($plainText, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);

        if ($encrypted === false) {
            throw new ApiException($this->getDriverName() . ' AES encryption failed');
        }

        return base64_encode($iv . $encrypted);
    }

    /**
     * AES-256-CBC 解密：base64 解碼後前 16 bytes 為 IV
     */
    protected function aesDecrypt(string $encryptedBase64, string $aesKey): string
    {
        $raw = base64_decode($encryptedBase64, true);

        if ($raw === false || strlen($raw) <= 16) {
            throw new ApiException($this->getDriverName() . ' AES decryption failed: invalid payload');
        }

        $key = hash_pbkdf2('sha1', $aesKey, $aesKey, 1000, 32, true);
        $decrypted = openssl_decrypt(substr($raw, 16), 'AES-256-CBC', $key, OPENSSL_RAW_DATA, substr($raw, 0, 16));

        if ($decrypted === false) {
            throw new ApiException($this->getDriverName() . ' AES decryption failed');
        }

        return $decrypted;
    }

    /**
     * RSA SHA-1 簽名：商戶私鑰（PKCS#8 base64，補 PEM 頭尾）對 JSON 明文簽，base64 回傳
     */
    protected function generateRsaSignature(string $plainText, string $privateKeyBase64): string
    {
        $pem = "[PRIVATE_KEY_BLOCK_REDACTED]';
        $key = openssl_pkey_get_private($pem);

        if ($key === false) {
            throw new ApiException($this->getDriverName() . ' invalid RSA private key');
        }

        $signature = '';

        if (!openssl_sign($plainText, $signature, $key, OPENSSL_ALGO_SHA1)) {
            throw new ApiException($this->getDriverName() . ' RSA signature generation failed');
        }

        return base64_encode($signature);
    }

    /**
     * RSA SHA-1 驗簽：A3Pay 公鑰（X.509 base64，補 PEM 頭尾）對解密後的 JSON 明文驗
     */
    protected function verifyRsaSignature(string $plainText, string $signatureBase64, string $publicKeyBase64): bool
    {
        $pem = "-----BEGIN PUBLIC KEY-----\n" . chunk_split($publicKeyBase64, 64) . '-----END PUBLIC KEY-----';
        $key = openssl_pkey_get_public($pem);

        if ($key === false) {
            throw new ApiException($this->getDriverName() . ' invalid RSA public key');
        }

        $signature = base64_decode($signatureBase64, true);

        if ($signature === false) {
            return false;
        }

        return openssl_verify($plainText, $signature, $key, OPENSSL_ALGO_SHA1) === 1;
    }
}
