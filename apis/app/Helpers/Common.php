<?php

declare(strict_types=1);

namespace App\Helpers;

use App\Enums\General\CacheTtl;
use App\Helpers\Traits\CacheTrait;
use App\Helpers\Traits\JsonTrait;
use App\Helpers\Traits\MaskTrait;
use App\Helpers\Traits\MathTrait;
use App\Helpers\Traits\MonitorTrait;
use App\Helpers\Traits\PaymentTrait;
use App\Helpers\Traits\SocketTrait;
use App\Helpers\Traits\TransactionTrait;
use App\Models\Vendor;
use Exception;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Normalizer;
use Symfony\Component\HttpFoundation\IpUtils;

class Common
{
    use CacheTrait;
    use JsonTrait;
    use MaskTrait;
    use MathTrait;
    use MonitorTrait;
    use PaymentTrait;
    use SocketTrait;
    use TransactionTrait;

    /**
     * 是否為空字串或NULL
     *
     * @deprecated
     */
    public static function isNullOrEmptyString($str): bool
    {
        if ($str === null) {
            return true;
        }

        if (is_string($str)) {
            return trim($str) === '';
        }

        return empty($str);
    }

    /**
     * 驗證字段是否為非空字符串
     *
     * @param  mixed  $value  待驗證的值
     */
    public static function isNotEmptyString(mixed $value): bool
    {
        return $value !== null && $value !== '';
    }

    /**
     * @deprecated 額外的資訊已使用 Log::withContext 放在上下文
     */
    public static function log($content, $action, mixed $extra = null): void
    {
        // 刪除PASSWORD相關的KEY
        try {
            if (is_array($content)) {
                $content = collect($content)->map(function ($value, $key) {
                    if (is_array($value) && isset($value['password'])) {
                        $value['password'] = '******';
                    }

                    return $value;
                })->all();
            }
            $ip = Common::getIp();
            $context = [
                'content' => $content,
                'action' => $action,
                'ip' => $ip,
            ];
            $action = is_array($context['action']) ? json_encode($action) : $action;

            $message = sprintf('access %s from %s',
                $action,
                $context['ip']);
            Log::info($message, $context);
        } catch (Exception $e) {
            Log::error($e->getMessage(), $context ?? []);
        }
    }

    /**
     * target/tickets#4187 Stage 2：改為委派 resolveClientIp() 的信任鏈判斷，
     * 不再無條件信任下面 legacyGetIp() 那組可被使用者偽造的 header。
     */
    public static function getIp(): string
    {
        return self::resolveClientIp();
    }

    /**
     * getIp() 在 target/tickets#4187 Stage 2 之前的舊實作，整段保留、刻意沒有
     * 任何呼叫端——只要把上面 getIp() 改成呼叫這個方法，就能退回舊行為，
     * 不需要額外的 env/config 開關。
     */
    private static function legacyGetIp(): string
    {
        $headers = [
            'HTTP_ALI_CDN_REAL_IP',
            'HTTP_CF_CONNECTING_IP',
            'HTTP_CLIENT_IP',
            'HTTP_X_FORWARDED_FOR',
        ];

        foreach ($headers as $header) {
            if (!empty($_SERVER[$header])) {
                return self::extractFirstIp($_SERVER[$header]);
            }
        }

        $clientIp = request()->header('V-HTTP-CLIENT-IP');
        if (!empty($clientIp)) {
            return self::extractFirstIp($clientIp);
        }

        return self::extractFirstIp(request()->server('REMOTE_ADDR')) ?? '0.0.0.0';
    }

