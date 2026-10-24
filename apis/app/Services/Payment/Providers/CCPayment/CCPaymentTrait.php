<?php

declare(strict_types=1);

namespace App\Services\Payment\Providers\CCPayment;

use App\CacheKeys\CacheKeys;
use App\Enums\General\CacheTtl;
use App\Enums\General\HttpCode;
use App\Exceptions\ApiException;
use App\Facades\BotNotify;
use App\Helpers\Common;
use App\Models\AgentCashFlowOrderType;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * CCPayment 共用 HTTP 與簽名邏輯。
 *
 * 鑑權為 header 三件組（Appid / Timestamp / Sign）＋ IP 白名單（商戶在廠商後台有設定時才檢查，api.md §1）：
 * signText = Appid + Timestamp + Body，以 APP_SECRET 為 key 做 HMAC-SHA256，輸出 hex。
 *
 * 三個實作要點（皆有實證依據，改動前請先讀 docs/integrations/payments/CCPayment/integration.md）：
 *
 * 1. 🔴 簽名用的 body 必須與送出的 body 逐字元相同。因此本 trait 自行 json_encode
 *    取得字串後，以 withBody() 原字串送出，不可改用 Http::asJson()->post($url, $array)
 *    ——那會讓 Laravel 自行序列化，與簽名字串未必一致（斜線與 Unicode 轉義差異）。
 * 2. 🔴 Timestamp 為秒級 10 位（實打 getCoinList 確認回 code 10000）。
 *    廠商 GitHub 文檔寫 milliseconds 是錯的，照那個實作會全部被判 11006 ReqExpired。
 * 3. 🔴 Timestamp 取一次、簽名與 header 共用。廠商 GitHub SDK 文檔的 Shell 範例在 generate_sign 內
 *    與 header 各取一次 date +%s，跨秒會導致簽名與 header 不一致而驗簽失敗。
 *
 * 另：User-Agent 為廠商規定的必填 header，缺少或無效可能被安全閘擋下回 403（api.md §2.1）。
 *
 * @knowledge rules/payment-cost-summary
 * @knowledge rules/provider-ccpayment
 */
trait CCPaymentTrait
{
    /** 三方 API 根路徑（info 鍵名） */
    protected string $apiUrlField = 'API_URL';

    /**
     * 業務碼：成功。
     *
     * 完整錯誤碼表見 docs/integrations/payments/CCPayment/api.md §3.1。
     * 代付的明確拒絕白名單（REJECT_CODES）與回調三態碼集屬資損級判定，
     * 於 Withdrawal / WithdrawalCallback 各自以類別常數列舉，不放這裡。
     */
    protected const SUCCESS_CODE = 10000;

    /** 廠商規定必填，缺少或無效可能被安全閘擋下回 403 */
    protected const USER_AGENT = 'CCPayment-Client/1.0';

    /**
     * 代收支付通道的三方（coinId, chain）：由 order type key 與商戶後台啟用的代幣清單決定。
     *
     * 代收的幣與鏈都跟著支付通道走：key 的格式是 CCPAYMENT.{幣別代號}_{三方鏈代號}
     * （ChannelKey），兩個都是三方後台的字面值；coinId 不寫在代碼裡，執行期依幣別代號向 getCoinList
     * （商戶在三方後台啟用的代幣，api.md §7.1）查，並確認該鏈在該幣的 networks 裡。也就是哪些幣、哪些鏈
     * 可用由三方後台控制：後台沒啟用，key 再怎麼寫都取不到、一律拋出（fail-closed，不猜幣也不猜鏈）。
     * 同一個商戶可以掛多條支付通道，各自一組（幣, 鏈）；商戶 info 只放憑證，不放幣別也不放鏈別
     * （代付的幣與鏈跟著提款銀行的三方代碼走，見 payoutSpec()）。
     *
     * 啟用代幣清單每商戶快取一天；快取裡找不到就重新向三方拉一次再判定，後台剛啟用的幣或鏈不必等快取過期。
     * 三方拉不到（連線失敗、回應形狀不符）同樣拋出，呼叫端各自決定怎麼降級：存款方式清單取址留空、
     * 回調不入帳並回非 200 讓三方重送、對帳跳過該通道。
     *
     * ETH_SEPOLIA 是三方唯一的測試網，測試幣 TETH 是獨立代幣，只給 Dev 驗收用；該 order type
     * 只在非正式庫連線宣告（Data/providers/CCPayment.php）。
     *
     * @return array{coin_id: int, chain: string}
     */
    protected function channelSpec(AgentCashFlowOrderType $channel): array
    {
        $key = (string) ($channel->orderType?->key ?? '');
        $parsed = ChannelKey::parse($key);

        if ($parsed === null) {
            throw new ApiException(sprintf(
                '%s payment channel %d order type key "%s" is not in the form CCPAYMENT.{symbol}_{chain}',
                $this->getDriverName(),
                $channel->id,
                $key
            ));
        }

        return [
            'coin_id' => $this->enabledCoinId($parsed['symbol'], $parsed['chain'], 'payment channel ' . $channel->id),
            'chain' => $parsed['chain'],
        ];
    }

