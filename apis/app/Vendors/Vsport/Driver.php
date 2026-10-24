<?php

declare(strict_types=1);

namespace App\Vendors\Vsport;

use App\Data\Vendor\CreateAccountParamsData;
use App\Data\Vendor\CreateAccountResultData;
use App\Data\VendorGameUrlParamsData;
use App\Data\VendorGameUrlResultData;
use App\Data\VendorTransferData;
use App\Enums\General\ContextKeyEnum;
use App\Enums\General\HttpCode;
use App\Enums\LanguageEnum;
use App\Enums\Vendor\VendorTransferResultEnum;
use App\Exceptions\ApiException;
use App\Helpers\Common;
use App\Models\Member;
use App\Vendors\AbstractTransferWallet;
use App\Vendors\Contracts\ShouldScheduleAccountCreation;
use App\Vendors\Models\VsportAccount;
use App\Vendors\Models\VsportBetRecord;
use Exception;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * VSport 53
 */
class Driver extends AbstractTransferWallet implements ShouldScheduleAccountCreation
{
    protected string $accountModel = VsportAccount::class;

    protected string $betRecordModel = VsportBetRecord::class;

    /**
     * @var mixed|string
     */
    private mixed $apiUrl;

    private mixed $apiSecret;

    private mixed $apiAgent;

    private mixed $accType;

    private mixed $host;

    private mixed $theme;

