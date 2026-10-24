<?php

declare(strict_types=1);

namespace App\Vendors\Pussy888;

use App\Data\Vendor\CreateAccountParamsData;
use App\Data\Vendor\CreateAccountResultData;
use App\Data\VendorGameUrlParamsData;
use App\Data\VendorGameUrlResultAppInfoData;
use App\Data\VendorGameUrlResultData;
use App\Data\VendorTransferData;
use App\Enums\Vendor\VendorTransferResultEnum;
use App\Exceptions\ApiException;
use App\Helpers\Common;
use App\Models\Member;
use App\Vendors\AbstractTransferWallet;
use App\Vendors\Contracts\ShouldScheduleAccountCreation;
use App\Vendors\Models\Pussy888Account;
use App\Vendors\Models\Pussy888BetRecord;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class Driver extends AbstractTransferWallet implements ShouldScheduleAccountCreation
{
    protected string $accountModel = Pussy888Account::class;

    protected string $betRecordModel = Pussy888BetRecord::class;

    protected string $actionTitle = 'Pussy888';

    protected string $apiUrl;

    protected string $h5Url;

    protected string $downloadUrl;

    protected string $authcode;

    protected string $secretKey;

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
        $this->apiUrl = $info['API_URL1'] ?? '';
        $this->authcode = $info['API_AUTHCODE'] ?? '';
        $this->secretKey = $info['API_SECRETKEY'] ?? '';
        $this->downloadUrl = $info['DOWNLOAD_URL'] ?? '';
        $this->h5Url = $info['H5_URL'] ?? '';
    }

    public function getGameUrl(VendorGameUrlParamsData $params): VendorGameUrlResultData
    {
        $username = $this->vendorAccount->player_id;
        $password = [REDACTED];
        $url = $this->h5Url . '/?acc=' . $username . '&pw=' . $password;

        $appInfo = VendorGameUrlResultAppInfoData::from([
            'username' => $username,
            'password' => $password,
            'ios_downloads' => [$this->downloadUrl],
            'ios_open' => "pussy888://example.invalid/?user=$username&password=[REDACTED]",
            'android_downloads' => [$this->downloadUrl],
            'android_open' => "pussy888://example.invalid/?user=$username&password=[REDACTED]",
            'app_icon' => $this->vendor->app_icon,
        ]);

        return VendorGameUrlResultData::from([
            'app_infos' => $appInfo,
            'pc' => $url,
            'mobile' => $url,
        ]);
    }

    /**
     * 取得餘額
     */
    public function getBalance(): string
    {
        $action = $this->actionTitle . '_getBalance';
        $username = $this->vendorAccount->player_id;

        $url = $this->apiUrl . 'account/account.ashx';
        $time = Carbon::now()->getTimestampMs();
        $authcode = $this->authcode;
        $secretKey = $this->secretKey;
        $sign = $this->sign($authcode, $username, $time, $secretKey);

        $data = [
            'action' => 'getUserInfo',
            'userName' => $username,
            'time' => $time,
            'authcode' => $authcode,
            'sign' => $sign,
        ];
        $response = Http::logAction($action)->get($url, $data)->throw();
        $json = $response->json();

        if (!$json) {
            throw new ApiException('Third party error: not json response');
        }

        if (!isset($json['success'])) {
            throw new ApiException('Third party error: format error');
        }

        if ($json['success'] !== true) {
            throw new ApiException('Third party error: not success');
        }

        if (!isset($json['MoneyNum'])) {
            throw new ApiException('Third party error: format error');
        }

        return (string) $json['MoneyNum'];
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

        $username = $this->vendorAccount->player_id;

        $url = $this->apiUrl . 'account/setScore.ashx';
        $time = Carbon::now()->getTimestampMs();
        $authcode = $this->authcode;
        $secretKey = $this->secretKey;
        $sign = $this->sign($authcode, $username, $time, $secretKey);

        $data = [
            'action' => 'setServerScore',
            'scoreNum' => $amount,
            'userName' => $username,
            'ActionUser' => $this->vendorInfo->info['AGENT_NAME'],
            'ActionIp' => Common::getIp(),
            'time' => $time,
            'authcode' => $authcode,
            'sign' => $sign,
        ];
        $response = Http::logAction($action)->get($url, $data)->throw();
        $json = $response->json();

        if (!$json) {
            return VendorTransferResultEnum::TIMEOUT;
        }

        if (!isset($json['code']) || !is_numeric($json['code'])) {
            throw new ApiException('Third party error: format error');
        }

        if ((string) $json['code'] != '0') {
            $msg = $json['msg'];
            throw new ApiException('Third party error: ' . $msg);
        }

        if (!isset($json['success'])) {
            throw new ApiException('Third party error: format error');
        }

        if ($json['success'] !== true) {
            throw new ApiException('Third party error: not success');
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

    private function sign($authcode, $username, $time, $secretKey): string
    {
        $sign = "$authcode$username$time$secretKey";
        $sign = strtolower($sign);

        return strtoupper(md5($sign));
    }

    /**
     * 建立我方遊戲商帳號（遷自 RD-Studio create_pussy888_account）
     *
     * 兩步建號：先向三方索取 player_id（RandomUserName），再以該 id 建號（addUser）。
     * getGameUrl 直讀 password 欄明碼組 H5 URL／APP deeplink，落庫與送三方的密碼必須一致。
     */
    public function createAccount(CreateAccountParamsData $params): CreateAccountResultData
    {
        $this->setVendorInfo($params->vendorInfo);
        $username = $params->username;
        $password = [REDACTED];

        $account = $this->accountModel::create([
            'vendor_info_id' => $params->vendorInfo->id,
            'username' => $username,
            'player_id' => '',
            'password' => $password,
            'admin' => 1,
            'member_id' => -999999999,
        ]);

        if (!$account) {
            return CreateAccountResultData::from(['status' => false, 'username' => $username]);
        }

        $playerId = '';

        try {
            $playerId = $this->fetchRandomPlayerId();
            $success = $playerId !== '' && $this->registerVendorAccount($playerId, $username, $password);
        } catch (Throwable $e) {
            Log::error('[Pussy888][createAccount] failed', [
                'username' => $username,
                'error' => $e->getMessage(),
                'trace' => Common::formatTrace($e),
            ]);
            $success = false;
        }

        if (!$success) {
            return CreateAccountResultData::from(['status' => false, 'username' => $username]);
        }

        $account->update(['player_id' => $playerId, 'member_id' => 0]);

        return CreateAccountResultData::from(['status' => true, 'username' => $username]);
    }

    /**
     * RandomUserName：由三方產生一組可用玩家帳號（回傳空字串代表失敗）
     */
    private function fetchRandomPlayerId(): string
    {
        $agent = (string) ($this->vendorInfo->info['AGENT_NAME'] ?? '');
        $time = (string) Carbon::now()->getTimestampMs();

        $data = [
            'action' => 'RandomUserName',
            'userName' => $agent,
            'UserAreaId' => '0',
            'time' => $time,
            'authcode' => $this->authcode,
            'sign' => $this->sign($this->authcode, $agent, $time, $this->secretKey),
        ];

        $json = Http::logAction($this->actionTitle . '_getRandomUserName')
            ->get($this->apiUrl . 'account/account.ashx', $data)
            ->throw()
            ->json();

        if (!is_array($json) || ($json['success'] ?? false) !== true || empty($json['account'])) {
            Log::error('[Pussy888][RandomUserName] failed', ['response' => $json]);

            return '';
        }

        return (string) $json['account'];
    }

    /**
     * addUser：以 player_id 建立三方帳號（Name 帶我方帳號，便於三方後台對照）
     */
    private function registerVendorAccount(string $playerId, string $username, string $password): bool
    {
        $agent = (string) ($this->vendorInfo->info['AGENT_NAME'] ?? '');
        $time = (string) Carbon::now()->getTimestampMs();

        $data = [
            'action' => 'addUser',
            'agent' => $agent,
            'PassWd' => $password,
            'pwdtype' => '1', // 1 = 明文
            'userName' => $playerId,
            'Name' => $username,
            'Tel' => '0912345678',
            'Memo' => 'N/A',
            'UserType' => '1', // 1 = 正式玩家
            'time' => $time,
            'authcode' => $this->authcode,
            'sign' => $this->sign($this->authcode, $playerId, $time, $this->secretKey),
        ];

        $json = Http::logAction($this->actionTitle . '_createAccount')
            ->get($this->apiUrl . 'account/account.ashx', $data)
            ->throw()
            ->json();

        if (!is_array($json) || ($json['success'] ?? false) !== true) {
            Log::error('[Pussy888][addUser] failed', ['player_id' => $playerId, 'response' => $json]);

            return false;
        }

        return true;
    }
}
