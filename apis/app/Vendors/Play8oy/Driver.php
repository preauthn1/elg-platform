<?php

declare(strict_types=1);

namespace App\Vendors\Play8oy;

use App\Data\GameInfoData;
use App\Data\Vendor\CreateAccountParamsData;
use App\Data\Vendor\CreateAccountResultData;
use App\Data\Vendor\GetGameListParamsData;
use App\Data\Vendor\GetGameListResultData;
use App\Data\VendorGameUrlParamsData;
use App\Data\VendorGameUrlResultAppInfoData;
use App\Data\VendorGameUrlResultData;
use App\Data\VendorResetPasswordData;
use App\Data\VendorTransferData;
use App\Enums\Game\GameTypeEnum;
use App\Enums\Transaction\TransactionEnum;
use App\Enums\Vendor\VendorTransferResultEnum;
use App\Exceptions\ApiException;
use App\Helpers\Common;
use App\Models\Member;
use App\Models\TransactionLog;
use App\Models\WalletTransactionLog;
use App\Vendors\AbstractTransferWallet;
use App\Vendors\Contracts\ShouldScheduleAccountCreation;
use App\Vendors\Contracts\ShouldSyncGameList;
use App\Vendors\Models\Play8oyAccount;
use App\Vendors\Models\Play8oyBetRecord;
use App\Vendors\Play8oy\Enums\TransactionResponseCodeEnum;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Play8oy（vendor 30，iGaming 平台）轉帳錢包
 *
 * 🔴 `App\Vendors\Suncity\Driver` 繼承本類（同平台 Clubsuncity 品牌、同一份 API 規格）：
 * 改動本類必須同時跑 `tests/Feature/Vendors/Play8oy` 與 `tests/Feature/Vendors/Suncity`。
 *
 * 開放給子類的切點：`$appScheme`（App deep-link scheme）、`$actionTitle`（HTTP log action 與 log 前綴）、
 * `newAccountAttributes()`（本地帳號列欄位，表結構不同時覆寫）。
 *
 * 🔴 三方請求一律經 `postJson()`＋`decode()`，禁止 `->throw()`：iGaming 業務碼走 HTTP 400，
 * throw() 會把明確拒絕攔成例外、轉帳卡 8888 不退款（docs/integrations/vendors/Suncity/integration.md §5）。
 *
 * `GAME_TYPE_NAME_MAP`／`LEGACY_UNCATEGORIZED_TYPE_ID` 依 Play8oy 歷史分類整理，SunCity 刻意沿用
 * （同平台同遊戲清單）；若兩家的 GameTypeName 分歧，再改為可覆寫。
 */
class Driver extends AbstractTransferWallet implements ShouldScheduleAccountCreation, ShouldSyncGameList
{
    /**
     * 舊系統未分類遊戲寫 type_id=0（共用 DB 既有慣例，維持一致）
     */
    private const int LEGACY_UNCATEGORIZED_TYPE_ID = 0;

    /**
     * 三方 GameTypeName → 平台遊戲類型（target/op_tickets#2866）
     *
     * 依既有已分類遊戲的 remark（即 GameTypeName）比對歷史人工分類慣例得出，僅收錄
     * 有壓倒性多數共識的類型；ARCADE MULTI/ARCADE SINGLE/CASINO SINGLE 歷史分類本身
     * 不一致（無明顯多數），刻意不列入，維持寫 0 待業務判斷，不代為收斂。
     */
    /**
     * iGaming 承載業務碼的 HTTP 狀態：成功 200、業務錯誤 400，見 {@see carriesBusinessCode()}
     */
    private const array BUSINESS_CODE_HTTP_STATUSES = [200, 400];

    private const array GAME_TYPE_NAME_MAP = [
        'SLOT' => GameTypeEnum::SLOTS->value,
        'SLOT JP' => GameTypeEnum::SLOTS->value,
        'FISH' => GameTypeEnum::FISHING->value,
        'HG Live' => GameTypeEnum::LIVE_CASINO->value,
        'CASINO MULTI' => GameTypeEnum::TABLE->value,
        'Lobby' => GameTypeEnum::EVENT_AND_OTHERS->value,
        'Slot Activity' => GameTypeEnum::EVENT_AND_OTHERS->value,
        'Other' => GameTypeEnum::EVENT_AND_OTHERS->value,
    ];

    protected string $accountModel = Play8oyAccount::class;

    protected string $betRecordModel = Play8oyBetRecord::class;

    protected string $actionTitle = 'PLAY8OY';

