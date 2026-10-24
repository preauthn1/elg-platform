<?php

declare(strict_types=1);

namespace App\Vendors\Mega888;

use App\Data\Vendor\CreateAccountParamsData;
use App\Data\Vendor\CreateAccountResultData;
use App\Data\VendorGameUrlParamsData;
use App\Data\VendorGameUrlResultAppInfoData;
use App\Data\VendorGameUrlResultData;
use App\Data\VendorResetPasswordData;
use App\Data\VendorTransferData;
use App\Enums\Transaction\TransactionEnum;
use App\Enums\Vendor\VendorTransferResultEnum;
use App\Exceptions\AccountNotExistsException;
use App\Exceptions\ApiException;
use App\Helpers\Common;
use App\Models\Member;
use App\Vendors\AbstractTransferWallet;
use App\Vendors\Contracts\ShouldScheduleAccountCreation;
use App\Vendors\Models\Mega888Account;
use App\Vendors\Models\Mega888BetRecord;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Spatie\LaravelData\Data;

class Driver extends AbstractTransferWallet implements ShouldScheduleAccountCreation
{
    /** Mega888 錯誤碼：會員不存在（checkUserExist failed），玩家已被三方清除 */
    private const string ACCOUNT_NOT_EXISTS_CODE = '37111';

    protected string $accountModel = Mega888Account::class;

    protected string $betRecordModel = Mega888BetRecord::class;