    /**
     * 解析真正的 client IP（target/tickets#4187）。
     *
     * 對齊 gsrc/WEB_AgentBackend/_release/trusted_proxies.php 與
     * gffv3/agent-backend apps/gateway/utils/trusted-proxies.ts 的同一套
     * 信任鏈規則：
     * - REMOTE_ADDR（真實 TCP 連線來源）不在信任清單內 → 代表 client 直連
     *   或經過未知代理，直接回傳 REMOTE_ADDR，完全不採信 X-Forwarded-For。
     * - REMOTE_ADDR 在信任清單內（已知的 CDN/LB/WAF）→ 才往
     *   X-Forwarded-For 裡找：從最右邊往左掃描，跳過所有落在信任清單內的
     *   節點，回傳第一個不在信任清單內的 IP。
     * - 整串 X-Forwarded-For 的 IP 都在信任清單內（理論上不應發生）時，
     *   fallback 回傳最左邊的 IP。
     *
     * Stage 1（本次）：只新增此方法，不改動 getIp() 既有任何呼叫點。
     * getIp() 授權判斷改用本方法是下一張票，需各品牌先驗證
     * TRUSTED_PROXIES／TRUSTED_PROXY_EXTRA_CIDRS 設定正確（見
     * docs/security/route-authorization-audit.md finding #4）。
     */
    public static function resolveClientIp(): string
    {
        $remoteAddr = (string) request()->server('REMOTE_ADDR', '');
        $trustedCidrs = self::getTrustedProxyCidrs();

        if ($remoteAddr === '' || !IpUtils::checkIp($remoteAddr, $trustedCidrs)) {
            return $remoteAddr !== '' ? $remoteAddr : '0.0.0.0';
        }

        $xff = (string) request()->header('X-Forwarded-For', '');
        if ($xff === '') {
            return $remoteAddr;
        }

        $chain = array_values(array_filter(
            array_map('trim', explode(',', $xff)),
            fn ($ip) => $ip !== ''
        ));

        if ($chain === []) {
            return $remoteAddr;
        }

        if (config('trustedproxy.skip_last_hop') && count($chain) > 1) {
            array_pop($chain);
        }

        for ($i = count($chain) - 1; $i >= 0; $i--) {
            if (!IpUtils::checkIp($chain[$i], $trustedCidrs)) {
                return $chain[$i];
            }
        }

        return $chain[0];
    }

    /**
     * 取得目前生效的信任代理 CIDR 清單（資料檔基礎清單 + 品牌額外清單）。
     *
     * 基礎清單讀 config('trustedproxy.data_path') 指向的 JSON（由
     * `trusted-proxies:sync` 指令產生並 commit 進 git），走
     * Cache::safeRemember() 避免每個請求都讀檔。檔案不存在時視為沒有任何
     * 可信代理（fail-closed）。
     */
    public static function getTrustedProxyCidrs(): array
    {
        $base = Cache::safeRemember('trusted_proxy_base_cidrs', CacheTtl::ONE_HOUR->value, function () {
            $path = config('trustedproxy.data_path');

            if (!is_string($path) || !is_file($path)) {
                return [];
            }

            $decoded = json_decode((string) file_get_contents($path), true);
            $cidrs = $decoded['cidrs'] ?? null;

            return is_array($cidrs) ? array_merge(...array_values($cidrs)) : [];
        });

        return array_values(array_filter(array_merge(
            $base,
            (array) config('trustedproxy.extra_cidrs', [])
        )));
    }

    /**
     * 代理後台稽核紀錄專用的來源 IP。
     *
     * 🚫 只能寫進 agent_operate_logs 這類稽核欄位，**禁止用於任何授權判斷**
     * （IP 白名單、2FA 略過、超級帳號、風控）。V-HTTP-CLIENT-IP 是呼叫端自填的
     * 一般 header，任何人都能偽造；拿它做授權等於把判斷權交給請求方。
     * 授權判斷一律繼續用 getIp()。
     *
     * 為什麼稽核不能用 getIp()：代理後台的請求是 WEB_AgentBackend 以 curl 代打的，
     * 中間過一層 GCP LB，getIp() 會先命中 X-Forwarded-For 拿到 WEB 主機的出口 IP，
     * 真正的操作者 IP 反而被蓋掉 —— 稽核紀錄整欄失去意義。WEB 端在
     * function.php 的每個 curlPost 都會帶上 V-HTTP-CLIENT-IP，那才是操作者 IP。
     * API_Agent 的 Common::getIp() 本來就把這個 header 排在 XFF 之前，
     * 兩後端共寫同一張表，這裡對齊它的語意。
     *
     * 偽造風險可接受：agent 路由都在 token 驗證之後，最壞情況是已登入的代理
     * 竄改自己那筆稽核的 IP，與 record_count／menu_name 同樣是前端可偽造的欄位，
     * 屬工單已知且接受的限制。
     */
    public static function getAgentClientIp(): string
    {
        $clientIp = request()->header('V-HTTP-CLIENT-IP');

        if (!empty($clientIp)) {
            return self::extractFirstIp($clientIp);
        }

        return self::getIp();
    }

