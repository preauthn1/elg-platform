<?php

declare(strict_types=1);

namespace App\Logging;

use App\Helpers\Common;
use GuzzleHttp\TransferStats;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

class HttpLoggerCustom
{
    /**
     * 最後一次出站 HTTP 的摘要，存在 Context 的 hidden 區；值是純量陣列
     * （目前 `http_status` 與 `remote_ip`，要再加欄位就加鍵，不另開 key）。
     *
     * 為什麼要存：`OrderRequestLog` 的 `http_status` 與 `source_ip` 需要這兩個值，但金流商的
     * `sendRequest()` 回傳的是已經 json_decode 的陣列，HTTP 層的資訊留在各家實作裡出不來。
     * 逐家改 30 個 provider 不划算，而所有出站三方 HTTP 一律經本 macro（見 registerMacro 的硬規則），
     * 所以在這裡集中取一次。
     *
     * 🔴 **只存純量，禁止把 response 物件整個塞進來**。Context 的 hidden 會被 serialize 進
     * queue payload（實測：`Context::dehydrate()` 的 hidden 帶著完整的 `GuzzleHttp\Psr7\Response`
     * 字串），而三方回應原文含明文商戶憑證——那是 `order_request_logs` 刻意只留在 DB、
     * 禁止外流的東西，進了 queue 就等於換了一個保護等級更低的地方存。
     *
     * 🔴 **語意是「最後一次」，不是「某一次」**：本類只負責覆寫，讀取端必須在發出請求前先
     * {@see self::forgetLastOutbound()}，否則讀到的可能是同一個 request／job 裡更早那次請求的值。
     *
     * ⚠️ 兩個鍵的取得時機不同：狀態碼在 middleware 的 promise（`Http::fake()` 下也有），
     * 對端 IP 在 `on_stats`（只有真的連出去的 curl handler 有）。所以測試裡狀態碼拿得到、IP 是 null。
     */
    public const string LAST_OUTBOUND = 'last_outbound';

    /**
     * 註冊 logAction macro 於 Http facade 與 PendingRequest。
     * 兩處皆為 inline closure（Larastan 賴此辨識 $this 綁定），實際邏輯下沉至 buildLoggingMiddleware。
     *
     * 🔴 所有出站三方 HTTP 一律經 logAction，禁止自寫 Guzzle middleware，
     * **也禁止在呼叫端另外 `Log::info` 一份請求內容——那會產生未遮罩的第二份紀錄**。
     * header 由本類統一經 `Common::flattenHeaders()`／`maskHeaders()` 遮罩，
     * 禁止在此另立遮罩鍵清單（單一來源是 MaskTrait）。
     */
    public static function registerMacro(): void
    {
        Http::macro('logAction', function (string $action, array $info = [], bool $verbose = false, array $maskKeys = []) {
            /** @var PendingRequest $this */
            return $this->withMiddleware(HttpLoggerCustom::buildLoggingMiddleware(
                action: $action,
                info: $info,
                verbose: $verbose,
                maskKeys: $maskKeys,
            ));
        });

        PendingRequest::macro('logAction', function (string $action, array $info = [], bool $verbose = false, array $maskKeys = []) {
            /** @var PendingRequest $this */
            return $this->withMiddleware(HttpLoggerCustom::buildLoggingMiddleware(
                action: $action,
                info: $info,
                verbose: $verbose,
                maskKeys: $maskKeys,
            ));
        });
    }

    /**
     * 建立記錄 HTTP request/response 的 Guzzle middleware。
     *
     * 注意：必須維持 public——由 registerMacro 內的 macro closure 呼叫，該 closure 於執行期被
     * Laravel `Macroable` rebind 到 PendingRequest/Factory scope，無法存取本類的 private 成員。
     *
     * @param  array<array-key, mixed>  $info
     * @param  array<int, string>  $maskKeys
     */
    public static function buildLoggingMiddleware(string $action, array $info, bool $verbose, array $maskKeys): callable
    {
        return function (callable $handler) use ($action, $info, $verbose, $maskKeys) {
            return function (RequestInterface $request, array $options) use ($handler, $action, $info, $verbose, $maskKeys) {
                $startTime = microtime(true);

                // 保留外部已設定的 on_stats，避免鏈式覆蓋
                $existing = $options['on_stats'] ?? null;

                $verboseHandle = $verbose ? fopen('php://temp', 'w+') : null;
                if ($verboseHandle !== null) {
                    $options['curl'] = [CURLOPT_VERBOSE => true, CURLOPT_STDERR => $verboseHandle] + ($options['curl'] ?? []);
                }

                $options['on_stats'] = function (TransferStats $stats) use ($action, $startTime, $info, $existing, $verboseHandle, $maskKeys) {
                    // on_stats 由 Guzzle 當純 callable 呼叫、未 rebind，scope 仍為本類，故可 self:: 存取 private logStats
                    self::logStats(
                        stats: $stats,
                        action: $action,
                        startTime: $startTime,
                        info: $info,
                        maskKeys: $maskKeys,
                        verboseHandle: $verboseHandle,
                    );

                    // 若外部有設定 on_stats，執行完本次 log 後繼續呼叫
                    if ($existing) {
                        ($existing)($stats);
                    }
                };

                return $handler($request, $options)->then(static function (ResponseInterface $response) {
                    // 狀態碼在這裡取而不是在 on_stats：on_stats 由 curl handler 觸發，
                    // Http::fake() 換掉 handler 之後就不會跑，測試驗不到寫進去的值。
                    // middleware 的 promise 無論真連線或 fake 都會走到。
                    self::rememberOutbound('http_status', $response->getStatusCode());

                    return $response;
                });
            };
        };
    }

