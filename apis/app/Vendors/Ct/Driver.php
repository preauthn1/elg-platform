<?php

declare(strict_types=1);

namespace App\Vendors\Ct;

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
use App\Vendors\Models\CtAccount;
use App\Vendors\Models\CtBetRecord;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class Driver extends AbstractTransferWallet implements ShouldScheduleAccountCreation
{
    protected string $accountModel = CtAccount::class;

    protected string $betRecordModel = CtBetRecord::class;

    protected string $actionTitle = 'CT';

    protected string $apiUrl;

    protected string $agentName;

    protected string $apiKey;

    protected string $minAmount;

    protected string $maxAmount;

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
        $this->agentName = $info['AGENT_NAME'];
        $this->apiKey = [REDACTED]'API_KEY'];
        $this->minAmount = (string) ($info['LOGIN_MIN_AMOUNT'] ?? 0);
        $this->maxAmount = (string) ($info['LOGIN_MAX_AMOUNT'] ?? 0);
    }

    public function getGameUrl(VendorGameUrlParamsData $params): VendorGameUrlResultData
    {
        $action = $this->actionTitle . '_api_getGameUrl';
        $username = $this->vendorAccount->username;
        $language = $this->getLanguage($params->language->value);
        $gameCode = $params->gameCode;

        $url = $this->apiUrl . 'api/login/' . $this->agentName . '/';
        $random = md5(uniqid());
        $token = [REDACTED] . $this->apiKey . $random);

        $params = [];
        $params['random'] = $random;
        $params['token'] = $token;
        $params['lang'] = $language;
        $params['member'] = ['username' => $username];
        if ($this->minAmount > 0 && $this->maxAmount > 0) {
            $params['limit'] = [
                'min' => $this->minAmount,
                'max' => $this->maxAmount,
            ];
        }

        $response = Http::logAction($action)->post($url, $params)->throw();
        $json = $response->json();

        if (!$json) {
            throw new ApiException('Third party error: not json response');
        }

        if (!isset($json['codeId']) || !is_numeric($json['codeId'])) {
            throw new ApiException('Third party error: format error');
        }

        if ((string) $json['codeId'] != '0') {
            $code = $json['codeId'];
            throw new ApiException('Third party error: error code ' . $code);
        }

        if (!isset($json['list'][0])) {
            throw new ApiException('Third party error: format error');
        }

        $pc_url = $json['list'][0] . $json['token'];
        $mobile_url = $json['list'][1] . $json['token'];

        return VendorGameUrlResultData::from([
            'pc' => $pc_url,
            'mobile' => $mobile_url,
        ]);
    }

    /**
     * 取得餘額
     */
    public function getBalance(): string
    {
        $action = $this->actionTitle . '_getBalance';
        $username = $this->vendorAccount->username;

        $url = $this->apiUrl . 'api/getBalance/' . $this->agentName . '/';
        $random = md5(uniqid());
        $token = [REDACTED] . $this->apiKey . $random);
        $params = [
            'random' => $random,
            'token' => $token,
            'member' => ['username' => $username],
        ];

        $response = Http::logAction($action)->post($url, $params)->throw();
        $json = $response->json();

        if (!$json) {
            throw new ApiException('Third party error: not json response');
        }

        if (!isset($json['codeId']) || !is_numeric($json['codeId'])) {
            throw new ApiException('Third party error: format error');
        }

        if ((string) $json['codeId'] != '0') {
            $code = $json['codeId'];
            throw new ApiException('Third party error: error code ' . $code);
        }

        if (!isset($json['member']['balance'])) {
            throw new ApiException('Third party error: format error');
        }

        return (string) $json['member']['balance'];
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

        $url = $this->apiUrl . 'api/transfer/' . $this->agentName . '/';
        $uniqid = uniqid();
        $random = md5($uniqid);
        $token = [REDACTED] . $this->apiKey . $random);
        $params = [
            'random' => $random,
            'token' => $token,
            'data' => $billNo,
            'member' => ['username' => $username, 'amount' => $amount],
        ];

        $response = Http::logAction($action)->post($url, $params)->throw();
        $json = $response->json();

        if (!$json) {
            return VendorTransferResultEnum::TIMEOUT;
        }
        if (!isset($json['codeId']) || !is_numeric($json['codeId'])) {
            return VendorTransferResultEnum::TIMEOUT;
        }

        // 確定成功
        if (is_array($json) && isset($json['codeId']) && (string) $json['codeId'] === '0') {
            return VendorTransferResultEnum::SUCCESS;
        }
        // 確定失敗
        if (is_array($json) && isset($json['codeId']) && (string) $json['codeId'] === '324') {
            return VendorTransferResultEnum::FAILED;
        }

        // 其他都視為逾時
        return VendorTransferResultEnum::TIMEOUT;
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

        $url = $this->apiUrl . 'api/checkTransfer/' . $this->agentName . '/';
        $uniqid = uniqid();
        $random = md5($uniqid);
        $token = [REDACTED] . $this->apiKey . $random);
        $params = [
            'random' => $random,
            'token' => $token,
            'data' => $transactionNo,
        ];
        $response = Http::logAction($action)->post($url, $params)->throw();
        $json = $response->json();

        if (!$json) {
            return VendorTransferResultEnum::TIMEOUT;
        }

        if (!isset($json['codeId']) || !is_numeric($json['codeId'])) {
            return VendorTransferResultEnum::TIMEOUT;
        }

        // 0: 成功, 324: 確定失敗, 其他: 未知錯誤
        if ((string) $json['codeId'] === '0') {
            return VendorTransferResultEnum::SUCCESS;
        } elseif ((string) $json['codeId'] === '324') {
            return VendorTransferResultEnum::FAILED;
        }

        return VendorTransferResultEnum::TIMEOUT;
    }

    public function getLanguage(int $languageId): string
    {
        return match ($languageId) {
            LanguageEnum::EN->value => 'en',
            LanguageEnum::ZH_TW->value => 'tw',
            LanguageEnum::ZH_CN->value => 'cn',
            default => 'en',
        };
    }

    /**
     * 建立我方遊戲商帳號（遷自 RD-Studio create_ct_account）
     */
    public function createAccount(CreateAccountParamsData $params): CreateAccountResultData
    {
        $this->setVendorInfo($params->vendorInfo);
        $username = $params->username;

        $account = $this->accountModel::create([
            'vendor_info_id' => $params->vendorInfo->id,
            'username' => $username,
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
     * 建立三方遊戲商帳號（signup）
     */
    private function createVendorAccount(string $username): bool
    {
        $action = $this->actionTitle . '_createAccount';
        $url = $this->apiUrl . 'api/signup/' . $this->agentName . '/';
        $random = md5(uniqid());
        $token = [REDACTED] . $this->apiKey . $random);
        // TABLE_LIMIT / CURRENCY_NAME 僅建號使用，setInfo 未解析，直接取商戶原始設定
        $info = $this->vendorInfo->info;

        $data = [
            'token' => $token,
            'random' => $random,
            'data' => (string) ($info['TABLE_LIMIT'] ?? ''),
            'member' => [
                'username' => $username,
                // 三方 signup 必填但我方不保存：getGameUrl 只送 username，不做密碼驗證
                'password' => md5($random),
                'currencyName' => (string) ($info['CURRENCY_NAME'] ?? ''),
                'winLimit' => 0,
            ],
        ];

        try {
            $json = Http::logAction($action)->post($url, $data)->throw()->json();
        } catch (Throwable $e) {
            Log::error('[Ct][createAccount] API error', [
                'username' => $username,
                'error' => $e->getMessage(),
                'trace' => Common::formatTrace($e),
            ]);

            return false;
        }

        if (!is_array($json) || (string) ($json['codeId'] ?? '') !== '0') {
            Log::error('[Ct][createAccount] API error: codeId is not 0', [
                'username' => $username,
                'response' => $json,
            ]);

            return false;
        }

        return true;
    }
}
