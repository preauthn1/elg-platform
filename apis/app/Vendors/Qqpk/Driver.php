<?php

declare(strict_types=1);

namespace App\Vendors\Qqpk;

use App\Data\Vendor\CreateAccountParamsData;
use App\Data\Vendor\CreateAccountResultData;
use App\Data\VendorGameUrlParamsData;
use App\Data\VendorGameUrlResultData;
use App\Data\VendorTransferData;
use App\Enums\General\DeviceTypeEnum;
use App\Enums\General\OrientationEnum;
use App\Enums\LanguageEnum;
use App\Enums\Transaction\TransactionEnum;
use App\Enums\Vendor\VendorTransferResultEnum;
use App\Exceptions\ApiException;
use App\Exceptions\VendorSetupException;
use App\Helpers\Common;
use App\Models\Member;
use App\Vendors\AbstractTransferWallet;
use App\Vendors\Contracts\ShouldScheduleAccountCreation;
use App\Vendors\Models\QqpkAccount;
use App\Vendors\Models\QqpkBetRecord;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class Driver extends AbstractTransferWallet implements ShouldScheduleAccountCreation
{
    protected string $accountModel = QqpkAccount::class;

    protected string $betRecordModel = QqpkBetRecord::class;

    protected string $actionTitle = 'QQPK';

    protected string $apiUrl;

    protected string $key;

    protected string $iv;

    protected string $merchant;

    protected string $currency;

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
        $requiredKeys = ['apiUrl', 'key', 'iv', 'merchant', 'currency'];
        foreach ($requiredKeys as $key) {
            if (empty($info[$key])) {
                throw new VendorSetupException('Vendor setup error: ' . $key . ' is empty');
            }
        }

        $this->apiUrl = rtrim($info['apiUrl'], '/');
        $this->key = $info['key'];
        $this->iv = $info['iv'];
        $this->merchant = $info['merchant'];
        $this->currency = $info['currency'];
    }

    public function getGameUrl(VendorGameUrlParamsData $params): VendorGameUrlResultData
    {
        $url = $this->apiUrl . '/v1/epoker/Weblogin/geturl';
        $action = $this->actionTitle . '_api_getGameUrl';
        $params = [
            'Account' => $this->vendorAccount->username,
            // 'Ip' => $params->ip,
            'KindId' => (int) $params->gameCode,
            'jumpType' => 1,
            'Currency' => $this->currency,
            'GameLang' => $this->getGameLang($params->language->value),
            'HorizontalMode' => $this->getHorizontalMode($params), // 1:橫屏, 0:直屏
        ];
        $json = $this->sendRequest($action, $url, $params);

        if (!isset($json['status_code'])) {
            throw new ApiException('Third party error: missing status_code');
        }

        if ((string) $json['status_code'] !== '200') {
            throw new ApiException('Third party error: (' . $json['status_code'] . ') ' . $json['message'] ?? '');
        }

        $gameUrl = $json['data']['url'] ?? '';
        if ($gameUrl === '') {
            throw new ApiException('Third party error: missing data.url');
        }

        return VendorGameUrlResultData::from([
            'pc' => $gameUrl,
            'mobile' => $gameUrl,
        ]);
    }

    /**
     * 取得螢幕方向參數（1:橫屏, 0:直屏）
     *
     * 前端有帶 X-Orientation 就依前端；未帶（UNKNOWN）時退回以裝置類型判斷
     */
    protected function getHorizontalMode(VendorGameUrlParamsData $params): int
    {
        return match ($params->orientation) {
            OrientationEnum::LANDSCAPE => 1,
            OrientationEnum::PORTRAIT => 0,
            OrientationEnum::UNKNOWN => $params->deviceType === DeviceTypeEnum::MOBILE ? 0 : 1,
        };
    }

    /**
     * 取得餘額
     */
    public function getBalance(): string
    {
        $url = $this->apiUrl . '/v1/epoker/account/info';
        $action = $this->actionTitle . '_api_getBalance';
        $params = [
            'Account' => $this->vendorAccount->username,
        ];
        $json = $this->sendRequest($action, $url, $params);

        if (!isset($json['status_code'])) {
            throw new ApiException('Third party error: missing status_code');
        }

        if ((string) $json['status_code'] !== '200') {
            throw new ApiException('Third party error: (' . $json['status_code'] . ') ' . $json['message'] ?? '');
        }

        if (!isset($json['data']['totalmoney'])) {
            throw new ApiException('Third party error: missing data.totalmoney');
        }

        if (!isset($json['data']['currency'])) {
            throw new ApiException('Third party error: missing data.currency');
        }

        if ($json['data']['currency'] !== $this->currency) {
            throw new ApiException('Third party error: currency mismatch');
        }

        // 分轉成元
        $balance = Common::numDiv($json['data']['totalmoney'], 100, 4);

        return $balance;
    }

    /**
     * 轉帳
     */
    protected function transfer(string $username, int $type, string $credit, string $billNo): VendorTransferResultEnum
    {
        if ($type == 1) {
            // 存款
            $url = $this->apiUrl . '/v1/epoker/fraction/upper';
            $action = $this->actionTitle . '_Deposit';
        } else {
            // 提款
            $url = $this->apiUrl . '/v1/epoker/fraction/under';
            $action = $this->actionTitle . '_Withdrawal';
        }

        // 元轉成分, 只接受正整數的字串
        $credit = Common::numMul($credit, 100, 0);
        $params = [
            'Account' => $this->vendorAccount->username,
            'Money' => $credit,
            'OrderNo' => $billNo,
            'Type' => $type,
            'Currency' => $this->currency,
        ];

        $json = $this->sendRequest($action, $url, $params);

        if (!isset($json['status_code'])) {
            throw new ApiException('Third party error: missing status_code');
        }

        if ((string) $json['status_code'] !== '200') {
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

    public function checkTransferStatus(string $transactionNo): VendorTransferResultEnum
    {
        $url = $this->apiUrl . '/v1/epoker/fraction/getstatus';
        $action = $this->actionTitle . '_CheckTransferStatus';
        $type = str_starts_with($transactionNo, 'U') ? 11 : 12;
        $params = [
            'OrderNo' => $transactionNo,
            'Type' => $type,
        ];
        $json = $this->sendRequest($action, $url, $params);

        if (!isset($json['status_code'])) {
            return VendorTransferResultEnum::TIMEOUT;
        }

        if ((string) $json['status_code'] !== '200') {
            return VendorTransferResultEnum::TIMEOUT;
        }

        if (!isset($json['data']['status'])) {
            return VendorTransferResultEnum::TIMEOUT;
        }

        if ((string) $json['data']['status'] == '1') {
            // 成功
            return VendorTransferResultEnum::SUCCESS;
        } elseif ((string) $json['data']['status'] == '0') {
            // 處理中
            return VendorTransferResultEnum::TIMEOUT;
        } elseif ((string) $json['data']['status'] == '2') {
            // 失敗
            return VendorTransferResultEnum::FAILED;
        } elseif ((string) $json['data']['status'] == '-1') {
            // 訂單不存在
            return VendorTransferResultEnum::FAILED;
        }

        return VendorTransferResultEnum::TIMEOUT;
    }

    /**
     * 遊戲商有指定訂單編號格式
     */
    public function getTransactionNo(?TransactionEnum $transactionEnum = null): string
    {
        if ($transactionEnum == TransactionEnum::TO_VENDOR) {
            $orderPrefix = 'U';
        } elseif ($transactionEnum == TransactionEnum::FROM_VENDOR) {
            $orderPrefix = 'D';
        } else {
            throw new \Exception('TransactionEnum not supported');
        }

        // 前綴+15個英文數字
        return $orderPrefix . strtoupper(Str::random(15));
    }

    private function sendRequest(string $action, string $url, array $params): array
    {
        $encrypted = $this->encryptParams($params, $this->key, $this->iv);

        $postData = [
            'b' => $encrypted,
            'm' => $this->merchant,
            't' => time(),
        ];

        $response = Http::logAction($action, $params)
            ->asJson()
            ->post($url, $postData)
            ->throw();

        $json = $response->json();
        if (!$json) {
            throw new ApiException('Third party error: not json response');
        }

        return $json;
    }

    /**
     * AES-256-CBC 加密
     */
    private function encryptParams(array $params, string $key, string $iv): string
    {
        $json = json_encode($params);
        $cipher = 'aes-256-cbc';
        $encrypted = openssl_encrypt($json, $cipher, $key, OPENSSL_RAW_DATA, $iv);

        return base64_encode($encrypted);
    }

    private function decryptParams(string $encrypted, string $key, string $iv): array
    {
        $cipher = 'aes-256-cbc';
        $decodeText = base64_decode($encrypted);
        $decrypted = openssl_decrypt($decodeText, $cipher, $key, OPENSSL_RAW_DATA, $iv);
        $json = json_decode($decrypted, true);

        return $json;
    }

    /**
     * @see https://example.invalid/qqpoker-seamless-wallet-api/xiang-guan-dui-zhao-biao/yu-yan-can-shu-dui-zhao-biao.md
     */
    private function getGameLang(int $languageId): string
    {
        return match ($languageId) {
            LanguageEnum::ZH_CN->value => 'zh-CN',
            LanguageEnum::ZH_TW->value => 'zh-TW',
            LanguageEnum::VI_VN->value => 'vi-VN',
            LanguageEnum::TH_TH->value => 'th-TH',
            LanguageEnum::ID_ID->value => 'en-US',
            LanguageEnum::EN->value => 'en-US',
            default => 'en-US',
        };
    }

    /**
     * 建立我方遊戲商帳號（遷自 RD-Studio create_qqpk_account）
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

        if (!$this->createVendorAccount($username)) {
            return CreateAccountResultData::from(['status' => false, 'username' => $username]);
        }

        $account->update(['member_id' => 0]);

        return CreateAccountResultData::from(['status' => true, 'username' => $username]);
    }

    /**
     * 建立三方遊戲商帳號
     *
     * QQPK 無獨立註冊 API：Weblogin/geturl 首次帶入新 Account 即隱式建號；
     * KindId 固定 902 僅為觸發用（沿用舊系統魔法值）。
     */
    private function createVendorAccount(string $username): bool
    {
        $action = $this->actionTitle . '_CreateAccount';
        $url = $this->apiUrl . '/v1/epoker/Weblogin/geturl';

        $data = [
            'Account' => $username,
            'Currency' => $this->currency,
            'KindId' => 902,
        ];

        try {
            $json = $this->sendRequest($action, $url, $data);
        } catch (Throwable $e) {
            Log::error("[QQPK][{$action}] request failed", [
                'username' => $username,
                'error' => $e->getMessage(),
                'trace' => Common::formatTrace($e),
            ]);

            return false;
        }

        if (!isset($json['status_code']) || (string) $json['status_code'] !== '200') {
            Log::error("[QQPK][{$action}] API error: status_code is not 200", [
                'username' => $username,
                'response' => $json,
            ]);

            return false;
        }

        return true;
    }
}
