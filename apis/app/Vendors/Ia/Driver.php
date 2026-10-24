<?php

declare(strict_types=1);

namespace App\Vendors\Ia;

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
use App\Enums\General\DeviceTypeEnum;
use App\Enums\LanguageEnum;
use App\Enums\Vendor\VendorTransferResultEnum;
use App\Exceptions\AccountNotExistsException;
use App\Exceptions\ApiException;
use App\Helpers\Common;
use App\Models\Member;
use App\Vendors\AbstractTransferWallet;
use App\Vendors\Contracts\HasEntryGames;
use App\Vendors\Contracts\ShouldScheduleAccountCreation;
use App\Vendors\Contracts\ShouldSyncGameList;
use App\Vendors\Models\IaAccount;
use App\Vendors\Models\IaBetRecord;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Spatie\LaravelData\Data;
use Throwable;

class Driver extends AbstractTransferWallet implements HasEntryGames, ShouldScheduleAccountCreation, ShouldSyncGameList
{
    /**
     * 我方自建的入口遊戲代碼
     *
     * 三方 getGameList 只回數字代碼（賽事 id），這兩筆是我方自建供前台當作 IA 的
     * 進入點（`type_id` 為 E_SPORTS），三方永遠不會回傳，同步時必須整批排除。
     */
    private const array ENTRY_GAME_CODES = ['ESPORT', 'IA'];

    /**
     * 三方回應 code：用戶不存在（msg 為 `用戶不存在@#@`）
     */
    private const int ACCOUNT_NOT_EXISTS_CODE = 1501;

    protected string $accountModel = IaAccount::class;

    protected string $betRecordModel = IaBetRecord::class;

