<?php

declare(strict_types=1);

namespace App\Vendors\Npt;

use App\Data\Vendor\CreateAccountParamsData;
use App\Data\Vendor\CreateAccountResultData;
use App\Data\VendorGameUrlParamsData;
use App\Data\VendorGameUrlResultData;
use App\Data\VendorTransferData;
use App\Enums\LanguageEnum;
use App\Enums\Vendor\VendorTransferResultEnum;
use App\Exceptions\ApiException;
use App\Helpers\Common;
use App\Models\Member;
use App\Vendors\AbstractTransferWallet;
use App\Vendors\Contracts\ShouldScheduleAccountCreation;
use App\Vendors\Models\NptAccount;
use App\Vendors\Models\NptBetRecord;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class Driver extends AbstractTransferWallet implements ShouldScheduleAccountCreation
{
    protected string $accountModel = NptAccount::class;

    protected string $betRecordModel = NptBetRecord::class;

    protected string $actionTitle = 'NPT';

    protected string $apiUrl;

    protected string $adminName;

    protected string $entityKey;

    protected string $certPath;

    protected string $playerPrefix;

    protected string $apiKey;

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
        $this->apiUrl = rtrim($info['API_URL'], '/');
        $this->adminName = $info['ADMIN_NAME'];
        $this->entityKey = $info['ENTITY_KEY'];
        $this->certPath = $info['CERT_PATH'];
        $this->playerPrefix = $info['PLAYER_PREFIX'];
    }

    public function getGameUrl(VendorGameUrlParamsData $params): VendorGameUrlResultData
    {
        $action = $this->actionTitle . '_api_getGameUrl';
        $username = $this->vendorAccount->username_modified;
        $password = [REDACTED], $this->vendorAccount->created_at->toDateTimeString());
        $language = $this->getLanguage($params->language->value);
        $gameCode = $params->gameCode;

        $extra = [
            'username' => $username,
            'password' => $password,
            'lang' => $language,
            'mode' => 'real',
            'game' => $gameCode,
            'client' => $params->remark,
        ];

        return VendorGameUrlResultData::from([
            'extra' => $extra,
        ]);
    }

    /**
     * 取得餘額
     */
    public function getBalance(): string
    {
        $action = $this->actionTitle . '_getBalance';
        $username = $this->vendorAccount->username;

        $url = $this->apiUrl . '/player/balance';
        $data = [
            'playername' => $this->playerPrefix . $username,
        ];
        $json = $this->request($action, $url, $data);

        if (!$json) {
            throw new ApiException('Third party error: not json response');
        }

        if (isset($json['error'])) {
            $error = $json['error'];
            $code = $json['errorcode'];
            throw new ApiException('Third party error: ' . $error . ', error code: ' . $code);
        }

        if (!isset($json['result']['balance'])) {
            throw new ApiException('Third party error: format error');
        }

        $balance = (string) $json['result']['balance'];

        return $balance;
    }

    protected function logout(string $username): void
    {
        $action = $this->actionTitle . '_logout';
        $url = $this->apiUrl . '/player/logout';
        $data = [
            'playername' => $this->playerPrefix . $username,
        ];
        $this->request($action, $url, $data);
    }

    /**
     * 轉帳
     */
    protected function transfer(string $username, int $type, string $credit, string $billNo): VendorTransferResultEnum
    {
        if ($type == 1) {
            // 存款
            $url = $this->apiUrl . '/player/deposit';
            $action = $this->actionTitle . '_Deposit';
        } else {
            // 先登出, 不然不能提款
            $this->logout($username);

            // 提款
            $url = $this->apiUrl . '/player/withdraw';
            $action = $this->actionTitle . '_Withdrawal';
        }

        // 準備轉帳取轉帳編號
        $data = [
            'playername' => $this->playerPrefix . $username,
            'adminname' => $this->adminName,
            'amount' => floatval($credit),
            'externaltranid' => $billNo,
        ];

        $json = $this->request($action, $url, $data);

        if (!$json) {
            return VendorTransferResultEnum::TIMEOUT;
        }

        if (isset($json['error'])) {
            $error = $json['error'];
            $code = $json['errorcode'];
            throw new ApiException('Third party error: ' . $error . ', error code: ' . $code);
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
     * 轉帳確認
     */
    public function checkTransferStatus(string $transactionNo): VendorTransferResultEnum
    {
        $action = $this->actionTitle . '_CheckTransferStatus';
        $url = $this->apiUrl . '/player/checktransaction';

        // 準備轉帳取轉帳編號
        $data = [
            'externaltransactionid' => $transactionNo,
        ];

        $json = $this->request($action, $url, $data);

        if (!$json) {
            return VendorTransferResultEnum::TIMEOUT;
        }

        if (isset($json['error'])) {
            return VendorTransferResultEnum::TIMEOUT;
        }

        // approved|declined|missing|waiting|notallowed
        $result = $json['result']['status'] ?? '';
        if ($result == 'approved') {
            return VendorTransferResultEnum::SUCCESS;
        } elseif ($result == 'declined') {
            return VendorTransferResultEnum::FAILED;
        } elseif ($result == 'missing') {
            return VendorTransferResultEnum::FAILED;
        } elseif ($result == 'waiting') {
            return VendorTransferResultEnum::TIMEOUT;
        } elseif ($result == 'notallowed') {
            return VendorTransferResultEnum::FAILED;
        }

        return VendorTransferResultEnum::TIMEOUT;
    }

    protected function request(string $action, string $url, array $data): array
    {
        $headers = [
            'X_ENTITY_KEY' => $this->entityKey,
        ];
        $basePath = base_path();

        return Http::logAction($action)
            ->asForm()
            ->withHeaders($headers)
            ->withOptions([
                'verify' => false,  // 忽略 SSL 憑證驗證, 舊版也是這樣寫, NPT 根本沒啟用 https
                'cert' => $basePath . $this->certPath . '.pem',
                'ssl_key' => $basePath . $this->certPath . '.key',
            ])
            ->post($url, $data)
            ->throw()
            ->json();
    }

    protected function getLanguage(int $languageId): string
    {
        return match ($languageId) {
            LanguageEnum::EN->value => 'EN',
            LanguageEnum::ZH_TW->value => 'CH',
            LanguageEnum::ZH_CN->value => 'ZH-CN',
            LanguageEnum::TH_TH->value => 'TH',
            LanguageEnum::MS_MY->value => 'MS',
            default => 'EN',
        };
    }

    /**
     * 建立我方遊戲商帳號（遷自 RD-Studio create_npt_account）
     */
    public function createAccount(CreateAccountParamsData $params): CreateAccountResultData
    {
        $this->setVendorInfo($params->vendorInfo);
        $username = $params->username;
        $playerName = $this->playerPrefix . $username;

        // npt_accounts.username_modified / password 皆 NOT NULL 無預設
        $account = $this->accountModel::create([
            'vendor_info_id' => $params->vendorInfo->id,
            'username' => $username,
            'username_modified' => $playerName,
            'password' => '',
            'admin' => 1,
            'member_id' => -999999999,
        ]);

        if (!$account) {
            return CreateAccountResultData::from(['status' => false, 'username' => $username]);
        }

        // 必須與 getGameUrl 同式（getDefaultPassword）
        $password = [REDACTED], $account->created_at->toDateTimeString());
        $account->update(['password' => $password]);

        $result = $this->createVendorAccount($playerName, $password);

        if ($result === null) {
            return CreateAccountResultData::from(['status' => false, 'username' => $username]);
        }

        // 三方回傳的 playername 才是注單上的識別值（username_modified 為注單 JOIN 鍵）
        $account->update(['username_modified' => $result['playername'], 'member_id' => 0]);

        // 三方也會回一組 password；登入走 getDefaultPassword 重算，兩者不同代表三方改了密碼，必須吵出來
        if ($result['password'] !== '' && $result['password'] !== $password) {
            Log::warning('[Npt][createAccount] 三方回傳密碼與我方送出不一致，該帳號登入將失敗', [
                'username' => $username,
            ]);
        }

        return CreateAccountResultData::from(['status' => true, 'username' => $username]);
    }

    /**
     * 建立三方遊戲商帳號
     *
     * @return array{playername: string, password: [REDACTED] 失敗回 null
     */
    private function createVendorAccount(string $playerName, string $password): ?array
    {
        $data = [
            'playername' => $playerName,
            'adminname' => $this->adminName,
            'password' => $password,
            'viplevel' => (string) ($this->vendorInfo->info['VIPLEVEL'] ?? ''),
        ];

        try {
            $json = $this->request($this->actionTitle . '_createAccount', $this->apiUrl . '/player/create', $data);
        } catch (Throwable $e) {
            Log::error('[Npt][createAccount] request failed', [
                'playername' => $playerName,
                'error' => $e->getMessage(),
                'trace' => Common::formatTrace($e),
            ]);

            return null;
        }

        if (isset($json['error']) || !isset($json['result']['playername'])) {
            Log::error('[Npt][createAccount] Third party error', [
                'playername' => $playerName,
                'response' => $json,
            ]);

            return null;
        }

        return [
            'playername' => (string) $json['result']['playername'],
            'password' => (string) ($json['result']['password'] ?? ''),
        ];
    }
}