    /**
     * 將單次 HTTP 請求的 request/response context 寫入 log（含遮罩、耗時統計、verbose 輸出）。
     *
     * @param  array<array-key, mixed>  $info
     * @param  array<int, string>  $maskKeys
     * @param  resource|null  $verboseHandle
     */
    private static function logStats(
        TransferStats $stats,
        string $action,
        float $startTime,
        array $info,
        array $maskKeys,
        $verboseHandle
    ): void {
        $ctx = $stats->getHandlerStats();
        self::rememberLastOutbound($ctx);
        // 優先使用 Guzzle 統計的 total_time，無法取得時改以 microtime 計算實際耗時
        $totalTime = round($ctx['total_time'] ?? microtime(true) - $startTime, 5);
        $level = $stats->hasResponse() ? 'info' : 'error';

        $request = self::extractRequestContext($stats->getRequest());
        $response = self::extractResponseContext($stats->getResponse());

        // 遮罩支付憑證（pin / 簽章密鑰等），避免完整憑證隨 HTTP request/response 落入 log。
        // 只遮罩 log 副本，不影響實際送出的 body；未指定 maskKeys 則維持原樣（向後相容）。
        if (!empty($maskKeys)) {
            $request['params'] = self::maskSensitive($request['params'], $maskKeys);
            if (is_array($response['body'])) {
                $response['body'] = self::maskSensitive($response['body'], $maskKeys);
            }
        }

        $log = ['request' => $request, 'response' => $response];
        if (!empty($info)) {
            $log['info'] = $info;
        }
        // 僅在無回應（連線失敗）時附上 handler 統計與 verbose 輸出，協助診斷連線層問題
        if (!$stats->hasResponse()) {
            $log['handler_stats'] = self::buildHandlerStats($ctx, $totalTime);
            $verboseOutput = self::readVerboseOutput($verboseHandle);
            if ($verboseOutput !== '') {
                $log['handler_stats']['verbose'] = $verboseOutput;
            }
        }

        if ($verboseHandle !== null && is_resource($verboseHandle)) {
            fclose($verboseHandle);
        }

        Log::$level($action . ', Time ' . $totalTime . ' sec', $log);
    }

    /**
     * 記下本次出站的對端 IP，供 {@see \App\Models\OrderRequestLog} 的 `source_ip` 取用。
     *
     * `primary_ip` 是 curl 的 `curl_getinfo` 給的，只有 curl handler 有；換成 StreamHandler、
     * 或在 `Http::fake()` 下都拿不到，故一律 null 安全取值——那是連線層的事實，不是錯誤。
     * 狀態碼不在這裡取，理由見 {@see self::buildLoggingMiddleware()}。
     *
     * @param  array<array-key, mixed>  $handlerStats
     */
    private static function rememberLastOutbound(array $handlerStats): void
    {
        self::rememberOutbound('remote_ip', $handlerStats['primary_ip'] ?? null);
    }

    /**
     * 併入最後一次出站摘要的其中一個鍵。
     *
     * 兩個寫入點（middleware 的 promise 與 `on_stats`）各寫一半、先後不保證，所以是併入不是覆寫整包。
     */
    private static function rememberOutbound(string $key, int|string|null $value): void
    {
        $current = Context::getHidden(self::LAST_OUTBOUND);

        Context::addHidden(self::LAST_OUTBOUND, [
            ...(is_array($current) ? $current : []),
            $key => $value,
        ]);
    }

