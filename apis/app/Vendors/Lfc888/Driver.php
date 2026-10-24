<?php

declare(strict_types=1);

namespace App\Vendors\Lfc888;

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
use App\Enums\LanguageEnum;
use App\Enums\Vendor\VendorTransferResultEnum;
use App\Exceptions\ApiException;
use App\Helpers\Common;
use App\Models\Member;
use App\Vendors\AbstractTransferWallet;
use App\Vendors\Contracts\ShouldScheduleAccountCreation;
use App\Vendors\Contracts\ShouldSyncGameList;
use App\Vendors\Models\Lfc888Account;
use App\Vendors\Models\Lfc888BetRecord;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Spatie\LaravelData\Data;

class Driver extends AbstractTransferWallet implements ShouldScheduleAccountCreation, ShouldSyncGameList
{
    /**
     * 舊系統未分類遊戲寫 type_id=0（共用 DB 既有慣例，維持一致）
     */
    private const int LEGACY_UNCATEGORIZED_TYPE_ID = 0;

    private const string GAME_IMAGE_URL = 'https://example.invalid/cdn-betmonsters-com-cdn-bucket-online/vworld/game/lfc888/en/';

    protected string $accountModel = Lfc888Account::class;

    protected string $betRecordModel = Lfc888BetRecord::class;

    private VendorInfoDetailData $vendorInfoDetail;