    public static function extractFirstIp(string $ips): string
    {
        $ips = explode(',', $ips);
        // 去除多餘空格
        $ips = array_map('trim', $ips);
        // 排除空字串
        $ips = array_values(array_filter($ips, function ($ip) {
            return !empty($ip);
        }));

        return $ips[0] ?? '';
    }

    /**
     * 限流用的帳號／手機／信箱 key（工單 4540）：Unicode 相容分解（NFKD）後去掉組合字元，再轉小寫、去空白。
     *
     * 讓 `KÉRÖRO`、全形 `ｋｅｒｏｒｏ` 與 `keroro` 落在同一把 key。這是對齊 members.username 的
     * utf8mb4_unicode_ci 定序的近似，不是完全等價（定序還有其他等價規則），所以帳號 key 只是輔助，
     * 主要防線仍是 IP key。陣列這類非純量輸入視為沒帶，回空字串。
     * 依賴 intl 擴充（Normalizer）；正式依賴 filament/support 要求 ext-intl，映像一定有。
     */
    public static function identityRateLimitKey(mixed $value): string
    {
        if (!is_scalar($value)) {
            return '';
        }

        $value = (string) $value;
        $normalized = Normalizer::normalize($value, Normalizer::FORM_KD);

        // 非合法 UTF-8 無法正規化，改用原字串，仍保有自己的 key
        if ($normalized === false) {
            return strtolower(trim($value));
        }

        $stripped = preg_replace('/\p{Mn}/u', '', $normalized) ?? $normalized;

        return mb_strtolower(trim($stripped));
    }