    /**
     * 代付的幣與鏈：拆提款銀行的三方代碼 `{幣別代號}_{三方鏈代號}`（ChannelKey::coinChain()），
     * coinId 與代收同一套查法（同一份快取），並確認該鏈在三方後台有啟用。
     *
     * 格式不符或後台沒啟用一律拋出；呼叫端必須在送出代付請求之前呼叫，讓這類失敗落在
     * 「請求沒送出、訂單可重送」這一側，不會變成結果未知。
     *
     * @return array{coin_id: int, chain: string}
     */
    protected function payoutSpec(string $providerBankCode): array
    {
        $parsed = ChannelKey::parseCoinChain($providerBankCode);

        if ($parsed === null) {
            throw new ApiException(sprintf(
                '%s payout provider bank code "%s" is not in the form {symbol}_{chain}',
                $this->getDriverName(),
                $providerBankCode
            ));
        }

        return [
            'coin_id' => $this->enabledCoinId($parsed['symbol'], $parsed['chain'], 'payout bank code ' . $providerBankCode),
            'chain' => $parsed['chain'],
        ];
    }

    protected function channelChain(AgentCashFlowOrderType $channel): string
    {
        return $this->channelSpec($channel)['chain'];
    }

    /**
     * 依幣別代號取商戶後台啟用的 coinId，並確認該鏈在該幣的 networks 裡；快取裡找不到先重拉一次再判定。
     */
    private function enabledCoinId(string $symbol, string $chain, string $usedBy): int
    {
        $coin = $this->enabledCoins()[$symbol] ?? null;

        if ($coin === null || !in_array($chain, $coin['chains'], true)) {
            Cache::forget(CacheKeys::ccpaymentEnabledCoins((int) $this->agentCashFlow->id));
            $coin = $this->enabledCoins()[$symbol] ?? null;
        }

        if ($coin === null || !in_array($chain, $coin['chains'], true)) {
            throw new ApiException(sprintf(
                '%s coin %s on chain %s is not enabled for merchant %d (%s)',
                $this->getDriverName(),
                $symbol,
                $chain,
                $this->agentCashFlow->id,
                $usedBy
            ));
        }

        if ($coin['ambiguous'] ?? false) {
            throw new ApiException(sprintf(
                '%s coin %s maps to more than one coinId for merchant %d (%s)',
                $this->getDriverName(),
                $symbol,
                $this->agentCashFlow->id,
                $usedBy
            ));
        }

        return $coin['coin_id'];
    }

    /**
     * 商戶後台啟用的代幣（POST /getCoinList），整理成幣別代號 → coinId 與鏈代號清單；每商戶快取一天。
     * 代收（channelSpec）與代付（payoutSpec）共用這一份。
     *
     * @return array<string, array{coin_id: int, chains: list<string>, ambiguous?: bool}>
     */
    private function enabledCoins(): array
    {
        return Cache::safeRemember(
            CacheKeys::ccpaymentEnabledCoins((int) $this->agentCashFlow->id),
            CacheTtl::ONE_DAY->value,
            function (): array {
                $rows = $this->sendRequest('/getCoinList')['data']['coins'] ?? null;
                if (!is_array($rows)) {
                    throw new ApiException($this->getDriverName() . ' getCoinList returned unexpected response shape');
                }

                $coins = [];
                foreach ($rows as $row) {
                    if (!is_array($row) || !is_numeric($row['coinId'] ?? null) || !is_string($row['symbol'] ?? null)) {
                        continue;
                    }

                    $symbol = $row['symbol'];
                    $coinId = (int) $row['coinId'];

                    // api.md §7.1 沒保證幣別代號唯一。同代號出現兩個 coinId 時不能挑一個用（挑錯＝出錯幣），
                    // 整個代號標成 ambiguous，enabledCoinId() 一律拋出
                    if (isset($coins[$symbol]) && $coins[$symbol]['coin_id'] !== $coinId) {
                        Log::warning($this->getDriverName() . ' getCoinList 同一個幣別代號出現多個 coinId，該幣別停用', [
                            'agent_cash_flow_id' => $this->agentCashFlow->id,
                            'symbol' => $symbol,
                            'coin_ids' => [$coins[$symbol]['coin_id'], $coinId],
                        ]);
                        $coins[$symbol]['ambiguous'] = true;

                        continue;
                    }

                    $networks = $row['networks'] ?? null;
                    $coins[$symbol] = [
                        'coin_id' => $coinId,
                        'chains' => is_array($networks) ? array_map(strval(...), array_keys($networks)) : [],
                    ];
                }

                return $coins;
            },
        );
    }