    /**
     * 清掉上一次出站的狀態碼與對端 IP。
     *
     * 🔴 **發出請求前一定要呼叫**：這兩個值是「最後一次」的語意，不清的話，請求如果拋在
     * 送出之前（參數組裝失敗、連 DNS 都沒查），讀到的會是同一個 request／job 裡更早那次
     * 請求的值——而那筆紀錄會看起來完全正常，是最難發現的錯。
     */
    public static function forgetLastOutbound(): void
    {
        Context::forgetHidden(self::LAST_OUTBOUND);
    }

    /** 最後一次出站的 HTTP 狀態碼；沒拿到（連不上、或未經 logAction）時為 null。 */
    public static function lastOutboundHttpStatus(): ?int
    {
        $status = self::lastOutbound()['http_status'] ?? null;

        return is_int($status) ? $status : null;
    }

    /** 最後一次出站的對端（三方伺服器）IP；沒拿到時為 null。 */
    public static function lastOutboundRemoteIp(): ?string
    {
        $ip = self::lastOutbound()['remote_ip'] ?? null;

        return is_string($ip) && $ip !== '' ? $ip : null;
    }

    /** @return array<string, int|string|null> */
    private static function lastOutbound(): array
    {
        $summary = Context::getHidden(self::LAST_OUTBOUND);

        return is_array($summary) ? $summary : [];
    }

    /**
     * 讀取 curl verbose 暫存串流的內容；未啟用或無內容時回傳空字串。
     *
     * @param  resource|null  $verboseHandle
     */
    private static function readVerboseOutput($verboseHandle): string
    {
        if ($verboseHandle === null) {
            return '';
        }

        rewind($verboseHandle);

        return stream_get_contents($verboseHandle) ?: '';
    }

    /**
     * 遞迴遮罩指定的敏感鍵（支付憑證如 pin、payment_key/payment_pass、簽章密鑰等），回傳遮罩後副本。
     * 僅供寫入 log 使用——比對命中的鍵一律以 '***' 取代，巢狀陣列遞迴處理。
     *
     * @param  array<array-key, mixed>  $data
     * @param  array<int, string>  $maskKeys
     * @return array<array-key, mixed>
     */
    public static function maskSensitive(array $data, array $maskKeys): array
    {
        if (empty($maskKeys)) {
            return $data;
        }

        foreach ($data as $key => $value) {
            if (is_string($key) && in_array($key, $maskKeys, true)) {
                $data[$key] = is_scalar($value) ? Common::maskData($value, $key) : '***';
            } elseif (is_array($value)) {
                $data[$key] = self::maskSensitive($value, $maskKeys);
            }
        }

        return $data;
    }

    /** @return array{url: string, method: string, header: array<string, string>, params: array} */
    public static function extractRequestContext(?RequestInterface $request): array
    {
        if (!$request) {
            return ['url' => '', 'method' => '', 'header' => [], 'params' => []];
        }

        $request->getBody()->rewind();
        $rawReq = $request->getBody()->getContents();
        $params = json_decode($rawReq, true) ?: [];
        if (!$params) {
            parse_str($rawReq, $params);
        }

        return [
            'url' => (string) $request->getUri(),
            'method' => $request->getMethod(),
            'header' => Common::maskHeaders(array_map(fn (array $v): string => implode(', ', $v), $request->getHeaders())),
            'params' => $params,
        ];
    }

    /** @return array{statusCode: int|null, header: array<string, string>, body: mixed} */
    public static function extractResponseContext(?ResponseInterface $response): array
    {
        if (!$response) {
            return ['statusCode' => null, 'header' => [], 'body' => null];
        }

        $response->getBody()->rewind();
        $rawRes = $response->getBody()->getContents();
        // 回捲 stream，確保後續呼叫 $response->body() 仍能正常讀取
        $response->getBody()->rewind();

        return [
            'statusCode' => $response->getStatusCode(),
            'header' => Common::flattenHeaders($response->getHeaders()),
            'body' => json_decode($rawRes, true) ?: $rawRes,
        ];
    }

    /**
     * @param  array<string, mixed>  $ctx
     * @return array{total: float, dns: float, connect: float, ssl: float, ttfb: float}
     */
    public static function buildHandlerStats(array $ctx, float $totalTime): array
    {
        return [
            'total' => $totalTime,
            'dns' => round($ctx['namelookup_time'] ?? 0, 5),
            'connect' => round($ctx['connect_time'] ?? 0, 5),
            'ssl' => round($ctx['appconnect_time'] ?? 0, 5),
            'ttfb' => round($ctx['starttransfer_time'] ?? 0, 5),
            'primary_ip' => $ctx['primary_ip'] ?? null,
            'primary_port' => $ctx['primary_port'] ?? null,
        ];
    }
}