    private VendorInfoDetailData $vendorInfoDetailData;

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
        $this->vendorInfoDetailData = VendorInfoDetailData::from($info);
    }

    public function getTransactionNo(?TransactionEnum $transactionEnum = null): string
    {
        $number = date('mHis') . rand(10, 99); // 交易編號

        if ($this->member->is_event_wallet || $this->agent->is_multi_wallet) {
            $logModel = \App\Models\WalletTransactionLog::query();
        } else {
            $logModel = \App\Models\TransactionLog::query();
        }

        $isExist = $logModel->where('transaction_no', $number)->exists();

        return $isExist ? $this->getTransactionNo($transactionEnum) : $number;
    }

    /**
     * -修改密碼(改自己的db)
     */
    public function resetPassword(VendorResetPasswordData $params): VendorTransferResultEnum
    {
        $this->vendorAccount->update(['password' => Hash::make($params->newPassword)]);

        return VendorTransferResultEnum::SUCCESS;
    }

    public function getGameUrl(VendorGameUrlParamsData $params): VendorGameUrlResultData
    {
        $digest = $this->digest('');

        $data = [
            'digest' => $digest['digest'],
            'random' => $digest['random'],
            'sn' => $this->vendorInfoDetailData->SN,
            'agentLoginId' => $this->vendorInfoDetailData->ACCOUNT,
        ];

        $response = $this->apiCall('example.invalid.url.download', $data, 'getGameUrl');

        if (!is_array($response)) {
            throw new ApiException('[Mega888][getGameUrl] API call failed: response not array');
        }

        if (isset($response['error']) && $response['error'] != null) {
            throw new ApiException("[Mega888][getGameUrl] API call failed: {$response['error']}");
        }

        if (!isset($response['result'])) {
            throw new ApiException('[Mega888][getGameUrl] API call failed: result not found');
        }

        if ($this->vendorAccount->password == '') {
            // 未設置密碼
            $password = [REDACTED]'error_message.9009');
        } else {
            // 已設置過密碼
            $password = [REDACTED]'error_message.9010');
        }

        $appInfo = VendorGameUrlResultAppInfoData::from([
            'username' => $this->vendorAccount->player_id, // Not username
            'password' => $password,
            'ios_downloads' => [
                $this->vendorInfoDetailData->DOWNLOAD_URL,
                $this->vendorInfoDetailData->DOWNLOAD_URL2,
            ],
            'ios_open' => '',
            'android_downloads' => [
                $this->vendorInfoDetailData->DOWNLOAD_URL,
                $this->vendorInfoDetailData->DOWNLOAD_URL2,
            ],
            'android_open' => '',
            'app_icon' => $this->vendor->app_icon,
        ]);

        return VendorGameUrlResultData::from([
            'pc' => '',
            'mobile' => '',
            'app_infos' => $appInfo,
        ]);
    }

    public function getBalance(): string
    {
        $action = 'getBalance';
        $digest = $this->digest($this->vendorAccount->player_id);
        $data = [
            'digest' => $digest['digest'],
            'random' => $digest['random'],
            'sn' => $this->vendorInfoDetailData->SN,
            'loginId' => $this->vendorAccount->player_id,
        ];

        $response = $this->apiCall('open.mega.balance.get', $data, $action);

        if (!is_array($response)) {
            throw new ApiException("[Mega888][{$action}] API call failed: response not array");
        }

        if (isset($response['error']) && $response['error'] != null) {
            $error = is_array($response['error']) ? json_encode($response['error']) : $response['error'];

            // 三方已把玩家清掉（長期未登入），本地帳號紀錄還在：讓呼叫端可判定「無餘額可轉」而非三方故障
            if (is_array($response['error']) && (string) ($response['error']['code'] ?? '') === self::ACCOUNT_NOT_EXISTS_CODE) {
                throw new AccountNotExistsException("[Mega888][{$action}] account not exists: {$error}");
            }

            throw new ApiException("[Mega888][{$action}] API call failed: {$error}");
        }

        if (!isset($response['result'])) {
            throw new ApiException("[Mega888][{$action}] API call failed: balance not found");
        }

        return Common::numFloor($response['result'], 4);
    }

    public function checkTransferStatus(string $transactionNo): VendorTransferResultEnum
    {
        $action = 'checkTransferStatus';
        $digest = $this->digest('');
        $data = [
            'digest' => $digest['digest'],
            'random' => $digest['random'],
            'sn' => $this->vendorInfoDetailData->SN,
            'loginId' => $this->vendorAccount->player_id,
            'agentLoginId' => $this->vendorInfoDetailData->ACCOUNT,
            'bizId' => $transactionNo,
        ];

        $response = $this->apiCall('open.mega.balance.transfer.query', $data, $action);

        if (!is_array($response)) {
            Log::error("[Mega888][{$action}] API call failed: response not array", ['response' => $response]);

            return VendorTransferResultEnum::TIMEOUT;
        }

        if (isset($response['error']) && $response['error'] != null) {
            Log::error("[Mega888][{$action}] API call failed", ['response' => $response]);

            return VendorTransferResultEnum::FAILED;
        }

        return VendorTransferResultEnum::SUCCESS;
    }

    /**
     * 玩家餘額轉入遊戲商 (存款), origin: type = 1
     */
    public function transferToVendor(VendorTransferData $params): VendorTransferResultEnum
    {
        return $this->transfer($params);
    }

    /**
     * 轉出遊戲商餘額回本地 (提款), origin: type != 1
     */
    public function transferToLocal(VendorTransferData $params): VendorTransferResultEnum
    {
        $params->vendorAmount = Common::numMul($params->vendorAmount, -1);

        return $this->transfer($params);
    }

    private function transfer(VendorTransferData $params): VendorTransferResultEnum
    {
        $action = $params->vendorAmount < 0 ? 'TransferToLocal' : 'TransferToVendor';
        $digest = $this->digest($this->vendorAccount->player_id . $params->vendorAmount);
        $data = [
            'digest' => $digest['digest'],
            'random' => $digest['random'],
            'sn' => $this->vendorInfoDetailData->SN,
            'loginId' => $this->vendorAccount->player_id,
            'amount' => $params->vendorAmount,
            'bizId' => $params->transactionNo,
            'checkBizId' => 0,
        ];

        $response = $this->apiCall('open.mega.balance.transfer', $data, $action);

        if (!is_array($response)) {
            Log::error("[Mega888][{$action}] API call failed: response not array", ['response' => $response]);

            return VendorTransferResultEnum::TIMEOUT;
        }

        if (isset($response['error']) && $response['error'] != null) {
            Log::error("[Mega888][{$action}] API call failed", ['response' => $response]);

            return VendorTransferResultEnum::FAILED;
        }

        return VendorTransferResultEnum::SUCCESS;
    }

    private function digest(string $string): array
    {
        $random = Str::uuid();
        $result = [
            'random' => $random,
            'digest' => strtoupper(md5($random . $this->vendorInfoDetailData->SN . $string . $this->vendorInfoDetailData->API_KEY)),
        ];

        return $result;
    }

    private function apiCall(string $url, array $data, string $action = ''): mixed
    {
        $action = $action ?: $url;

        $postData = [
            'id' => $data['random'],
            'method' => $url,
            'params' => $data,
            'jsonrpc' => '2.0',
        ];

        $response = Http::logAction("Mega888_{$url}", $postData)
            ->withHeaders([
                'Content-Type' => 'application/json',
            ])
            ->post($this->vendorInfoDetailData->API_URL . $url, $postData)
            ->json();

        return $response;
    }

    /**
     * 建立我方遊戲商帳號（遷自 RD-Studio create_mega888_account）
     *
     * password 刻意不寫：Mega888 三方登入不用密碼，getGameUrl 是用 password 是否為空
     * 決定顯示「未設密碼／已設密碼」提示；三方登入／轉帳／餘額全以 player_id 為鍵，
     * 成功後必須把 loginId 寫回 player_id。
     */
    public function createAccount(CreateAccountParamsData $params): CreateAccountResultData
    {
        $this->setVendorInfo($params->vendorInfo);
        $username = $params->username;

        $account = $this->accountModel::create([
            'vendor_info_id' => $params->vendorInfo->id,
            'username' => $username,
            'admin' => 1,
            'member_id' => -999999999,
        ]);

        if (!$account) {
            return CreateAccountResultData::from(['status' => false, 'username' => $username]);
        }

        $created = $this->createVendorAccount($username);

        if ($created === null) {
            return CreateAccountResultData::from(['status' => false, 'username' => $username]);
        }

        $account->update([
            'player_id' => $created['loginId'],
            'user_id' => $created['userId'],
            'member_id' => 0,
        ]);

        return CreateAccountResultData::from(['status' => true, 'username' => $username]);
    }

    /**
     * 建立三方遊戲商帳號
     *
     * @return array{loginId: string, userId: string}|null null = 失敗
     */
    private function createVendorAccount(string $username): ?array
    {
        $action = 'createAccount';
        $digest = $this->digest('');

        $data = [
            'random' => (string) $digest['random'],
            'digest' => $digest['digest'],
            'sn' => $this->vendorInfoDetailData->SN,
            'nickname' => $username,
            'agentLoginId' => $this->vendorInfoDetailData->ACCOUNT,
        ];

        $response = $this->apiCall('open.mega.user.create', $data, $action);

        if (!is_array($response) || ($response['result']['success'] ?? false) !== true) {
            Log::error("[Mega888][{$action}] API call failed", [
                'username' => $username,
                'response' => $response,
            ]);

            return null;
        }

        if (!isset($response['result']['loginId'], $response['result']['userId'])) {
            Log::error("[Mega888][{$action}] API call failed: loginId/userId not found", [
                'username' => $username,
                'response' => $response,
            ]);

            return null;
        }

        return [
            'loginId' => (string) $response['result']['loginId'],
            'userId' => (string) $response['result']['userId'],
        ];
    }
}

class VendorInfoDetailData extends Data
{
    public function __construct(
        public string $API_URL,
        public string $API_KEY,
        public string $SN,
        public string $ACCOUNT,

        public ?string $DOWNLOAD_URL = '',
        public ?string $DOWNLOAD_URL2 = '',
    ) {}
}
