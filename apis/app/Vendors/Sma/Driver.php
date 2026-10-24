<?php

declare(strict_types=1);

namespace App\Vendors\Sma;

use App\Data\GameInfoData;
use App\Data\Vendor\CreateAccountParamsData;
use App\Data\Vendor\CreateAccountResultData;
use App\Data\Vendor\GetGameListParamsData;
use App\Data\Vendor\GetGameListResultData;
use App\Data\VendorGameUrlParamsData;
use App\Data\VendorGameUrlResultData;
use App\Data\VendorTransferData;
use App\Enums\Game\GameTypeEnum;
use App\Enums\General\ContextKeyEnum;
use App\Enums\General\HttpCode;
use App\Enums\LanguageEnum;
use App\Enums\Vendor\VendorTransferResultEnum;
use App\Exceptions\ApiException;
use App\Helpers\Common;
use App\Models\Member;
use App\Vendors\AbstractTransferWallet;
use App\Vendors\Contracts\ShouldScheduleAccountCreation;
use App\Vendors\Contracts\ShouldSyncGameList;
use App\Vendors\Models\SmaAccount;
use App\Vendors\Models\SmaBetRecord;
use Exception;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Slotmania 77
 *
 * Neil: 如果時間戳的時間太過接近當下時間 他們API會回傳錯誤 所以減個3秒
 *
 * @knowledge rules/game-list-sync
 */
class Driver extends AbstractTransferWallet implements ShouldScheduleAccountCreation, ShouldSyncGameList
{
    /**
     * 三方 gameList 的 `type` → 站內遊戲類型；表外的值落「活動與其他」並記 warning
     */
    private const array GAME_TYPE_MAP = [
        'slots' => GameTypeEnum::SLOTS,
        'fishing' => GameTypeEnum::FISHING,
    ];

    protected string $accountModel = SmaAccount::class;

    protected string $betRecordModel = SmaBetRecord::class;

    private string $apiUrl;

    private string $appId;

    private string $secretKey;

    private string $closeBack;

    private string $backUrl;

    /** 排程情境（無 member）不會經建構子注入帳號，給預設值避免 throwError() 讀未初始化屬性 */
    private string $username = '';

    private string $token;

    public function __construct(int $vendorId, ?Member $member)
    {
        parent::__construct($vendorId, $member);

        if (!$member) {
            return;
        }

        // Config
        $this->setInfo($this->vendorInfo->info);

        // Account
        $account = $this->vendorAccount;
        $this->username = $account->username ?? '';
        $this->token = [REDACTED] ?? '';
    }

    public function setInfo(array $info): void
    {
        $this->apiUrl = $info['API_URL'];
        $this->appId = $info['API_APPID'];
        $this->secretKey = $info['API_SECRET_KEY'];
        $this->backUrl = $info['BACK_URL'] ?? '1';
        $this->closeBack = $info['CLOSE_BACK'] ?? '1';
    }

    public function getGameUrl(VendorGameUrlParamsData $params): VendorGameUrlResultData
    {
        $token = [REDACTED];

        $isValidToken = [REDACTED], $token);

        if (!$isValidToken) {
            $token = [REDACTED];
        }

        if (!$token) {
            $this->throwError('Login failed');
        }

        $this->vendorAccount->update(['token' => $token]);

        $data = [
            'timestamp' => now()->subSeconds(3)->getPreciseTimestamp(3),
            'appId' => $this->appId,
            'back' => base64_encode($this->backUrl),
            'token' => $token,
            'id' => $params->gameCode,
            'lang' => $this->mapLanguageCode(),
            'closeBack' => $this->closeBack,
        ];

        $data = $this->generateSignature($data);

        $gameUrl = $this->apiUrl . 'api/v2/ext/player/openGame?' . http_build_query($data);