    /**
     * iGaming 平台各品牌 App 自動登入的 deep-link scheme（API 文件附錄 5）：
     * 組成 `{scheme}://{scheme}?account=…&password=[REDACTED]
     */
    protected string $appScheme = 'lobbyplay8oy';

    protected string $apiMerchantName;

    protected string $apiUrl;

    protected string $apiAgent;

    protected string $apiKey;

    protected bool $getDownloadUrl;

    protected string $iosDownloadUrl;

    protected string $androidDownloadUrl;

    /**
     * 是否驗證三方 TLS 憑證（info.VERIFY_SSL，缺省 true）
     *
     * 🔴 只允許 staging／測試商戶設 false，正式商戶禁止：iGaming staging `*.example.invalid`
     * 的憑證自 2025-09-29 過期，驗證會 cURL 60 失敗；正式環境關閉驗證等於放行中間人攻擊。
     */
    protected bool $verifySsl = true;

    public function __construct(int $vendorId, ?Member $member)
    {
        parent::__construct($vendorId, $member);

        if (!$member) {
            return;
        }

        $this->setInfo($this->vendorInfo->info);
    }

    public function setInfo(array $info): void
    {
        $this->apiUrl = $info['API_URL'];
        $this->apiMerchantName = $info['API_MERCHANT_NAME'];
        $this->apiAgent = $info['API_AGENT'];
        $this->apiKey = [REDACTED]'API_KEY'];
        $this->getDownloadUrl = $info['GET_DOWNLOAD_URL'] ?? false;
        $this->iosDownloadUrl = $info['IOS_DOWNLOAD_URL'] ?? '';
        $this->androidDownloadUrl = $info['ANDROID_DOWNLOAD_URL'] ?? '';
        // 缺省驗證憑證（fail-closed）；只有 staging 商戶明確設 false 才關閉，見 $verifySsl
        $this->verifySsl = (bool) ($info['VERIFY_SSL'] ?? true);
    }

    /**
     * 本 Driver（含子類）所有出站三方請求的唯一入口：掛 logAction，VERIFY_SSL=false 時關閉憑證驗證
     *
     * 禁止在各呼叫點自行判斷 withoutVerifying()，一律經本方法
     */
    protected function http(string $action): PendingRequest
    {
        $request = Http::logAction($action);

        return $this->verifySsl ? $request : $request->withoutVerifying();
    }

    /**
     * 帶 merchantName／Sign header 對三方 POST，**不 throw()**
     *
     * 🔴 禁止在本方法或呼叫點加 `->throw()`：iGaming 的業務錯誤碼走 HTTP 400（成功才 200），
     * throw() 會把明確拒絕（如 -90607 商戶額度不足）攔成 RequestException，轉帳被當結果不明卡 8888 不退款。
     * HTTP 狀態一律交 {@see decode()} 判斷。
     *
     * @param  array<string, mixed>  $data
     */
    protected function postJson(string $action, string $path, array $data = []): Response
    {
        return $this->http($action)
            ->withHeaders([
                'merchantName' => $this->apiMerchantName,
                'Sign' => $this->sign($data),
            ])
            ->post($this->apiUrl . $path, $data);
    }

    /**
     * 解析三方回應：5xx 或非 JSON 回 null（結果不明），其餘（含 4xx）回 JSON 陣列交業務碼判斷
     *
     * iGaming 的業務碼走 HTTP 400，不可用 throw() 擋掉：400 帶 `Code` 是三方明確的業務結果，
     * 必須讓呼叫端依 Code 分類。只有 5xx 與非 JSON（含 WAF 的 HTML 403）才算不可知。
     *
     * @return array<string, mixed>|null
     */
    protected function decode(Response $response): ?array
    {
        if ($response->serverError()) {
            return null;
        }

        $json = $response->json();

        return is_array($json) ? $json : null;
    }

    /**
     * 回應的 HTTP 狀態是否承載 iGaming 業務碼（只有 200／400）
     *
     * iGaming 只用 200／400 承載業務碼，其他 4xx（gateway／WAF）即使 body 帶拒絕碼也視為結果不明：
     * 轉帳與補查的 FAILED 會觸發立即退款，只能來自 200／400 帶明確拒絕碼。
     */
    protected function carriesBusinessCode(Response $response): bool
    {
        return in_array($response->status(), self::BUSINESS_CODE_HTTP_STATUSES, true);
    }

    public function getGameUrl(VendorGameUrlParamsData $params): VendorGameUrlResultData
    {
        $action = $this->actionTitle . '_api_getGameUrl';
        $username = $this->vendorAccount->username;
        $password = [REDACTED], $this->vendorAccount->created_at->toDateTimeString());
        $android_download = $this->androidDownloadUrl;
        $ios_download = $this->iosDownloadUrl;

        // info 的 GET_DOWNLOAD_URL 為 true 時，呼叫三方取得 app 下載網址
        if ($this->getDownloadUrl) {
            $response = $this->postJson($action, 'appurl');
            $json = $this->decode($response);
            if ($json === null) {
                throw new ApiException('Third party error: not json response (HTTP ' . $response->status() . ')');
            }

            if (!isset($json['Code']) || !is_numeric($json['Code'])) {
                throw new ApiException('Third party error: format error');
            }

            if ((string) $json['Code'] != '0') {
                $code = $json['Code'];
                throw new ApiException('Third party error: error code: ' . $code);
            }

            $android_download = $json['Data']['Android'];
            $ios_download = $json['Data']['iOS'];
        }

        // app open url
        $app_open_url = $this->appScheme . '://' . $this->appScheme . '?account=' . $username . '&password=' . $password;

        $appInfo = VendorGameUrlResultAppInfoData::from([
            'username' => $username,
            'password' => $password,
            'ios_downloads' => [$ios_download],
            'ios_open' => $app_open_url,
            'android_downloads' => [$android_download],
            'android_open' => $app_open_url,
            'app_icon' => $this->vendor->app_icon,
        ]);

        return VendorGameUrlResultData::from([
            'app_infos' => $appInfo,
        ]);
    }

    /**
     * 取得餘額
     */
    public function getBalance(): string
    {
        $action = $this->actionTitle . '_getBalance';
        $username = $this->vendorAccount->username;

        $data = [];
        $data['PlayerAccount'] = $username;
        $response = $this->postJson($action, 'player/info', $data);
        $json = $this->decode($response);

        if ($json === null) {
            throw new ApiException('Third party error: not json response (HTTP ' . $response->status() . ')');
        }

        if (!isset($json['Code']) || !is_numeric($json['Code'])) {
            throw new ApiException('Third party error: format error');
        }

        if ((string) $json['Code'] != '0') {
            $code = $json['Code'];
            throw new ApiException('Third party error: error code: ' . $code);
        }

        if (!isset($json['Data']['Balance'])) {
            throw new ApiException('Third party error: format error');
        }

        return (string) $json['Data']['Balance'];
    }

    /**
     * 轉帳（transaction/create）
     *
     * 分類原則：不確定就不自動退款；只有三方明確拒絕才 FAILED。FAILED 會讓 MemberTransferService
     * 立即退款，三方若其實已入帳就雙重入帳，所以 -90002／-90003／-90500／-90501 與未知碼一律 TIMEOUT
     * 交給補查與人工確認。-90610（我方交易號已存在）也不算失敗：代表前一次請求可能已成功。
     * 碼表與分類見 {@see TransactionResponseCodeEnum}。
     */
    protected function transfer(string $username, int $type, string $credit, string $billNo): VendorTransferResultEnum
    {
        if ($type == 1) {
            // 存款
            $action = $this->actionTitle . '_Deposit';
            $amount = (float) Common::numAdd('0', $credit);
        } else {
            // 提款
            $action = $this->actionTitle . '_Withdrawal';
            $amount = (float) Common::numSub('0', $credit);
        }

        // 準備轉帳取轉帳編號
        $data = [];
        $data['PlayerAccount'] = $username;
        $data['Amount'] = $amount;
        $data['ExternalTransactionId'] = $billNo;

        // 業務碼走 HTTP 400（明確拒絕也是 400）；5xx／非 JSON／200、400 以外的狀態一律結果不明
        $response = $this->postJson($action, 'transaction/create', $data);
        $json = $this->decode($response);

        if ($json === null || !$this->carriesBusinessCode($response)) {
            return VendorTransferResultEnum::TIMEOUT;
        }

        if (!isset($json['Code']) || !is_numeric($json['Code'])) {
            throw new ApiException('Third party error: format error');
        }

        // 只有三方明確拒絕才 FAILED（會觸發立即退款），結果不明一律 TIMEOUT，見 TransactionResponseCodeEnum
        return TransactionResponseCodeEnum::transferResult((string) $json['Code']);
    }

    /**
     * 玩家餘額轉入遊戲商 (存款)
     */
    public function transferToVendor(VendorTransferData $params): VendorTransferResultEnum
    {
        return $this->transfer(
            username: $this->vendorAccount->username,
            type: 1,
            credit: $params->vendorAmount,
            billNo: $params->transactionNo,
        );
    }

    /**
     * 轉出遊戲商餘額回本地 (提款)
     */
    public function transferToLocal(VendorTransferData $params): VendorTransferResultEnum
    {
        return $this->transfer(
            username: $this->vendorAccount->username,
            type: 2,
            credit: $params->vendorAmount,
            billNo: $params->transactionNo,
        );
    }

    /**
     * 檢查轉帳狀態（transaction/check）
     *
     * 只有三方明確回報失敗（-90001）或交易不存在（-90609）才 FAILED；尚未處理（-90608）、
     * 例外錯誤（-90003）與未知碼一律 TIMEOUT，不確定就不自動退款。
     */
    public function checkTransferStatus(string $transactionNo): VendorTransferResultEnum
    {
        $action = $this->actionTitle . '_CheckTransferStatus';
        $username = $this->vendorAccount->username;
        $data = [];
        $data['PlayerAccount'] = $username;
        $data['ExternalTransactionId'] = $transactionNo;

        // 業務碼走 HTTP 400（-90608／-90609／-90001 皆為 400）；5xx／非 JSON／200、400 以外的狀態一律結果不明
        $response = $this->postJson($action, 'transaction/check', $data);
        $json = $this->decode($response);

        if ($json === null || !$this->carriesBusinessCode($response)) {
            return VendorTransferResultEnum::TIMEOUT;
        }
        if (!isset($json['Code']) || !is_numeric($json['Code'])) {
            throw new ApiException('Third party error: format error, code not found');
        }

        // -90001 失敗、-90609 交易不存在 → FAILED；-90608 尚未處理、-90003 例外與未知碼 → TIMEOUT
        return TransactionResponseCodeEnum::checkResult((string) $json['Code']);
    }

    /**
     * 修改密碼
     */
    public function resetPassword(VendorResetPasswordData $params): VendorTransferResultEnum
    {
        $action = $this->actionTitle . '_ResetPassword';
        $username = $this->vendorAccount->username;
        $newPassword = [REDACTED];
        $data = [];
        $data['PlayerAccount'] = $username;
        $data['Password'] = $newPassword;

        $response = $this->postJson($action, 'player/resetpassword', $data);
        $json = $this->decode($response);

        if ($json === null) {
            throw new ApiException('Third party error: not json response (HTTP ' . $response->status() . ')');
        }

        if (!isset($json['Code']) || !is_numeric($json['Code'])) {
            throw new ApiException('Third party error: format error');
        }

        if ((string) $json['Code'] != '0') {
            return VendorTransferResultEnum::FAILED;
        }

        return VendorTransferResultEnum::SUCCESS;
    }

    /**
     * 遊戲清單（GameTypeName 存 remark 比照既有資料，並依 GAME_TYPE_NAME_MAP 查表對應 type_id）
     */
    public function getGameList(GetGameListParamsData $params): GetGameListResultData
    {
        $this->setVendorInfo($params->vendorInfo);

        $response = $this->postJson($this->actionTitle . '_getGameList', 'codetable/gamelist');
        $json = $this->decode($response);

        if ($json === null) {
            throw new ApiException('Third party error: not json response (HTTP ' . $response->status() . ')');
        }

        if ((string) ($json['Code'] ?? '') !== '0') {
            throw new ApiException('Third party error: (' . ($json['Code'] ?? '') . ') ' . ($json['Message'] ?? ''));
        }

        $data = [];
        $unknownTypeNames = [];
        foreach ($json['Data'] ?? [] as $game) {
            $gameCode = (string) $game['GameID'];
            $gameTypeName = $game['GameTypeName'] ?? '';
            $gameType = self::GAME_TYPE_NAME_MAP[$gameTypeName] ?? self::LEGACY_UNCATEGORIZED_TYPE_ID;

            if ($gameType === self::LEGACY_UNCATEGORIZED_TYPE_ID && $gameTypeName !== '') {
                $unknownTypeNames[$gameTypeName] = $gameCode;
            }

            $data[$gameCode] = GameInfoData::from([
                'gameCode' => $gameCode,
                'gameName' => $game['GameName'],
                'imageUrl' => '',
                'gameType' => $gameType,
                'status' => 1,
                'remark' => $game['GameTypeName'] ?? null,
                'info' => json_encode($game),
            ]);
        }

        if (!empty($unknownTypeNames)) {
            Log::warning($this->actionTitle . ' 遊戲清單出現對應表外的遊戲類型', [
                'vendor_info_id' => $params->vendorInfo->id,
                'unknown_type_names' => $unknownTypeNames,
            ]);
        }

        return GetGameListResultData::from(['gameList' => $data]);
    }

    /**
     * 簽名（API 文件 2.2）：參數名升冪、以原始值串成 `key=value&…&PrivateKey={key}`，SHA256 raw → Base64
     *
     * 必須以原始字串串接，禁止改回 http_build_query：它會把值 URL 編碼（空格→+、冒號→%3A），
     * game/log/record 的 StartDate／EndDate 因此與廠商驗簽不符；純英數參數兩者輸出相同
     *
     * @param  array<string, mixed>  $data
     */
    protected function sign(array $data = []): string
    {
        if (count($data) > 0) {
            ksort($data);
            $pairs = [];
            foreach ($data as $key => $value) {
                $pairs[] = $key . '=' . (string) $value;
            }
            $string = implode('&', $pairs) . '&PrivateKey=' . $this->apiKey;
        } else {
            $string = 'PrivateKey=' . $this->apiKey;
        }
        $hash_hmac = hash('sha256', $string, true);

        return base64_encode($hash_hmac);
    }

    /**
     * 長度限制最大20
     */
    public function getTransactionNo(?TransactionEnum $transactionEnum = null): string
    {
        if ($this->member->is_event_wallet || $this->agent->is_multi_wallet) {
            $logModel = WalletTransactionLog::query();
        } else {
            $logModel = TransactionLog::query();
        }

        $transactionNo = str_replace('-', '', Str::uuid7()->toString());
        $transactionNo = substr($transactionNo, 0, 20);

        $isExist = $logModel->where('transaction_no', $transactionNo)->exists();

        return $isExist ? $this->getTransactionNo($transactionEnum) : $transactionNo;
    }

    /**
     * 建立我方遊戲商帳號（遷自 RD-Studio create_play8oy_account）
     *
     * 本 driver 無 password 欄，登入密碼由 getGameUrl 以 getDefaultPassword 即時重算，
     * 建號送三方的密碼必須同式。
     */
    public function createAccount(CreateAccountParamsData $params): CreateAccountResultData
    {
        $this->setVendorInfo($params->vendorInfo);
        $username = $params->username;

        $account = $this->accountModel::create($this->newAccountAttributes($params));

        if (!$account) {
            return CreateAccountResultData::from(['status' => false, 'username' => $username]);
        }

        $password = [REDACTED], $account->created_at->toDateTimeString());

        if (!$this->createVendorAccount($username, $password)) {
            return CreateAccountResultData::from(['status' => false, 'username' => $username]);
        }

        $account->update(['member_id' => 0]);

        return CreateAccountResultData::from(['status' => true, 'username' => $username]);
    }

    /**
     * 本地帳號列屬性；子類別（不同表結構）覆寫
     *
     * member_id 先以 -999999999 佔位，三方建號成功才於 createAccount() 翻成 0 釋出到帳號池
     *
     * @return array<string, mixed>
     */
    protected function newAccountAttributes(CreateAccountParamsData $params): array
    {
        return [
            'vendor_info_id' => $params->vendorInfo->id,
            'username' => $params->username,
            // username_modified 為 NOT NULL 無預設；舊系統存的是小寫 agent + username
            'username_modified' => strtolower($this->apiAgent) . $params->username,
            'admin' => 1,
            'member_id' => -999999999,
        ];
    }

    /**
     * 建立三方遊戲商帳號
     */
    private function createVendorAccount(string $username, string $password): bool
    {
        // Agent 送原樣大小寫（僅 username_modified 才小寫化）；sign() 內部會 ksort
        $data = [
            'PlayerAccount' => $username,
            'Password' => $password,
            'Agent' => $this->apiAgent,
        ];

        try {
            $response = $this->postJson($this->actionTitle . '_createAccount', 'player/create', $data);
        } catch (Throwable $e) {
            Log::error('[' . $this->actionTitle . '][createAccount] request failed', [
                'username' => $username,
                'error' => $e->getMessage(),
                'trace' => Common::formatTrace($e),
            ]);

            return false;
        }

        // 業務碼走 HTTP 400：5xx／非 JSON 與非 0 碼一律視為建號失敗
        $json = $this->decode($response);

        if ($json === null || (string) ($json['Code'] ?? '') !== '0') {
            Log::error('[' . $this->actionTitle . '][createAccount] Third party error', [
                'username' => $username,
                'http_status' => $response->status(),
                'code' => $json['Code'] ?? null,
                'message' => $json['Message'] ?? null,
            ]);

            return false;
        }

        return true;
    }
}