    /**
     * 本 provider 發送請求所需的憑證鍵。
     *
     * @return list<string>
     */
    protected function requiredRequestCredentials(): array
    {
        return [$this->apiUrlField, 'APP_ID', 'APP_SECRET'];
    }

    /**
     * 發送請求（僅 HTTP 層檢查）。
     *
     * 🚫 不在此驗業務碼拋例外：代付的 performRequest 只允許「結果未知」例外
     * （連線／逾時／HTTP 非 2xx）流出（→ -1 鎖 23）；HTTP 200 的業務碼拒絕一律
     * 原樣回傳，交 Withdrawal 的 interpretResult 判定（→ -2 還原可重送）。
     * 需要「業務碼失敗即拋」的便利包裝時用 sendRequest()。
     *
     * @param  array<string, mixed>  $params  請求 body；空陣列送出 {}
     * @return array<string, mixed>
     */
    protected function sendRequestRaw(string $endpoint, array $params = []): array
    {
        $missing = $this->findMissingCredentials($this->requiredRequestCredentials(), 'request');
        if ($missing !== []) {
            throw new ApiException(sprintf(
                '%s credentials not configured: %s',
                $this->getDriverName(),
                implode(', ', $missing)
            ));
        }

        $url = rtrim((string) $this->info[$this->apiUrlField], '/') . $endpoint;

        // 空 body 送 {}，與廠商官方範例一致
        $body = $params === []
            ? '{}'
            : (string) json_encode($params, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        // timestamp 取一次，簽名與 header 共用（見類別註解第 3 點）
        $timestamp = (string) time();

        try {
            $result = Http::logAction($this->getDriverName() . '_sendRequest')
                ->withHeaders([
                    'Content-Type' => 'application/json',
                    'Appid' => (string) $this->info['APP_ID'],
                    'Timestamp' => $timestamp,
                    'Sign' => $this->generateSign($body, $timestamp),
                    'User-Agent' => self::USER_AGENT,
                ])
                ->withBody($body, 'application/json')
                ->post($url)
                ->throw()
                ->json();
        } catch (Exception $e) {
            throw new ApiException(
                sprintf('%s request failed: %s', $this->getDriverName(), $e->getMessage()),
                HttpCode::HTTP_INTERNAL_SERVER_ERROR->value,
                $e
            );
        }

        return $result ?? [];
    }

    /**
     * 發送請求並驗業務碼（代收與唯讀查詢用的便利包裝）。
     *
     * 🚫 代付一律走 sendRequestRaw()，不得用本方法——業務碼拒絕在此被轉成例外，
     * 會讓代付把「三方明確未受理」誤判成「結果未知」而鎖 23。
     *
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    protected function sendRequest(string $endpoint, array $params = []): array
    {
        $result = $this->sendRequestRaw($endpoint, $params);

        $code = $result['code'] ?? null;

        // 型別閘門：缺鍵／null／非數值皆屬結構缺失，不可當成業務拒絕
        if (!is_numeric($code) || (int) $code !== self::SUCCESS_CODE) {
            throw new ApiException(sprintf(
                '%s %s error [%s]: %s',
                $this->getDriverName(),
                $endpoint,
                is_scalar($code) ? (string) $code : 'no-code',
                is_scalar($result['msg'] ?? null) ? (string) $result['msg'] : 'Unknown error'
            ));
        }

        return $result;
    }

    /**
     * 查詢單筆存款紀錄（api.md §4.2，POST /getAppDepositRecord）。
     *
     * 🔴 代收回調必須呼叫本方法二次確認，不可只信 webhook：廠商明文
     * 「never trust a webhook alone」「Do not treat the webhook status as the final
     * status — this may result in asset loss」（api.md §0.1、§5.4 廠商警告二）。
     * 永久地址模式的 DirectDeposit 回調，回查端點就是 getAppDepositRecord（api.md §5.4）。
     *
     * @return array<string, mixed>
     */
    protected function queryDepositRecord(string $recordId): array
    {
        return $this->sendRequest('/getAppDepositRecord', ['recordId' => $recordId]);
    }

    /**
     * 查詢單筆代付紀錄（api.md §6.4，POST /getAppWithdrawRecord）。
     *
     * 🔴 代付回調同樣須二次確認；另於「送出請求但未收到回應」時作為查證管道，
     * 廠商建議以此確認提現是否已成功處理（api.md §6.1 註記）。
     *
     * @return array<string, mixed>
     */
    protected function queryWithdrawRecord(string $recordId): array
    {
        return $this->sendRequest('/getAppWithdrawRecord', ['recordId' => $recordId]);
    }

    /**
     * 取得或建立永久收款地址（api.md §4.1，POST /getOrCreateAppDepositAddress）。
     *
     * 同一個 referenceId 重複呼叫回同一個地址（冪等），所以「取址成功但我方寫表失敗」
     * 重試即可，不會多占配額。notifyUrl 綁定於地址、持久有效，一律帶我方含金流商戶 id 的回調網址。
     *
     * @return array<string, mixed> 回應的 data，含 address；memo 只有需要 memo 的鏈才有
     */
    protected function getOrCreateDepositAddress(string $referenceId, string $chain, string $notifyUrl): array
    {
        return $this->extractData(
            $this->sendRequest('/getOrCreateAppDepositAddress', [
                'referenceId' => $referenceId,
                'chain' => $chain,
                'notifyUrl' => $notifyUrl,
            ]),
            '/getOrCreateAppDepositAddress'
        );
    }

    /**
     * 解除地址與 referenceId 的綁定（api.md §4.5，POST /addressUnbinding）。
     *
     * 解綁不釋放配額；解綁後轉進舊地址的款仍會記入我方帳戶；同一 referenceId 再取址會發新地址。
     *
     * @return array<string, mixed> 回應的 data，含 unbound 清單與原綁定的 referenceID
     */
    protected function unbindAddress(string $chain, string $address): array
    {
        return $this->extractData(
            $this->sendRequest('/addressUnbinding', ['chain' => $chain, 'address' => $address]),
            '/addressUnbinding'
        );
    }

    /**
     * 查詢存款紀錄清單（api.md §4.3，POST /getAppDepositRecordList），對帳排程用。
     *
     * @param  array<string, mixed>  $params  時間範圍與 nextId 等查詢條件，原樣送出
     * @return array<string, mixed> 回應的 data，含 records 與 nextId（超過 20 筆分頁）
     */
    protected function listDepositRecords(array $params): array
    {
        return $this->extractData($this->sendRequest('/getAppDepositRecordList', $params), '/getAppDepositRecordList');
    }

    /**
     * 要求金流商重送 webhook（api.md §5.6，POST /webhook/resend）。
     *
     * 重送的 webhook 走我方原本的回調路徑，補單不必另開入帳寫入路徑。
     * recordIds 單批最多 50 筆；startTimestamp 必填且與 endTimestamp 最多相隔 1 小時。
     *
     * @param  array<string, mixed>  $params  原樣送出
     * @return array<string, mixed> 回應的 data，含 resendCount
     */
    protected function resendWebhooks(array $params): array
    {
        return $this->extractData($this->sendRequest('/webhook/resend', $params), '/webhook/resend');
    }

    /**
     * 回調驗簽（api.md §5.7）：三種 webhook（DirectDeposit、ApiWithdrawal、風險地址）共用同一套。
     *
     * 簽名字串＝我方保存的 APP ID＋header Timestamp＋收到的原始 body，HMAC-SHA256 後 hex；
     * 以常數時間比較，不照廠商範例用 `===`。
     *
     * ! 刻意不檢查 Timestamp 時效：規格寫 2 分鐘內有效，但重送最晚在首發 30 分鐘後，
     * ! 重送會不會換新 Timestamp 尚未實測；重放對我方無害（回調只當觸發訊號，一律回查且以
     * ! recordId 冪等）。實測確認重送行為後再決定要不要加時效檢查。
     *
     * @param  string  $rawBody  收到的原始 body 字串，不可重新序列化
     */
    protected function verifyWebhookSignature(string $rawBody, ?string $timestamp, ?string $sign): bool
    {
        if ($timestamp === null || $timestamp === '' || $sign === null || $sign === '') {
            return false;
        }

        return hash_equals($this->generateSign($rawBody, $timestamp), $sign);
    }

    /**
     * 回調入口守衛：驗簽憑證齊全且簽名正確才往下走，代收、代付、風險地址三種 webhook 共用。
     *
     * 憑證缺漏走 CredentialGuardTrait（fail-closed）；驗簽失敗拋出讓回應非 HTTP 200，金流商會重送，
     * 不會因為一次簽名錯誤而漏掉款項。
     *
     * @param  string  $context  日誌情境，如 deposit callback
     *
     * @throws ApiException 憑證未設定或驗簽失敗
     */
    protected function assertWebhookRequest(Request $request, string $context): void
    {
        $missing = $this->findMissingCredentials(['APP_ID', 'APP_SECRET'], $context);
        if ($missing !== []) {
            throw new ApiException(sprintf(
                '%s %s credentials not configured: %s',
                $this->getDriverName(),
                $context,
                implode(', ', $missing)
            ));
        }

        $timestamp = $request->header('Timestamp');
        $sign = $request->header('Sign');

        if (!$this->verifyWebhookSignature(
            (string) $request->getContent(),
            is_string($timestamp) ? $timestamp : null,
            is_string($sign) ? $sign : null
        )) {
            Log::warning(sprintf('%s %s signature verification failed', $this->getDriverName(), $context), [
                'ip' => $request->ip(),
                'has_timestamp' => is_string($timestamp) && $timestamp !== '',
                'has_sign' => is_string($sign) && $sign !== '',
            ]);

            throw new ApiException(sprintf('%s %s signature verification failed', $this->getDriverName(), $context));
        }
    }

    /**
     * 生成簽名：HMAC-SHA256(Appid + Timestamp + Body, APP_SECRET)，輸出 hex。
     *
     * @param  string  $body  實際送出的 body 字串（必須與簽名字串來源相同）
     */
    protected function generateSign(string $body, string $timestamp): string
    {
        $signText = (string) $this->info['APP_ID'] . $timestamp . $body;

        return hash_hmac('sha256', $signText, (string) $this->info['APP_SECRET']);
    }

    /**
     * 自成功回應取出 data 並驗形狀：缺 data 或非陣列一律拋出，不回半套資料讓呼叫端誤判。
     *
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    private function extractData(array $result, string $endpoint): array
    {
        $data = $result['data'] ?? null;

        if (!is_array($data)) {
            throw new ApiException(sprintf(
                '%s %s returned unexpected response shape',
                $this->getDriverName(),
                $endpoint
            ));
        }

        return $data;
    }

    /**
     * 回查紀錄的手續費（代幣數量）換算成站點幣別的金流商手續費，寫進訂單的 payment_cost。
     *
     * CCPayment 的服務費是階梯式浮動，要等回查拿到該筆的實際值才知道多少，所以不走共用流程的
     * 「點數 × 成本費率」（AgentCashFlow::paymentCostFor()）。截位規則與它相同：截到小數第 4 位、不四捨五入。
     *
     * @param  mixed  $fee  回查紀錄裡的手續費原值（存款 serviceFee、提款 fee.amount）
     * @param  string  $rate  訂單的匯率（代幣 → 站點幣別）
     * @return string|null 手續費缺漏、不是數字或為負時回 null，呼叫端退回成本費率算法
     */
    protected function actualPaymentCost(mixed $fee, string $rate): ?string
    {
        if (!is_numeric($fee)) {
            return null;
        }

        $fee = Common::numAdd('0', (string) $fee, 8);
        if (Common::numCompare($fee, '0', 8) < 0) {
            return null;
        }

        return Common::numFloor(Common::numMul($fee, Common::numAdd('0', $rate, 9), 8), 4);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    protected function alertAndLog(string $message, array $context): void
    {
        Log::warning($this->getDriverName() . ' ' . $message, $context);

        BotNotify::channel('warn')->send(sprintf(
            '%s %s %s',
            $this->getDriverName(),
            $message,
            Common::safeJsonEncode($context)
        ));
    }
}