    private string $apiUrl;

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
        $this->vendorInfoDetail = VendorInfoDetailData::from($info);
        $this->apiUrl = trim($this->vendorInfoDetail->API_URL, '/');
    }

    public function getGameUrl(VendorGameUrlParamsData $params): VendorGameUrlResultData
    {
        $url = "{$this->apiUrl}/agent/user_login";
        $countryCode = $this->member->country?->code;
        $action = 'GetGameUrl';

        $routing = match ($countryCode) {
            'CN' => 'zh-cn',
            'ID' => 'id',
            default => 'world',
        };

        $params = [
            'game_code' => $params->gameCode,
            'username' => $this->vendorAccount->username,
            'lang' => $this->mapLanguageCode(),
            'routing' => $routing,
            'device' => 'mobile',
            'back_url' => $params->exitUrl,
        ];

        $response = $this->apiCall($action, $url, $params);

        if (!is_array($response)) {
            throw new ApiException("[LFC888][{$action}] Invalid response: not json array");
        }

        if (!isset($response['status']['code']) || (int) $response['status']['code'] !== 1000) {
            $code = $response['status']['code'] ?? 'unknown';
            $msg = $response['status']['message'] ?? 'unknown';
            throw new ApiException("[LFC888][{$action}] Invalid response: status code is not 1000, code: {$code}, msg: {$msg}");
        }

        $gameUrl = $response['data']['game_url'] ?? '';

        return VendorGameUrlResultData::from([
            'pc' => $gameUrl,
            'mobile' => $gameUrl,
        ]);
    }

    public function getBalance(): string
    {
        $url = "{$this->apiUrl}/user/get_balance";
        $action = 'GetBalance';

        $params = [
            'username' => $this->vendorAccount->username,
        ];

        $response = $this->apiCall($action, $url, $params);

        if (!is_array($response)) {
            throw new ApiException("[LFC888][{$action}] Invalid response: not json array");
        }

        if (!isset($response['status']['code']) || (int) $response['status']['code'] !== 1000) {
            $code = $response['status']['code'] ?? 'unknown';
            $msg = $response['status']['message'] ?? 'unknown';
            throw new ApiException("[LFC888][{$action}] Invalid response: status code is not 1000, code: {$code}, msg: {$msg}");
        }

        if (!isset($response['data']['balance'])) {
            throw new ApiException("[LFC888][{$action}] Invalid response: no balance in data");
        }

        $balance = $response['data']['balance'];

        return Common::numFloor($balance, 4);
    }

    public function checkTransferStatus(string $transactionNo): VendorTransferResultEnum
    {
        $url = "{$this->apiUrl}/user/get_transaction_status";
        $action = 'CheckTransferStatus';

        $data = [
            'external_order_id' => $transactionNo,
        ];

        $response = $this->apiCall($action, $url, $data);

        if (!is_array($response)) {
            Log::error("[LFC888][{$action}] Invalid response: not json array");

            return VendorTransferResultEnum::TIMEOUT;
        }

        if (!isset($response['status']['code']) || (int) $response['status']['code'] !== 1000) {
            $code = $response['status']['code'] ?? 'unknown';
            $msg = $response['status']['message'] ?? 'unknown';
            Log::error("[LFC888][{$action}] Invalid response: status code is not 1000, code: {$code}, msg: {$msg}");

            return VendorTransferResultEnum::FAILED;
        }

        return VendorTransferResultEnum::SUCCESS;
    }

    /**
     * 玩家餘額轉入遊戲商 (存款), origin: type = 1
     */
    public function transferToVendor(VendorTransferData $params): VendorTransferResultEnum
    {
        $url = "{$this->apiUrl}/user/deposit_amount";
        $action = 'Deposit';

        $data = [
            'username' => $this->vendorAccount->username,
            'external_order_id' => $params->transactionNo,
            'deposit_amount' => (float) $params->vendorAmount,
        ];

        return $this->transfer($action, $url, $data);
    }

    /**
     * 轉出遊戲商餘額回本地 (提款), origin: type != 1
     */
    public function transferToLocal(VendorTransferData $params): VendorTransferResultEnum
    {
        $url = "{$this->apiUrl}/user/withdraw_amount";
        $action = 'Withdrawal';
        $data = [
            'username' => $this->vendorAccount->username,
            'external_order_id' => $params->transactionNo,
            'withdraw_amount' => (float) $params->vendorAmount,
            'take_all' => false, // 提領玩家全部額度
        ];

        return $this->transfer($action, $url, $data);
    }

    private function transfer(string $action, string $url, array $postData): VendorTransferResultEnum
    {
        $response = $this->apiCall($action, $url, $postData);

        if (!is_array($response) || !isset($response['status']['code'])) {
            Log::error("[LFC888][{$action}] Invalid response: not json array or no status code");

            return VendorTransferResultEnum::TIMEOUT; // 無法判斷的錯誤，先不還錢
        }

        if ((int) $response['status']['code'] !== 1000) {
            $code = $response['status']['code'];
            $msg = $response['status']['message'] ?? 'unknown';
            Log::error("[LFC888][{$action}] Invalid response: status code is not 1000, code: {$code}, msg: {$msg}");

            return VendorTransferResultEnum::FAILED; // FAILED
        }

        return VendorTransferResultEnum::SUCCESS;
    }

    /**
     * 遊戲清單（回應為 {分類: [遊戲]} 巢狀結構；圖檔照舊系統走 GCS 共用圖庫，
     * bucket 路徑為多白標共用的 vworld 硬編碼；語系名以語系碼為 key 掛在遊戲上）
     */
    public function getGameList(GetGameListParamsData $params): GetGameListResultData
    {
        $this->setVendorInfo($params->vendorInfo);

        $url = "{$this->vendorInfoDetail->API_URL}/agent/get_gamelist";
        $response = $this->apiCall('GetGameList', $url);

        if (!is_array($response) || (int) ($response['status']['code'] ?? 0) !== 1000) {
            $code = $response['status']['code'] ?? 'null';
            $message = $response['status']['message'] ?? '';
            throw new ApiException("Third party error: ({$code}) {$message}");
        }

        $typeMap = [
            'live' => GameTypeEnum::LIVE_CASINO->value,
            'slot' => GameTypeEnum::SLOTS->value,
            'table' => GameTypeEnum::POKER->value,
            'p2p' => GameTypeEnum::POKER->value,
            'fish' => GameTypeEnum::FISHING->value,
            'arcade' => GameTypeEnum::ARCADE->value,
        ];

        $langMap = [
            'en' => LanguageEnum::EN->value,
            'tw' => LanguageEnum::ZH_TW->value,
            'cn' => LanguageEnum::ZH_CN->value,
            'id' => LanguageEnum::ID_ID->value,
            'th' => LanguageEnum::TH_TH->value,
        ];

        $gameList = [];
        foreach ($response['data'] ?? [] as $category => $games) {
            foreach ($games as $game) {
                $gameCode = (string) $game['game_code'];
                $game['type'] = $category;
                $gameInfo = GameInfoData::from([
                    'gameCode' => $gameCode,
                    'gameName' => $game['en'] ?? $gameCode,
                    'imageUrl' => self::GAME_IMAGE_URL . $gameCode . '.png',
                    'gameType' => $typeMap[strtolower((string) $category)] ?? self::LEGACY_UNCATEGORIZED_TYPE_ID,
                    'status' => 1,
                    'info' => json_encode($game),
                ]);

                foreach ($langMap as $langCode => $languageId) {
                    if (!empty($game[$langCode])) {
                        $gameInfo->setGameNames($languageId, $game[$langCode]);
                    }
                }

                $gameList[$gameCode] = $gameInfo;
            }
        }

        return GetGameListResultData::from(['gameList' => $gameList]);
    }

    /**
     * 呼叫遊戲商 API, json_decode 後回傳
     */
    private function apiCall(string $action, string $url, array $params = []): mixed
    {
        // make sure.
        unset($params['check_code'], $params['account_id']);

        $params['account_id'] = $this->vendorInfoDetail->ACCOUNT_ID;
        $params['check_code'] = $this->getCheckCode($params);

        $response = Http::logAction("LFC888_{$action}")
            ->asJson()
            ->post($url, $params)
            ->json();

        return $response;
    }

    /**
     * -數據簽章碼
     */
    private function getCheckCode(array $requestData): string
    {
        $str = sprintf('security_code=%s&', $this->vendorInfoDetail->SECURITY_CODE);

        ksort($requestData);

        // 轉出的 take_all 必須轉換為字串
        foreach ($requestData as $key => $value) {
            if (is_bool($value)) {
                $requestData[$key] = $value ? 'true' : 'false';
            }
        }

        $str .= urldecode(http_build_query($requestData));

        return md5($str);
    }

    private function mapLanguageCode(?LanguageEnum $language = null): string
    {
        if (empty($language)) {
            $languageId = Context::getHidden(ContextKeyEnum::LANGUAGE_ID->value);
        } else {
            $languageId = $language->value;
        }

        return match ($languageId) {
            LanguageEnum::ZH_TW->value => 'zh-tw',
            LanguageEnum::ZH_CN->value => 'zh-cn',
            LanguageEnum::ID_ID->value => 'id',
            LanguageEnum::TH_TH->value => 'th',
            default => 'en',
        };
    }

    /**
     * 建立我方遊戲商帳號（遷自 RD-Studio create_lfc888_account）
     *
     * LFC888 沒有專屬 create endpoint：首次 /agent/lobby_login 即自動建號。無密碼機制。
     */
    public function createAccount(CreateAccountParamsData $params): CreateAccountResultData
    {
        $this->setVendorInfo($params->vendorInfo);
        $username = $params->username;

        $account = $this->accountModel::create([
            'vendor_info_id' => $params->vendorInfo->id,
            'username' => $username,
            // 注單以 b.username = v.username_modified 對帳，必寫
            'username_modified' => (string) ($params->vendorInfo->info['ACCOUNT_CLASS'] ?? '') . '_' . $username,
            'admin' => 1,
            'member_id' => -999999999,
        ]);

        if (!$account) {
            return CreateAccountResultData::from(['status' => false, 'username' => $username]);
        }

        if (!$this->createVendorAccount($username)) {
            return CreateAccountResultData::from(['status' => false, 'username' => $username]);
        }

        $account->update(['member_id' => 0]);

        return CreateAccountResultData::from(['status' => true, 'username' => $username]);
    }

    /**
     * 建立三方遊戲商帳號
     */
    private function createVendorAccount(string $username): bool
    {
        $action = 'CreateAccount';
        $url = "{$this->apiUrl}/agent/lobby_login";

        // apiCall 會自動補 account_id 與 check_code
        $response = $this->apiCall($action, $url, ['username' => $username]);

        if (is_array($response) && (int) ($response['status']['code'] ?? 0) === 1000) {
            return true;
        }

        Log::error("[LFC888][{$action}] Invalid response: status code is not 1000", [
            'username' => $username,
            'code' => $response['status']['code'] ?? 'unknown',
            'message' => $response['status']['message'] ?? 'unknown',
        ]);

        return false;
    }
}

class VendorInfoDetailData extends Data
{
    public function __construct(
        public string $API_URL,
        public string $ACCOUNT_ID,
        public string $SECURITY_CODE,
        public string $CURRENCY,
    ) {}
}