        return VendorGameUrlResultData::from([
            'pc' => $gameUrl,
            'mobile' => $gameUrl,
        ]);
    }

    public function getBalance(): string
    {
        $data = [
            'timestamp' => now()->subSeconds(3)->getPreciseTimestamp(3),
            'appId' => $this->appId,
            'username' => $this->username,
        ];

        $data = $this->generateSignature($data);

        $result = $this->sendRequest('/api/v2/ext/wallet/queryBalance', $data);

        if (!is_array($result)) {
            $this->throwError('API response is not an array', ['response' => $result]);
        }

        if (!isset($result['code']) || (string) $result['code'] != '200') {
            $this->throwError($result['msg'] ?? 'Unknown error');
        }

        if (!isset($result['data']) || !is_numeric($result['data'])) {
            $this->throwError('Invalid balance data', ['response' => $result]);
        }

        // 無條件捨去 $result['data'] 到小數第二位
        $balance = floor($result['data'] * 100) / 100;

        return Common::numFloor($balance, 2);
    }

    /**
     * 遊戲清單（/api/v2/ext/gameList，遷自 RD-Studio get_sma_games）
     *
     * 只回三方現行清單，寫入交給 GameListSyncService（純新增；DB 有、API 無僅偵測提示不停用）；
     * 舊指令「先全關再開回」與「名稱不同就連圖一起蓋」的行為皆不沿用，既有遊戲欄位由後台人工維護。
     */
    public function getGameList(GetGameListParamsData $params): GetGameListResultData
    {
        $this->setVendorInfo($params->vendorInfo);

        $data = [
            // 時間戳太貼近當下會被三方退回，比照舊指令回退數秒
            'timestamp' => now()->subSeconds(5)->getPreciseTimestamp(3),
            'appId' => $this->appId,
        ];

        $data = $this->generateSignature($data);

        $result = $this->sendRequest('/api/v2/ext/gameList', $data);

        // HTTP 層錯誤由 sendRequest 拋出；HTTP 200 但 code 非 200 回空清單並記 log，
        // 呼叫端對空清單會提前 return，不會讓「三方未提供」偵測把整批遊戲誤報
        if (!is_array($result) || (string) ($result['code'] ?? '') !== '200') {
            Log::error('[Sma][getGameList] Invalid response: code is not 200', [
                'vendor_id' => $this->vendor->id,
                'vendor_info_id' => $params->vendorInfo->id,
                'code' => $result['code'] ?? null,
                'msg' => $result['msg'] ?? null,
            ]);

            return GetGameListResultData::from(['gameList' => []]);
        }

        $gameList = [];
        $unknownTypes = [];
        foreach ($result['data'] ?? [] as $game) {
            // 缺 id 的列直接略過：空字串當唯一鍵會互相覆蓋，也會讓「三方未提供」偵測誤判
            if (!isset($game['id']) || $game['id'] === '') {
                continue;
            }

            $gameCode = (string) $game['id'];
            $rawType = (string) ($game['type'] ?? '');
            $gameType = self::GAME_TYPE_MAP[$rawType] ?? GameTypeEnum::EVENT_AND_OTHERS;

            if (!isset(self::GAME_TYPE_MAP[$rawType])) {
                $unknownTypes[$rawType] = $gameCode;
            }

            $gameList[$gameCode] = GameInfoData::from([
                'gameCode' => $gameCode,
                'gameName' => (string) ($game['name'] ?? ''),
                'imageUrl' => (string) ($game['squareIcon'] ?? ''),
                'gameType' => $gameType->value,
                'status' => 1,
            ]);
        }

        if (!empty($unknownTypes)) {
            Log::warning('[Sma][getGameList] 遊戲清單出現對應表外的遊戲類型', [
                'vendor_info_id' => $params->vendorInfo->id,
                'unknown_types' => $unknownTypes,
            ]);
        }

        return GetGameListResultData::from(['gameList' => $gameList]);
    }

    /**
     * 玩家餘額轉入遊戲商 (存款), origin: type = 1
     */
    public function transferToVendor(VendorTransferData $params): VendorTransferResultEnum
    {
        $data = [
            'timestamp' => now()->subSeconds(3)->getPreciseTimestamp(3),
            'appId' => $this->appId,
            'username' => $this->username,
            'balance' => $params->vendorAmount,
            'requestId' => $params->transactionNo,
        ];

        return $this->transfer('/api/v2/ext/wallet/deposit', $data);
    }

    /**
     * 轉出遊戲商餘額回本地 (提款), origin: type != 1
     */
    public function transferToLocal(VendorTransferData $params): VendorTransferResultEnum
    {
        $data = [
            'timestamp' => now()->subSeconds(3)->getPreciseTimestamp(3),
            'appId' => $this->appId,
            'username' => $this->vendorAccount->username,
            'balance' => $params->vendorAmount,
            'requestId' => $params->transactionNo,
        ];

        return $this->transfer('/api/v2/ext/wallet/withdraw', $data);
    }

    private function transfer(string $endpoint, array $data): VendorTransferResultEnum
    {
        $data = $this->generateSignature($data);

        try {
            $result = $this->sendRequest($endpoint, $data);
        } catch (Exception $e) {
            return VendorTransferResultEnum::TIMEOUT;
        }

        $logData = [
            'request' => $data,
            'response' => $result,
        ];

        if (!is_array($result)) {
            Log::error($this->getName() . ' transfer failed: response is not array', $logData);

            return VendorTransferResultEnum::TIMEOUT;
        }

        if (!isset($result['code'])) {
            Log::error($this->getName() . ' transfer failed: parameter "code" is not found', $logData);

            return VendorTransferResultEnum::TIMEOUT;
        }

        if ((string) $result['code'] === '200') {
            return VendorTransferResultEnum::SUCCESS;
        }

        Log::error($this->getName() . ' transfer failed', $logData);

        return VendorTransferResultEnum::FAILED;
    }

    private function mapLanguageCode(?LanguageEnum $language = null): string
    {
        if (empty($language)) {
            $languageId = Context::getHidden(ContextKeyEnum::LANGUAGE_ID->value);
        } else {
            $languageId = $language->value;
        }

        return match ($languageId) {
            LanguageEnum::ZH_TW->value, LanguageEnum::ZH_CN->value => 'cn',
            LanguageEnum::MS_MY->value => 'my',
            LanguageEnum::ID_ID->value => 'ida',
            LanguageEnum::VI_VN->value => 'vi',
            LanguageEnum::TH_TH->value => 'th',
            default => 'en',
        };
    }

    private function getToken($username)
    {
        $data = [
            'timestamp' => now()->subSeconds(3)->getPreciseTimestamp(3),
            'appId' => $this->appId,
            'username' => $username,
        ];

        $data = $this->generateSignature($data);

        $result = $this->sendRequest('/api/v2/ext/player/login', $data);

        if ((string) $result['code'] != '200') {
            $this->throwError($result['msg']);
        }

        return $result['data']['token'] ?? '';
    }

    private function checkToken($username, $token): bool
    {
        $data = [
            'timestamp' => now()->subSeconds(3)->getPreciseTimestamp(3),
            'appId' => $this->appId,
            'username' => $username,
            'token' => $token,
        ];

        $data = $this->generateSignature($data);

        $result = $this->sendRequest('/api/v2/ext/player/checkToken', $data);

        // 该接口由 code 响应码作为判定区分：
        // code = 0 : 有效令牌。
        // code = 5 : 无效令牌。
        if ($result['code'] === 0) {
            return true;
        }

        return false;
    }

    /**
     * 簽名規則
     */
    private function generateSignature(array $data): array
    {
        unset($data['sign']);

        // 字段按照 参数名称 进行自然排序
        ksort($data);

        // 排序后的将参数名称按照 key=value 的方式组合，再由 & 字符进行拼接得出参数部分。
        $signature = urldecode(http_build_query($data));

        // 有序组合将 参数部分 与给定的 Secret Key 组合。
        $signature .= $this->secretKey;

        // 最后将组合后的字符串进行MD5 加密，作为签名的值
        $signature = md5($signature);

        // 将该值设置为请求参数中的 sign 字段
        $data['sign'] = $signature;

        return $data;
    }

    private function sendRequest(string $endpoint, array $data): mixed
    {
        $backtrace = debug_backtrace();
        $previousMethod = $backtrace[1]['function'] ?? null;
        $action = $this->getName() . ' ' . $previousMethod;

        $apiUrl = rtrim($this->apiUrl, '/');
        $url = $apiUrl . $endpoint;

        try {
            $result = Http::logAction($action)
                ->asJson()
                ->post($url, $data)
                ->throw()
                ->json();
        } catch (Exception $e) {
            $this->throwError('API request failed', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);
        }

        return $result;
    }

    private function throwError(string $message, array $context = []): void
    {
        $backtrace = debug_backtrace();
        $previousMethod = $backtrace[1]['function'] ?? null;
        $action = $this->getName() . ' ' . $previousMethod;

        $context = array_merge([
            'vendor_id' => $this->vendor->id,
            'username' => $this->username,
        ], $context);

        Log::error("$action got error: $message", $context);

        throw new ApiException($message, HttpCode::HTTP_INTERNAL_SERVER_ERROR->value);
    }

    /**
     * 建立我方遊戲商帳號（遷自 RD-Studio create_sma_account）
     *
     * SMA 為 token 制登入，無密碼。
     */
    public function createAccount(CreateAccountParamsData $params): CreateAccountResultData
    {
        $this->setVendorInfo($params->vendorInfo);
        $username = $params->username;
        // throwError() 會讀 $this->username（typed property，未初始化會炸），排程情境先注入
        $this->username = $username;

        $account = $this->accountModel::create([
            'vendor_info_id' => $params->vendorInfo->id,
            'username' => $username,
            'member_id' => -999999999,
        ]);

        if (!$account) {
            return CreateAccountResultData::from(['status' => false, 'username' => $username]);
        }

        if (!$this->createVendorAccount($username, (string) $params->vendorInfo->currency)) {
            return CreateAccountResultData::from(['status' => false, 'username' => $username]);
        }

        $account->update(['member_id' => 0]);

        return CreateAccountResultData::from(['status' => true, 'username' => $username]);
    }

    /**
     * 建立三方遊戲商帳號（/api/v2/ext/createAccount）
     */
    private function createVendorAccount(string $username, string $currency): bool
    {
        $data = [
            // 時間戳太貼近當下會被三方退回，比照舊系統回退數秒
            'timestamp' => now()->subSeconds(5)->getPreciseTimestamp(3),
            'appId' => $this->appId,
            'username' => $username,
            // 文件標已棄用但仍參與簽名，不能省
            'currency' => $currency,
        ];

        $data = $this->generateSignature($data);

        try {
            $result = $this->sendRequest('/api/v2/ext/createAccount', $data);
        } catch (Throwable $e) {
            Log::error('[Sma][createAccount] request failed', [
                'username' => $username,
                'error' => $e->getMessage(),
                'trace' => Common::formatTrace($e),
            ]);

            return false;
        }

        if (!is_array($result) || !isset($result['code'])) {
            Log::error('[Sma][createAccount] failed: bad response', ['username' => $username, 'response' => $result]);

            return false;
        }

        // 舊系統實戰以 200 判定成功；官方文件範例回 "0"，兩者皆接受
        if (in_array((string) $result['code'], ['200', '0'], true)) {
            return true;
        }

        Log::error('[Sma][createAccount] failed', ['username' => $username, 'response' => $result]);

        return false;
    }
}
