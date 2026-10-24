<?php

declare(strict_types=1);

namespace App\Vendors\Vpower;

use App\Data\GameInfoData;
use App\Data\Vendor\CreateAccountParamsData;
use App\Data\Vendor\CreateAccountResultData;
use App\Data\Vendor\GetGameListParamsData;
use App\Data\Vendor\GetGameListResultData;
use App\Data\VendorGameUrlParamsData;
use App\Data\VendorGameUrlResultAppInfoData;
use App\Data\VendorGameUrlResultData;
use App\Data\VendorTransferData;
use App\Enums\Game\GameTypeEnum;
use App\Enums\LanguageEnum;
use App\Enums\Vendor\VendorTransferResultEnum;
use App\Exceptions\ApiException;
use App\Helpers\Common;
use App\Models\Member;
use App\Vendors\AbstractTransferWallet;
use App\Vendors\Contracts\ShouldScheduleAccountCreation;
use App\Vendors\Contracts\ShouldSyncGameList;
use App\Vendors\Models\VpowerAccount;
use App\Vendors\Models\VpowerBetRecord;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class Driver extends AbstractTransferWallet implements ShouldScheduleAccountCreation, ShouldSyncGameList
{
    protected string $accountModel = VpowerAccount::class;

    protected string $betRecordModel = VpowerBetRecord::class;

    protected string $actionTitle = 'Vpower';

    protected string $apiUrl;

    protected string $gameUrl;

    protected string $appID;

    protected string $key;

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
        $this->gameUrl = $info['API_GAMEURL'];
        $this->appID = $info['API_APPID'];
        $this->key = $info['API_KEY'];
    }

    public function getGameUrl(VendorGameUrlParamsData $params): VendorGameUrlResultData
    {
        $action = $this->actionTitle . '_api_getGameUrl';
        $username = $this->vendorAccount->username;
        $language = $this->getLanguage($params->language->value);
        $exitUrl = base64_encode($params->exitUrl);
        $gameCode = $params->gameCode;

        $time = time();

        $data = [];
        $data['Act'] = 'tkreq';
        $data['Timestamp'] = $time;
        $data['Username'] = $username;
        $data['AppId'] = $this->appID;
        $data['Signature'] = $this->sign($data, $this->key);

        $response = Http::logAction($action)->get($this->apiUrl, $data)->throw();
        $json = $response->json();

        if (!$json) {
            throw new ApiException('Third party error: not json response');
        }

        if (!isset($json['ErrorCode']) || !is_numeric($json['ErrorCode'])) {
            throw new ApiException('Third party error: format error');
        }

        if ((string) $json['ErrorCode'] != '0') {
            $code = $json['ErrorCode'];
            throw new ApiException('Third party error: error code: ' . $code);
        }

        $params = [
            'id' => $gameCode,
            'token' => $json['Token'],
            'back' => $exitUrl,
        ];
        $url = $this->gameUrl . '?' . http_build_query($params);

        // app open url
        $app_open_url = 'vpower://token=' . $json['Token'];
        $info = $this->vendorInfo->info;
        $ios_download = $info['IOS_DOWNLOAD'] ?? '';
        $android_download = $info['ANDROID_DOWNLOAD'] ?? '';

        $appInfo = VendorGameUrlResultAppInfoData::from([
            'username' => $username,
            'password' => '',
            'ios_downloads' => [$ios_download],
            'ios_open' => $app_open_url,
            'android_downloads' => [$android_download],
            'android_open' => $app_open_url,
            'app_icon' => $this->vendor->app_icon,
        ]);

        return VendorGameUrlResultData::from([
            'pc' => $url,
            'mobile' => $url,
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
        $data['Act'] = 'getbal';
        $data['Timestamp'] = time();
        $data['Username'] = $username;
        $data['AppId'] = $this->appID;
        $data['Signature'] = $this->sign($data, $this->key);

        $response = Http::logAction($action)->get($this->apiUrl, $data)->throw();
        $json = $response->json();

        if (!$json) {
            throw new ApiException('Third party error: not json response');
        }

        if (!isset($json['ErrorCode']) || !is_numeric($json['ErrorCode'])) {
            throw new ApiException('Third party error: format error');
        }

        if ((string) $json['ErrorCode'] != '0') {
            $code = $json['ErrorCode'];
            throw new ApiException('Third party error: error code: ' . $code);
        }

        if (!isset($json['Credit'])) {
            throw new ApiException('Third party error: format error');
        }

        return (string) $json['Credit'];
    }

    /**
     * 轉帳
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

        $data = [];
        $data['Act'] = 'creditxf';
        $data['RequestID'] = $billNo;
        $data['Timestamp'] = time();
        $data['Username'] = $username;
        $data['AppId'] = $this->appID;
        $data['Amount'] = $amount;
        $data['Signature'] = $this->sign($data, $this->key);

        $response = Http::logAction($action)->get($this->apiUrl, $data)->throw();
        $json = $response->json();

        if (!$json) {
            return VendorTransferResultEnum::TIMEOUT;
        }

        if (!isset($json['ErrorCode']) || !is_numeric($json['ErrorCode'])) {
            throw new ApiException('Third party error: format error');
        }

        if ((string) $json['ErrorCode'] != '0') {
            return VendorTransferResultEnum::FAILED;
        }

        return VendorTransferResultEnum::SUCCESS;
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
     * 檢查轉帳狀態
     */
    public function checkTransferStatus(string $transactionNo): VendorTransferResultEnum
    {
        $action = $this->actionTitle . '_CheckTransferStatus';

        $data = [];
        $data['Act'] = 'creditcheck';
        $data['RequestID'] = $transactionNo;
        $data['Timestamp'] = time();
        $data['AppId'] = $this->appID;
        $data['Signature'] = $this->sign($data, $this->key);

        $response = Http::logAction($action)->get($this->apiUrl, $data)->throw();
        $json = $response->json();

        if (!$json) {
            return VendorTransferResultEnum::TIMEOUT;
        }

        $list = $json['List'] ?? [];
        if (empty($list)) {
            return VendorTransferResultEnum::FAILED;
        }
        $first = $list[0];
        $state = (string) $first['state'];

        // 处理状态 0 未处理， 1 已处理 成功，2 处理失败
        if ($state == '0') {
            return VendorTransferResultEnum::TIMEOUT;
        } elseif ($state == '1') {
            return VendorTransferResultEnum::SUCCESS;
        } else {
            return VendorTransferResultEnum::FAILED;
        }
    }

    /**
     * 遊戲清單（GameOCode 為遊戲代碼、GameID 存 remark 比照既有資料的複合鍵；
     * 名稱取英文 GameName_EN）
     */
    public function getGameList(GetGameListParamsData $params): GetGameListResultData
    {
        $this->setVendorInfo($params->vendorInfo);

        $data = [];
        $data['Act'] = 'newgamelist';
        $data['Timestamp'] = time();
        $data['AppId'] = $this->appID;
        $data['Signature'] = $this->sign($data, $this->key);

        $response = Http::logAction($this->actionTitle . '_getGameList')->get($this->apiUrl, $data)->throw();
        $json = $response->json();

        if (!$json) {
            throw new ApiException('Third party error: not json response');
        }

        if ((string) ($json['ErrorCode'] ?? '') !== '0') {
            throw new ApiException('Third party error: error code: ' . ($json['ErrorCode'] ?? 'null'));
        }

        $typeMap = [
            'fishing' => GameTypeEnum::FISHING->value,
            'multiplayer' => GameTypeEnum::ARCADE->value,
            'slot' => GameTypeEnum::SLOTS->value,
        ];

        $gameList = [];
        foreach ($json['GameList'] ?? [] as $game) {
            $gameCode = (string) ($game['GameOCode'] ?? '');
            if ($gameCode === '') {
                continue;
            }

            $gameList[$gameCode] = GameInfoData::from([
                'gameCode' => $gameCode,
                'gameName' => $game['GameName_EN'] ?? $game['GameName'],
                'imageUrl' => $game['Image1'] ?? '',
                'gameType' => $typeMap[strtolower($game['GameType'] ?? '')] ?? GameTypeEnum::EVENT_AND_OTHERS->value,
                'status' => 1,
                'remark' => isset($game['GameID']) ? (string) $game['GameID'] : null,
                'info' => json_encode($game),
            ]);
        }

        return GetGameListResultData::from(['gameList' => $gameList]);
    }

    private function sign(array $data, string $key): string
    {
        $str = http_build_query($data);
        $signature = '';
        if (function_exists('hash_hmac')) {
            $signature = base64_encode(hash_hmac('sha1', $str, $key, true));
        } else {
            $blocksize = 64;
            $hashfunc = 'sha1';
            if (strlen($key) > $blocksize) {
                $key = pack('H*', $hashfunc($key));
            }
            $key = str_pad($key, $blocksize, chr(0x00));
            $ipad = str_repeat(chr(0x36), $blocksize);
            $opad = str_repeat(chr(0x5C), $blocksize);
            $hmac = pack('H*', $hashfunc(($key ^ $opad) . pack('H*', $hashfunc(($key ^ $ipad) . $str))));
            $signature = base64_encode($hmac);
        }

        return $signature;
    }

    public function getLanguage(int $languageId): string
    {
        return match ($languageId) {
            LanguageEnum::EN->value => 'en-us',
            LanguageEnum::ZH_TW->value => 'zh-hant',
            LanguageEnum::ZH_CN->value => 'zh-hans',
            LanguageEnum::ID_ID->value => 'id',
            LanguageEnum::VI_VN->value => 'vi',
            LanguageEnum::TH_TH->value => 'th',
            LanguageEnum::MY_MM->value => 'my',
            LanguageEnum::MS_MY->value => 'ms',
            LanguageEnum::HI_IN->value => 'hi',
            default => 'en-us',
        };
    }

    /**
     * 建立我方遊戲商帳號（遷自 RD-Studio create_vpower_account）
     *
     * 登入走 Act=tkreq 只帶 Username，密碼全程不使用。
     */
    public function createAccount(CreateAccountParamsData $params): CreateAccountResultData
    {
        $this->setVendorInfo($params->vendorInfo);
        $username = $params->username;

        $account = $this->accountModel::create([
            'vendor_info_id' => $params->vendorInfo->id,
            'username' => $username,
            // password / admin 皆 NOT NULL 無 default；密碼全程不使用，存空字串
            'password' => '',
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
     * 建立三方遊戲商帳號（Act=getacc：查詢帳號，不存在則自動建立）
     *
     * 本接口成功碼是 1（New User created），與其他接口的 0 相反。
     */
    private function createVendorAccount(string $username): bool
    {
        $data = [
            'Act' => 'getacc',
            'Timestamp' => time(),
            'Username' => $username,
            'AppId' => $this->appID,
        ];
        $data['Signature'] = $this->sign($data, $this->key);

        try {
            $json = Http::logAction($this->actionTitle . '_createAccount')
                ->get($this->apiUrl, $data)
                ->throw()
                ->json();
        } catch (Throwable $e) {
            Log::error('[Vpower][createAccount] request failed', [
                'username' => $username,
                'error' => $e->getMessage(),
                'trace' => Common::formatTrace($e),
            ]);

            return false;
        }

        if (!is_array($json) || !isset($json['ErrorCode'])) {
            Log::error('[Vpower][createAccount] failed: bad response', ['username' => $username, 'response' => $json]);

            return false;
        }

        // 1 = New User created；12 = User already exist（帳號池重跑視為冪等成功）
        if (!in_array((string) $json['ErrorCode'], ['1', '12'], true)) {
            Log::error('[Vpower][createAccount] rejected', [
                'username' => $username,
                'error_code' => $json['ErrorCode'],
            ]);

            return false;
        }

        return true;
    }
}