    private VendorInfoDetailData $infoDetail;

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
        $this->infoDetail = VendorInfoDetailData::from($info);
    }

    public function entryGameCodes(): array
    {
        return self::ENTRY_GAME_CODES;
    }

    public function getGameUrl(VendorGameUrlParamsData $params): VendorGameUrlResultData
    {
        $action = 'GetGameUrl';
        $postData = [
            'client' => $params->deviceType === DeviceTypeEnum::MOBILE ? 2 : 1,
            'lang' => $this->mapLanguageCode($params->language),
            'username' => $this->vendorAccount->username,
        ];
        $response = $this->post($action, 'user/lunch', $postData);

        if (!is_array($response)) {
            throw new ApiException("[IA][{$action}] response is not array");
        }

        if (!isset($response['code']) || (string) $response['code'] !== '1') {
            throw new ApiException("[IA][{$action}] response wrong status code");
        }

        if (!isset($response['data']['url'])) {
            throw new ApiException("[IA][{$action}] response wrong data");
        }

        return new VendorGameUrlResultData(
            pc: $response['data']['url'],
            mobile: $response['data']['url'],
        );
    }

    public function getBalance(): string
    {
        try {
            return $this->fetchBalance();
        } catch (AccountNotExistsException) {
            // 三方端沒這個帳號（帳號池的舊帳號已被三方清掉），用同帳號補建後重查一次
            $this->registerAccount();

            return $this->fetchBalance();
        }
    }

    /**
     * 查詢餘額，帳號不存在時拋出 AccountNotExistsException（不自動補建）
     *
     * @throws AccountNotExistsException|ApiException
     */
    public function fetchBalance(): string
    {
        $action = 'GetBalance';
        $postData = [
            'username' => $this->vendorAccount->username,
        ];
        $response = $this->post($action, 'user/balance', $postData);

        if (!is_array($response)) {
            throw new ApiException("[IA][{$action}] response is not array");
        }

        if (!isset($response['code']) || $response['code'] !== 1) {
            // 帳號不存在讓呼叫端可決定是否補建，其餘 code 一律視為三方異常
            if ((int) ($response['code'] ?? 0) === self::ACCOUNT_NOT_EXISTS_CODE) {
                throw new AccountNotExistsException("[IA][{$action}] account not exists");
            }

            throw new ApiException("[IA][{$action}] response wrong code");
        }

        if (!isset($response['data']['data'])) {
            throw new ApiException("[IA][{$action}] response wrong data");
        }

        return Common::numFloor($response['data']['data'], 4);
    }

    /**
     * 在三方端以同一組帳號補建（參數比照舊系統 create_ia_account）
     *
     * @throws ApiException
     */
    public function registerAccount(): void
    {
        $action = 'RegisterAccount';
        $postData = [
            'currency_code' => $this->infoDetail->CURRENCY,
            'register_username' => $this->vendorAccount->username,
        ];
        $response = $this->post($action, 'user/register', $postData);

        if (($response['code'] ?? null) !== 1) {
            throw new ApiException("[IA][{$action}] register account failed");
        }
    }

    public function checkTransferStatus(string $transactionNo): VendorTransferResultEnum
    {
        $action = 'CheckTransferStatus';
        $postData = [
            'order_id' => $transactionNo,
        ];
        $response = $this->post($action, 'user/searchorder', $postData);

        $logData = [
            'action' => $action,
            'request' => $postData,
            'response' => $response,
        ];

        if (!is_array($response)) {
            Log::error("[IA][{$action}] response is not array", $logData);

            return VendorTransferResultEnum::TIMEOUT;
        }

        if (!isset($response['code'])) {
            Log::error("[IA][{$action}] response empty code", $logData);

            return VendorTransferResultEnum::TIMEOUT;
        }

        if ((string) $response['code'] !== '1') {
            Log::error("[IA][{$action}] response wrong code", $logData);

            return VendorTransferResultEnum::FAILED;
        }

        if (!isset($response['data']['status'])) {
            Log::error("[IA][{$action}] response wrong data, data status is empty", $logData);

            return VendorTransferResultEnum::TIMEOUT;
        }

        if ((string) $response['data']['status'] !== '1') {
            Log::error("[IA][{$action}] transfer failed", $logData);

            return VendorTransferResultEnum::FAILED;
        }

        return VendorTransferResultEnum::SUCCESS;
    }

    /**
     * 玩家餘額轉入遊戲商 (存款), origin: type = 1
     */
    public function transferToVendor(VendorTransferData $params): VendorTransferResultEnum
    {
        return $this->transfer($params, 'user/deposit', 'TransferToVendor');
    }

    /**
     * 轉出遊戲商餘額回本地 (提款), origin: type != 1
     */
    public function transferToLocal(VendorTransferData $params): VendorTransferResultEnum
    {
        return $this->transfer($params, 'user/withdraw', 'TransferToLocal');
    }

    private function transfer(VendorTransferData $params, string $endPoint, string $action): VendorTransferResultEnum
    {
        $postData = [
            'amount' => $params->vendorAmount,
            'order_id' => $params->transactionNo,
            'username' => $this->vendorAccount->username,
        ];
        $response = $this->post($action, $endPoint, $postData);

        $logData = [
            'action' => $action,
            'request' => $postData,
            'response' => $response,
        ];

        if (!is_array($response)) {
            Log::error("[IA][{$action}] response is not array", $logData);

            return VendorTransferResultEnum::TIMEOUT;
        }

        if (!isset($response['code'])) {
            Log::error("[IA][{$action}] response empty code", $logData);

            return VendorTransferResultEnum::TIMEOUT;
        }

        if ((string) $response['code'] !== '1') {
            Log::error("[IA][{$action}] response wrong status", $logData);

            return VendorTransferResultEnum::FAILED;
        }

        if (!isset($response['data']['status'])) {
            Log::error("[IA][{$action}] response wrong data, data status is empty", $logData);

            return VendorTransferResultEnum::TIMEOUT;
        }

        if ((string) $response['data']['status'] !== '1') {
            Log::error("[IA][{$action}] transfer failed", $logData);

            return VendorTransferResultEnum::FAILED;
        }

        // double check

        return $this->checkTransferStatus($params->transactionNo);
    }

    /**
     * 遊戲清單（三方無類型欄位，比照舊系統一律 EVENT_AND_OTHERS）
     */
    public function getGameList(GetGameListParamsData $params): GetGameListResultData
    {
        $this->setVendorInfo($params->vendorInfo);

        $response = $this->post('GetGameList', 'index/getGameType', []);

        if ((string) ($response['code'] ?? '') !== '1') {
            throw new ApiException('Third party error: (' . ($response['code'] ?? '') . ') ' . ($response['msg'] ?? ''));
        }

        $data = [];
        foreach ($response['data'] ?? [] as $game) {
            $gameCode = (string) $game['id'];
            $data[$gameCode] = GameInfoData::from([
                'gameCode' => $gameCode,
                'gameName' => $game['game_name'],
                'imageUrl' => $game['logo'] ?? '',
                'gameType' => GameTypeEnum::EVENT_AND_OTHERS->value,
                'status' => 1,
                'info' => json_encode($game),
            ]);
        }

        return GetGameListResultData::from(['gameList' => $data]);
    }

    private function post(string $action, string $endPoint, array $postData): array
    {
        $endPoint = trim($endPoint, '/');
        $encryptedPostData = $this->encrypt($postData);

        return Http::logAction('IA_' . $action, $postData)
            ->timeout(10)
            ->withHeaders([
                'pch' => $this->infoDetail->API_PCH,
            ])
            ->withBody($encryptedPostData, 'text/plain')
            ->post("{$this->infoDetail->API_URL}/{$endPoint}")
            ->throw()
            ->json();
    }

    private function encrypt(array $params): string
    {
        ksort($params);

        // '$key=value,key2=value2,key3=value3'
        $params['auth_key'] = md5(
            implode(',', array_map(
                fn ($k, $v) => "$k=$v",
                array_keys($params),
                $params
            ))
        );
        $params = json_encode($params);
        $cryptText = openssl_encrypt($params, 'aes-256-cbc', $this->infoDetail->API_KEY, OPENSSL_RAW_DATA, $this->infoDetail->API_IV);
        $data = base64_encode($cryptText);

        return $data;
    }

    private function mapLanguageCode(?LanguageEnum $language = null): string
    {
        if (empty($language)) {
            $languageId = Context::getHidden(ContextKeyEnum::LANGUAGE_ID->value);
        } else {
            $languageId = $language->value;
        }

        return match ($languageId) {
            LanguageEnum::ZH_CN->value => '1',
            LanguageEnum::EN->value => '2',
            LanguageEnum::ZH_TW->value => '3',
            LanguageEnum::TH_TH->value => '4',
            LanguageEnum::VI_VN->value => '5',
            LanguageEnum::ID_ID->value => '6',
            default => '2',
        };
    }

    /**
     * 建立我方遊戲商帳號（遷自 RD-Studio create_ia_account）
     *
     * IA 沒有密碼機制（getGameUrl 只帶 username），三方建號重用既有的
     * registerAccount()——其 payload 即照舊系統 create_ia_account 而寫。
     */
    public function createAccount(CreateAccountParamsData $params): CreateAccountResultData
    {
        $this->setVendorInfo($params->vendorInfo);
        $username = $params->username;

        $account = $this->accountModel::create([
            'vendor_info_id' => $params->vendorInfo->id,
            'username' => $username,
            // ia_accounts.password 為 NOT NULL 無 default；IA 登入不需要密碼
            'password' => '',
            'admin' => 1,
            'member_id' => -999999999,
        ]);

        if (!$account) {
            return CreateAccountResultData::from(['status' => false, 'username' => $username]);
        }

        try {
            // registerAccount() 讀 $this->vendorAccount->username，先注入剛建好的 row
            $this->setVendorAccount($account);
            $this->registerAccount();
        } catch (Throwable $e) {
            Log::error('[IA][createAccount] register account failed', [
                'username' => $username,
                'error' => $e->getMessage(),
                'trace' => Common::formatTrace($e),
            ]);

            return CreateAccountResultData::from(['status' => false, 'username' => $username]);
        }

        $account->update(['member_id' => 0]);

        return CreateAccountResultData::from(['status' => true, 'username' => $username]);
    }
}

class VendorInfoDetailData extends Data
{
    public function __construct(
        public string $API_URL,
        public string $API_KEY,
        public string $API_IV,
        public string $API_PCH,
        public string $CURRENCY,
    ) {
        $this->API_URL = trim($this->API_URL, '/');
    }
}
