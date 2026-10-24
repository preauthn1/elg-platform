<?php

declare(strict_types=1);

namespace App\Services\Payment\Providers\EasyPay;

use App\Exceptions\ApiException;
use Exception;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * @knowledge rules/payment-callback-common-checks
 */
trait EasyPayTrait
{
    // agentCashFlow->info 的 key 定義
    protected string $apiUrl = 'API_URL';

    protected string $merchantName = 'MERCHANT_NAME';

    protected string $merchantPassword = [REDACTED]'; // hardcode-ok: agentCashFlow->info 的欄位名，不是密碼本身

    protected string $privateKey = 'API_PRIVATE_KEY'; // hardcode-ok: agentCashFlow->info 的欄位名，不是金鑰本身

    /**
     * 為建單請求補上 `mdfive_val` 簽名（代收／代付的 create 一律適用）。
     *
     * 🔴 **`$signedFields` 必須照廠商文件逐個接口列的清單寫，禁止改成「請求全欄位」**：各接口簽的
     * 都是子集，代收的 `transferror_name`、代付快轉的 `holder_name` 都是必填但不參與簽名，多簽
     * 一個欄位簽出來就對不上。清單見各呼叫端的註解。
     *
     * 演算法（文件 `https://yizypayonline.online/merchant-docs` §簽名 — mdfive_val，已用文件範例值驗過）：
     * 鍵名升序 → `http_build_query`（空格編為 `+`）→ `derived = HMAC_SHA256('signing', API 私鑰)`
     * → `mdfive_val = HMAC_SHA256(query, derived)`，小寫十六進位。
     *
     * 🔴 **第二層的 key 用 `derived` 的小寫 hex 字串，不是 raw binary**：兩者都算得出值、長度也一樣，
     * 寫錯只會在三方開啟簽名後才爆（建單全被拒），本機看不出來。
     *
     * ⚠️ **欄位名叫 `mdfive` 但實際是 HMAC-SHA256，與 MD5 無關**，不要寫成 `md5()`。
     *
     * 簽名按商戶開關、預設關閉，所以 `info` 沒有設私鑰時原樣回傳不帶簽名——未開啟的站台照舊可用。
     *
     * @param  array<string, mixed>  $params  完整請求參數
     * @param  list<string>  $signedFields  該接口參與簽名的欄位；`$params` 沒有的鍵自動略過
     *                                      （快轉的 `bank_code` 就是「有傳才簽」）
     * @return array<string, mixed>
     */
    protected function signParams(array $params, array $signedFields): array
    {
        $privateKey = (string) ($this->info[$this->privateKey] ?? '');

        if ($privateKey === '') {
            return $params;
        }

        $signed = array_intersect_key($params, array_flip($signedFields));
        ksort($signed);

        $derived = hash_hmac('sha256', 'signing', $privateKey);
        $params['mdfive_val'] = hash_hmac('sha256', http_build_query($signed), $derived);

        return $params;
    }

    /**
     * 驗證回調簽名，回傳是否放行。
     *
     * 🔴 **回調沒帶 `mdfive_val` 一律放行，禁止改成「一律要求簽名」**：簽名按商戶開關、預設關閉，
     * 我方多數商戶還沒開（2026-10-13 dev 實測，開了簽名的建單與回調 body 仍只有 7 個業務欄位）。
     * 改成強制，所有未開簽名的商戶回調會被全擋 ⇒ 儲值不上分。帶了才驗、帶了就一定要驗得過。
     *
     * 參與簽名的是**回調體中除 `mdfive_val` 自身以外的全部欄位**（代收 7 個、代付少一個
     * `transferror_name`），所以這裡不列白名單、直接簽剩下的全部。
     * 🔴 **不可過濾空值**：`transferror_name` 未知時三方填 `"-"`，欄位不會因為值空被剔除，
     * 過濾掉就簽不出同一個值。
     *
     * 私鑰沒設定時同樣放行並記一筆 warning：那代表設定還沒補上，不是回調有問題，
     * 擋下來只會把「設定沒補」變成「儲值全掛」。
     *
     * @param  array<string, mixed>  $payload  回調原始內容（不要用 validate() 的結果，那會丟掉簽名欄位）
     */
    protected function isCallbackSignatureValid(array $payload): bool
    {
        $received = (string) ($payload['mdfive_val'] ?? '');

        if ($received === '') {
            return true;
        }

        $privateKey = (string) ($this->info[$this->privateKey] ?? '');

        if ($privateKey === '') {
            Log::warning($this->getDriverName() . ' callback signed but private key not configured, skip verify', [
                'merchant' => $this->info[$this->merchantName] ?? null,
            ]);

            return true;
        }

        unset($payload['mdfive_val']);
        ksort($payload);

        $derived = hash_hmac('sha256', 'signing', $privateKey);
        $expected = hash_hmac('sha256', http_build_query($payload), $derived);

        return hash_equals($expected, $received);
    }

    /**
     * 發送請求，回傳三方的業務信封（JSON decode 後的陣列）。
     *
     * 🔴 **刻意不掛 `->throw()`**：本家用 HTTP 400 承載業務錯誤
     * （`{"status":0,"code":"MERCHANT_INSUFFICIENT_BALANCE"}`），掛了 throw 的話非 2xx 會先變成
     * 例外，呼叫端拿不到 body，「三方明確拒絕」就會被當成「連不上、結果未知」——代付那條路因此
     * 讓訂單停在 21 等人工定案，錢明明沒出去（工單 target/tickets#4449，dev 實測）。
     *
     * 🔴 **但解不出業務信封時一定要拋**：502 的 HTML 錯誤頁、網關空 body、逾時後的殘缺回應，
     * `json()` 回的是 null。那種情況**請求可能已經送到三方**，不可以當成明確拒絕——判成拒絕會讓
     * 營運重新發起，等於二次出款。所以這裡拋出去，維持「結果未知」的語意（代付停 21）。
     * 判準是**「這包回應解不解得出業務信封」，不是 HTTP 幾**。同樣形狀見 Kira 的代付。
     *
     * 因此呼叫端拿到的回傳值一律是「三方答覆過」，各自依 `status` 判成功或拒絕。
     *
     * @throws ApiException 解不出業務信封（結果未知）
     */
    protected function sendRequest(string $url, array $params = [], array $headers = []): array
    {
        $action = $this->getDriverName() . '_sendRequest';

        $url = rtrim($this->info[$this->apiUrl], '/') . $url;

        $headers = array_merge([
            'merchant' => $this->info[$this->merchantName],
        ], $headers);

        try {
            $response = Http::logAction($action)
                ->asJson()
                ->withHeaders($headers)
                ->withBasicAuth($this->info[$this->merchantName], $this->info[$this->merchantPassword])
                ->post($url, $params);
        } catch (Exception $e) {
            Log::error($this->getDriverName() . ' request error: ' . $e->getMessage(), [
                'request' => [
                    'url' => $url,
                    'params' => $params,
                    'headers' => $headers,
                ],
            ]);

            throw $e;
        }

        $result = $response->json();

        if (!is_array($result)) {
            Log::error($this->getDriverName() . ' unparseable response', [
                'url' => $url,
                'http_status' => $response->status(),
                'body' => mb_substr((string) $response->body(), 0, 500),
            ]);

            throw new ApiException(sprintf(
                '%s unparseable response (HTTP %d)',
                $this->getDriverName(),
                $response->status()
            ));
        }

        return $result;
    }
}