    /**
     * 限流用的 IP key（工單 4540）：IPv6 截到所屬的 /64 網段，IPv4 與無法解析的字串原樣回傳。
     *
     * IPv6 一般一個用戶就配到一整段 /64，以單一位址當 key 的話，換一個位址就換到新額度。
     */
    public static function ipRateLimitKey(string $ip): string
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
            return $ip;
        }

        $packed = inet_pton($ip);
        if ($packed === false) {
            return $ip;
        }

        $network = inet_ntop(substr($packed, 0, 8) . str_repeat("\0", 8));

        return $network === false ? $ip : $network . '/64';
    }

    /**
     * 遊戲商回調來源 IP 白名單驗證（白名單值存 vendors.whitelist，逗號分隔字串，空值＝不限制）。
     *
     * 🔴 覆蓋面靠呼叫端自律，不是 middleware：由各 External Controller 在 callAction()
     * 內自行呼叫，新增 Controller 時必須自己加上，漏加不會有任何錯誤訊號。
     * CommonWhitelistTest 驗的是本方法族的比對行為，**沒有任何測試守「每支 Controller
     * 都有呼叫」**，所以漏加真的不會被擋下來。
     *
     * 2026-09-10 逐檔含繼承鏈實查：
     * - SW 的 50 支具體 Controller **全部涵蓋**（BWGController 由 PegasusController、
     *   ClotPlayLs／ClotPlayP2PController 由 ClotPlayController 繼承而來，
     *   只 grep 檔案字面會誤判成「未呼叫」）。
     * - TW 只有 5 支，**其中僅 NextSpinController 有呼叫**（Ace333／Ebet／Mega888／
     *   SpadeGaming 四支沒有）。
     *
     * ⚠️ **ClotPlayController 那一支呼叫了但擋不住**：失敗分支寫成
     * `$this->response(0, -1, 'IP not in whitelist');` 而 response() 只是回傳 JsonResponse，
     * 少了 `return` 就被丟棄、執行照樣往下走（對照 PegasusController 是 `return $this->errorResponse(...)`）。
     * 波及 ClotPlay／ClotPlayLs／ClotPlayP2P 三家，屬另案修復、不在本檔守備範圍。
     *
     * 金流三方回調（external/api/*）在 code 層沒有這道防線——其 DetectIp 只寫 Context
     * 不做驗證、IP 管控在 nginx／ingress 層，禁止據本方法推論金流回調也有白名單。
     */
    public static function verifyIPWhitelistByVendorId(int $vendorId): bool
    {
        $vendor = Vendor::find($vendorId);

        if (empty($vendor->whitelist)) {
            return true;
        }

        return self::verifyIPWhitelist($vendor->whitelist);
    }

    /**
     * 比對請求來源 IP 是否落在白名單內。
     *
     * ⚠️ 只在 config('app.env') === 'production' 生效，非正式環境一律放行——
     * 禁止拿本地或 dev 打得進去來斷定某個白名單失效。比對是「請求 IP 與白名單項目的
     * 全等字串比對」，不支援 CIDR 網段，把網段寫進 whitelist 不會生效。
     * 請求 IP 一律走 self::getIp()，禁止在呼叫端另讀 header。
     */
    public static function verifyIPWhitelist(string $whitelist): bool
    {
        if (config('app.env') != 'production') {
            return true;
        }

        /** IP 白名單驗證 **/
        if (empty($whitelist)) {
            return true;
        }

        // 內定 IP 白名單
        $whitelistIPs = array_map('trim', explode(',', $whitelist));

        // 請求的 IP
        $requestIPs = array_map('trim', explode(',', self::getIp()));

        // 檢查是否有任一 IP 在白名單中
        $matchingIPs = array_intersect($requestIPs, $whitelistIPs);
        if (empty($matchingIPs)) {
            return false;
        }

        return true;
    }

    /**
     * 白名單字串裡不合法的項目（逗號分隔，前後空白不計）。
     *
     * 只收單一 IP：{@see self::verifyIPWhitelist()} 是整串字串全等比對，網段（CIDR）寫進去
     * 永遠不會命中，所以在入口就擋掉，不要讓它進到 DB 之後才發現沒生效。
     *
     * 🔴 這是 vendors.whitelist 與 cash_flows.whitelist 共用的唯一格式判準，
     * 禁止在任一後台頁另寫一份（兩份一定會 drift）。
     *
     * @return list<string> 不合法的項目原文；空陣列＝全部合法
     */
    public static function invalidWhitelistEntries(string $whitelist): array
    {
        $invalid = [];
        foreach (explode(',', $whitelist) as $entry) {
            $entry = trim($entry);
            if (filter_var($entry, FILTER_VALIDATE_IP) === false) {
                $invalid[] = $entry;
            }
        }

        return $invalid;
    }

    /**
     * 取得API呼叫次數, 包含此次
     *
     * @param  string  $apiName  api key
     * @param  int  $seconds  幾秒內的呼叫次數
     * @param  int  $memberId  會員ID
     */
    public static function getApiCallCount(string $apiName, int $seconds, int $memberId = 0): int
    {
        $key = "api_call_count:{$apiName}:{$memberId}";
        $max = (string) now()->getTimestampMs();
        $min = (string) ($max - $seconds * 1000);

        // 移除過期的資料, 新增此次資料, 並取得次數
        $results = Redis::pipeline(function ($pipe) use ($key, $min, $max) {
            $pipe->zRemRangeByScore($key, '0', $min);
            $pipe->zAdd($key, $max, Str::uuid());
            $pipe->expire($key, CacheTtl::ONE_DAY->value);
            $pipe->zCount($key, $min, $max);
        });
        $count = (int) $results[3];

        return $count;
    }

    public static function clearApiCallCount(string $apiName, int $memberId = 0): void
    {
        Redis::del("api_call_count:{$apiName}:{$memberId}");
    }

    /**
     * 攤平 header 陣列供寫入 log，並遮罩憑證類 header（{@see MaskTrait::maskHeaders}）。
     *
     * 全部呼叫點都是 log 場景（RequestLogger 與各單一錢包 Controller 的請求紀錄），
     * 故在此收口統一遮罩：進站的會員／代理 bearer token 與出站的三方 API token
     * 不因個別呼叫點漏遮而明文落 log。需要未遮罩的 header 原值屬新用途，另開方法，
     * 不要在此加開關。
     */
    public static function flattenHeaders(array $headers): array
    {
        $flattened = array_map(function ($values) {
            // 如果值已经是字符串或其他非数组类型,直接返回
            if (!is_array($values)) {
                return $values;
            }

            // 如果是数组,只取第一个值
            return $values[0] ?? null;
        }, $headers);

        return self::maskHeaders($flattened);
    }

    /**
     * 檢查圖片使否存在
     */
    public static function isImageAccessible(string $url): bool
    {
        try {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_NOBODY => true, // 只获取头部，不下载内容
                CURLOPT_TIMEOUT => 2, // 总超时时间
                CURLOPT_CONNECTTIMEOUT => 1, // 连接超时时间
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 3,
            ]);

            curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);

            if ($error) {
                Log::debug('Image accessibility check failed', [
                    'url' => $url,
                    'error' => $error,
                ]);

                return false;
            }

            return $httpCode === 200;
        } catch (Exception $e) {
            Log::warning('Failed to check image accessibility', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * 格式化異常堆疊追蹤
     *
     * 限制堆疊追蹤字數，避免日誌過長
     *
     * @param  \Throwable  $exception  異常實例
     * @param  int  $limit  字數限制（預設 500）
     * @return string 限制後的堆疊追蹤
     */
    public static function formatTrace(\Throwable $exception, int $limit = 500): string
    {
        return Str::limit($exception->getTraceAsString(), $limit);
    }

    /**
     * 取得 GuzzleHttp 的 handler context
     *
     * 回傳請求基本資訊、cURL 錯誤、HTTP 狀態碼、對方回應內容與耗時，
     * 用於日誌中快速定位外部 API 失敗原因。
     *
     * @param  \Throwable  $exception  異常實例
     * @return array 處理上下文
     */
    /**
     * 判斷代理商階層是否在大額告警監控名單內
     *
     * 解析 config bot-notify.large_amount.watch_agents（逗號分隔）
     * 並與傳入的 hierarchyArray 取交集，有交集則回傳 true。
     */
    public static function isAgentInLargeAmountWatchList(array $hierarchyArray): bool
    {
        $watchAgents = array_values(array_filter(
            array_map('trim', explode(',', (string) config('bot-notify.large_amount.watch_agents', ''))),
            static fn (string $agentId): bool => $agentId !== '',
        ));

        if ($watchAgents === []) {
            return false;
        }

        $hierarchy = array_map('strval', $hierarchyArray);

        return !empty(array_intersect($watchAgents, $hierarchy));
    }

    public static function getGuzzleHandlerContext(\Throwable $exception): array
    {
        $maxDepth = 10;
        $depth = 0;

        while ($exception && $depth < $maxDepth) {
            $depth++;
            if (
                $exception instanceof \GuzzleHttp\Exception\ConnectException
                || $exception instanceof \GuzzleHttp\Exception\RequestException
            ) {
                $ctx = $exception->getHandlerContext();
                $request = $exception->getRequest();

                $result = [
                    'url' => (string) $request->getUri(),
                    'errno' => $ctx['errno'] ?? null,
                    'error' => $ctx['error'] ?? null,
                    'total_time' => round($ctx['total_time'] ?? 0, 3),
                ];

                // ConnectException 不含 response；RequestException 才有
                if ($exception instanceof \GuzzleHttp\Exception\RequestException) {
                    $response = $exception->getResponse();
                    if ($response !== null) {
                        $result['response_status'] = $response->getStatusCode();
                        $result['response_body'] = Str::limit((string) $response->getBody(), 500, '');
                    }
                }

                return $result;
            }
            $exception = $exception->getPrevious();
        }

        return [];
    }
}