    /**
     * @var mixed|string
     */
    private mixed $username;

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
    }

    public function setInfo(array $info): void
    {
        $this->apiUrl = $info['API_URL'];
        $this->apiSecret = [REDACTED]'SECRET'];
        $this->apiAgent = $info['AGENT'];
        $this->accType = $info['ACC_TYPE'];
        $this->host = $info['HOST'];
        $this->theme = $info['THEME'] ?? '';
    }

    public function getGameUrl(VendorGameUrlParamsData $params): VendorGameUrlResultData
    {
        $data = [
            'action' => 'login_mobile',
            'secret' => $this->apiSecret,
            'agent' => $this->apiAgent,
            'username' => $this->username,
            'lang' => $this->mapLanguageCode(),
            'accType' => $this->accType,
            'host' => $this->host, // 文檔寫 bettingHost 但實際上是 host
            'theme' => $this->theme,
        ];

        $result = $this->sendRequest($data);

        if (!isset($result['errcode']) || (string) $result['errcode'] !== '0') {
            $this->throwError($result['errtext'] ?? 'Unknown error', [
                'result' => $result,
            ]);
        }

        $gameUrl = $result['result'] ?? '';

        return VendorGameUrlResultData::from([
            'pc' => $gameUrl,
            'mobile' => $gameUrl,
        ]);
    }

    public function getBalance(): string
    {
        $data = [
            'action' => 'balance',
            'secret' => $this->apiSecret,
            'agent' => $this->apiAgent,
            'username' => $this->username,
        ];

        $result = $this->sendRequest($data);

        if (!isset($result['errcode']) || (string) $result['errcode'] !== '0') {
            $this->throwError($result['errtext'] ?? 'Unknown error', [
                'result' => $result,
            ]);
        }

        return (string) $result['result'];
    }

    /**
     * 玩家餘額轉入遊戲商 (存款), origin: type = 1
     */
    public function transferToVendor(VendorTransferData $params): VendorTransferResultEnum
    {
        $data = [
            'action' => 'deposit',
            'secret' => $this->apiSecret,
            'agent' => $this->apiAgent,
            'username' => $this->username,
            'serial' => $params->transactionNo,
            'amount' => $params->vendorAmount,
        ];

        return $this->transfer($data);
    }

    /**
     * 轉出遊戲商餘額回本地 (提款), origin: type != 1
     */
    public function transferToLocal(VendorTransferData $params): VendorTransferResultEnum
    {
        $data = [
            'action' => 'withdraw',
            'secret' => $this->apiSecret,
            'agent' => $this->apiAgent,
            'username' => $this->username,
            'serial' => $params->transactionNo,
            'amount' => $params->vendorAmount,
        ];

        return $this->transfer($data);
    }

    private function transfer(array $data): VendorTransferResultEnum
    {
        try {
            $result = $this->sendRequest($data);
        } catch (Exception $e) {
            return VendorTransferResultEnum::TIMEOUT;
        }

        if (!is_array($result)) {
            Log::error($this->getName() . ' checkTransferStatus failed: response is not array');

            return VendorTransferResultEnum::TIMEOUT;
        }

        if (!isset($result['errcode']) || !isset($result['result'])) {
            Log::error($this->getName() . ' checkTransferStatus failed: parameter "result" is not found');

            return VendorTransferResultEnum::TIMEOUT;
        }

        // No result
        if ((string) $result['errcode'] == '2') {
            return VendorTransferResultEnum::FAILED;
        }

        if ((string) $result['errcode'] === '0') {
            return VendorTransferResultEnum::SUCCESS;
        }

        // 操作太頻繁
        if ((string) $result['errcode'] === '-10') {
            return VendorTransferResultEnum::FAILED;
        }

        return VendorTransferResultEnum::TIMEOUT;
    }

    /**
     * 檢查轉帳結果
     */
    public function checkTransferStatus(string $transactionNo): VendorTransferResultEnum
    {
        $data = [
            'action' => 'check_payment',
            'secret' => $this->apiSecret,
            'agent' => $this->apiAgent,
            'username' => $this->username,
            'serial' => $transactionNo,
        ];

        try {
            $result = $this->sendRequest($data);
        } catch (Exception $e) {
            return VendorTransferResultEnum::TIMEOUT;
        }

        if (!is_array($result)) {
            Log::error($this->getName() . ' checkTransferStatus failed: response is not array');

            return VendorTransferResultEnum::TIMEOUT;
        }

        if (!isset($result['errcode']) || !isset($result['result'])) {
            Log::error($this->getName() . ' checkTransferStatus failed: parameter "result" is not found');

            return VendorTransferResultEnum::TIMEOUT;
        }

        // 0成功，1查無資料
        if ((string) $result['errcode'] === '0' && isset($result['result']['payment'])) {
            $collection = collect($result['result']['payment']);

            // 檢查交易紀錄是否存在
            if ($collection->count() == 1) {
                $record = $collection->first();
                if ($record->username === $this->username) {
                    return VendorTransferResultEnum::SUCCESS;
                }
            }

            return VendorTransferResultEnum::FAILED;
        }

        return VendorTransferResultEnum::TIMEOUT;
    }

    private function mapLanguageCode(?LanguageEnum $language = null): string
    {
        if (empty($language)) {
            $languageId = Context::getHidden(ContextKeyEnum::LANGUAGE_ID->value);
        } else {
            $languageId = $language->value;
        }

        return match ($languageId) {
            LanguageEnum::ZH_TW->value, LanguageEnum::ZH_CN->value => 'ZH-CN,',
            LanguageEnum::ID_ID->value => 'ID-ID,',
            LanguageEnum::VI_VN->value => 'VI-VN',
            LanguageEnum::TH_TH->value => 'TH-TH',
            LanguageEnum::MY_MM->value => 'MY-MM',
            default => 'EN-US',
        };
    }

    /**
     * Vsport API uses GET method
     */
    private function sendRequest(array $data): mixed
    {
        $backtrace = debug_backtrace();
        $previousMethod = $backtrace[1]['function'] ?? null;
        $action = $this->getName() . ' ' . $previousMethod;

        $apiUrl = rtrim($this->apiUrl, '/');
        $url = $apiUrl;

        try {
            $result = Http::logAction($action)
                ->asJson()
                ->get($url, $data)
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
        $vendorName = $this->getName();

        $context = array_merge([
            'vendor_id' => $this->vendor->id,
            'username' => $this->username,
        ], $context);

        Log::error("$vendorName $message", $context);

        throw new ApiException($message, HttpCode::HTTP_INTERNAL_SERVER_ERROR->value);
    }

    /**
     * 建立我方遊戲商帳號（遷自 RD-Studio create_vsport_account）
     *
     * 三方限制 username 最長 11 碼（前綴需 ≤ 3 碼）；create 後必須接 update 設限紅，
     * 兩段都成功才算建號完成。
     */
    public function createAccount(CreateAccountParamsData $params): CreateAccountResultData
    {
        $this->setVendorInfo($params->vendorInfo);
        $username = $params->username;
        // sendRequest()/throwError() 會讀 $this->username，排程情境無會員先注入
        $this->username = $username;

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
     * 建立三方遊戲商帳號
     */
    private function createVendorAccount(string $username): bool
    {
        $data = [
            'secret' => $this->apiSecret,
            'action' => 'create',
            'agent' => $this->apiAgent,
            'username' => $username,
        ];

        if (!$this->isCreateStepSuccess($data, 'createVendorAccount')) {
            return false;
        }

        return $this->updateVendorAccountLimit($username);
    }

    /**
     * 建號後設定限紅／佣金（舊系統 CreateVsportAccount::updateAccount）
     */
    private function updateVendorAccountLimit(string $username): bool
    {
        $info = $this->vendorInfo->info;
        $limit = $info['LIMIT'] ?? [];
        $com = $info['COM'] ?? [];

        $data = [
            'secret' => $this->apiSecret,
            'action' => 'update',
            'agent' => $this->apiAgent,
            'username' => $username,
            'comtype' => $info['COMTYPE'] ?? 0,
            'suspend' => $info['SUSPEND'] ?? 0,
        ];

        for ($i = 0; $i < 5; $i++) {
            $data['max' . ($i + 1)] = $limit['max'][$i] ?? 0;
            $data['lim' . ($i + 1)] = $limit['lim'][$i] ?? 0;
        }

        for ($i = 0; $i < 4; $i++) {
            $data['com' . ($i + 1)] = $com[$i] ?? 0;
        }

        return $this->isCreateStepSuccess($data, 'updateVendorAccountLimit');
    }

    /**
     * 送出建號請求並判定 errcode，失敗只記錄不拋出（交由 createAccount 標記帳號作廢）
     */
    private function isCreateStepSuccess(array $data, string $step): bool
    {
        try {
            $result = $this->sendRequest($data);
        } catch (Throwable $e) {
            Log::error("[Vsport][{$step}] request failed", [
                'username' => $data['username'],
                'error' => $e->getMessage(),
                'trace' => Common::formatTrace($e),
            ]);

            return false;
        }

        if (!is_array($result) || (string) ($result['errcode'] ?? '') !== '0') {
            Log::error("[Vsport][{$step}] failed", [
                'username' => $data['username'],
                'response' => $result,
            ]);

            return false;
        }

        return true;
    }
}
