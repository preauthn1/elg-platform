<?php

declare(strict_types=1);

namespace App\Vendors\Mt;

use App\Data\Vendor\CreateAccountParamsData;
use App\Data\Vendor\CreateAccountResultData;
use App\Data\VendorGameUrlParamsData;
use App\Data\VendorGameUrlResultData;
use App\Data\VendorTransferData;
use App\Enums\General\ContextKeyEnum;
use App\Enums\LanguageEnum;
use App\Enums\Vendor\VendorTransferResultEnum;
use App\Exceptions\ApiException;
use App\Helpers\Common;
use App\Models\Member;
use App\Vendors\AbstractTransferWallet;
use App\Vendors\Contracts\ShouldScheduleAccountCreation;
use App\Vendors\Models\MtAccount;
use App\Vendors\Models\MtBetRecord;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Spatie\LaravelData\Data;
use Throwable;

class Driver extends AbstractTransferWallet implements ShouldScheduleAccountCreation
{
    protected string $accountModel = MtAccount::class;

    protected string $betRecordModel = MtBetRecord::class;

    private VendorInfoDetail $infoDetail;

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
        $this->infoDetail = VendorInfoDetail::from($info);
        $this->infoDetail->MT_API_URL = rtrim($this->infoDetail->MT_API_URL, '/') . '/';
    }

    public function getGameUrl(VendorGameUrlParamsData $params): VendorGameUrlResultData
    {
        $rawData = json_encode([
            'gameCode' => $params->gameCode,
            'lang' => $this->mapLanguageCode($params->language),
            'roomID' => '',
        ]);
        $code = md5($this->infoDetail->MT_KEY . $rawData);
        $base64RawData = base64_encode($rawData);
        $pwd = [REDACTED], $this->vendorAccount->created_at->toDateTimeString()));
        $data = [
            'merchanId' => $this->infoDetail->MT_MERCHANID,
            'username' => $this->vendorAccount->username,
            'pwd' => $pwd,
            'rawData' => $base64RawData,
        ];
        $data['code'] = $code;

        $url = "playerPlatformUrl/{$this->infoDetail->MT_MERCHANID}/{$this->vendorAccount->username}/{$pwd}/{$code}/{$base64RawData}";
        $response = $this->apiCall('GetGameUrl', $url, $data);

        if (!is_array($response) || $response['resultCode'] !== '1') {
            throw new ApiException('[Mt][GetGameUrl] Invalid response: resultCode is not 1, response: ' . json_encode($response));
        }

        $gameUrl = $response['url'];

        return new VendorGameUrlResultData(
            pc: $gameUrl,
            mobile: $gameUrl,
        );
    }

    public function getBalance(): string
    {
        $rawData = json_encode([
            'currency' => $this->infoDetail->MT_CUR,
        ]);
        $base64RawData = base64_encode($rawData);
        $data = [
            'username' => $this->vendorAccount->username,
            'merchanId' => $this->infoDetail->MT_MERCHANID,
            'rawData' => $base64RawData,
        ];

        $url = "getPlayerBalance/{$this->vendorAccount->username}/{$this->infoDetail->MT_MERCHANID}/{$base64RawData}";
        $response = $this->apiCall('GetPlayerBalance', $url, $data);

        if (!is_array($response) || $response['resultCode'] !== '1') {
            throw new ApiException('[Mt][GetBalance] Invalid response: resultCode is not 1, response: ' . json_encode($response));
        }

        if (!isset($response['coinBalance'])) {
            throw new ApiException('[Mt][GetBalance] Invalid response: coinBalance is not set, response: ' . json_encode($response));
        }

        return Common::numFloor($response['coinBalance'], 4);
    }

    public function checkTransferStatus(string $transactionNo): VendorTransferResultEnum
    {
        $rawData = json_encode([
            'currency' => $this->infoDetail->MT_CUR,
        ]);
        $base64RawData = base64_encode($rawData);
        $data = [
            'playerName' => $this->vendorAccount->username,
            'merchantId' => $this->infoDetail->MT_MERCHANID,
            'extTransId' => $transactionNo,
            'rawData' => $base64RawData,
        ];

        $url = "queryTransbyId/{$this->vendorAccount->username}/{$this->infoDetail->MT_MERCHANID}/{$transactionNo}/{$base64RawData}";
        $response = $this->apiCall('CheckTransferStatus', $url, $data);

        $logData = [
            'request' => $data,
            'response' => $response,
        ];

        if (!is_array($response)) {
            Log::error('[Mt][CheckTransferStatus] Invalid response: response is not an array', $logData);

            return VendorTransferResultEnum::TIMEOUT;
        }

        if (!isset($response['resultCode'])) {
            Log::error('[Mt][CheckTransferStatus] Invalid response: resultCode is not set', $logData);

            return VendorTransferResultEnum::TIMEOUT;
        }

        if ($response['resultCode'] !== '1') {
            Log::error('[Mt][CheckTransferStatus] Invalid response: resultCode is not 1', $logData);

            return VendorTransferResultEnum::TIMEOUT;
        }

        if (!isset($response['status'])) {
            Log::error('[Mt][CheckTransferStatus] Invalid response: status is not set', $logData);

            return VendorTransferResultEnum::TIMEOUT;
        }

        return match ($response['status']) {
            '1' => VendorTransferResultEnum::SUCCESS,
            '2' => VendorTransferResultEnum::FAILED,
            default => VendorTransferResultEnum::TIMEOUT
        };
    }

    /**
     * 玩家餘額轉入遊戲商 (存款), origin: type = 1
     */
    public function transferToVendor(VendorTransferData $params): VendorTransferResultEnum
    {
        return $this->transfer($params, 'deposit2');
    }

    /**
     * 轉出遊戲商餘額回本地 (提款), origin: type != 1
     */
    public function transferToLocal(VendorTransferData $params): VendorTransferResultEnum
    {
        return $this->transfer($params, 'withdraw2');
    }

    private function transfer(VendorTransferData $params, string $endpoint)
    {
        $rawData = json_encode([
            'merchantId' => $this->infoDetail->MT_MERCHANID,
            'playerName' => $this->vendorAccount->username,
            'extTransId' => $params->transactionNo,
            'coins' => $params->vendorAmount,
            'currency' => $this->infoDetail->MT_CUR,
        ]);
        $code = md5($this->infoDetail->MT_KEY . $rawData);
        $base64RawData = base64_encode($rawData);
        $data = [
            'merchanId' => $this->infoDetail->MT_MERCHANID,
            'username' => $this->vendorAccount->username,
            'coins' => $params->vendorAmount,
            'extTransId' => $params->transactionNo,
            'rawData' => $base64RawData,
            'code' => $code,
        ];

        $url = "{$endpoint}/{$this->infoDetail->MT_MERCHANID}/{$this->vendorAccount->username}/{$params->vendorAmount}/{$params->transactionNo}/{$code}/{$base64RawData}";
        $response = $this->apiCall($endpoint, $url, $data);

        $logData = [
            'request' => $data,
            'response' => $response,
        ];

        if (!is_array($response)) {
            Log::error("[Mt][Transfer][{$endpoint}] Invalid response: response is not an array", $logData);

            return VendorTransferResultEnum::TIMEOUT;
        }

        if (!isset($response['resultCode'])) {
            Log::error("[Mt][Transfer][{$endpoint}] Invalid response: resultCode is not set", $logData);

            return VendorTransferResultEnum::TIMEOUT;
        }

        $resultCode = $response['resultCode'];

        return match ($resultCode) {
            '0' => VendorTransferResultEnum::FAILED,
            '1' => VendorTransferResultEnum::SUCCESS,
            '2' => VendorTransferResultEnum::FAILED,
            default => VendorTransferResultEnum::TIMEOUT
        };
    }

    private function apiCall(string $action, string $url, array $data)
    {
        $url = $this->infoDetail->MT_API_URL . $url;

        $response = Http::logAction("MT_{$action}")
            ->asJson()
            ->post($url, $data)
            ->throw()
            ->json();

        return $response;
    }

    private function mapLanguageCode(?LanguageEnum $language = null): string
    {
        if (empty($language)) {
            $languageId = Context::getHidden(ContextKeyEnum::LANGUAGE_ID->value);
        } else {
            $languageId = $language->value;
        }

        return match ($languageId) {
            LanguageEnum::ZH_TW->value => 'ZH-TW',
            LanguageEnum::ZH_CN->value => 'ZH-CN',
            default => 'EN-US',
        };
    }

    /**
     * 建立我方遊戲商帳號（遷自 RD-Studio create_mt_account）
     */
    public function createAccount(CreateAccountParamsData $params): CreateAccountResultData
    {
        $this->setVendorInfo($params->vendorInfo);
        $username = $params->username;

        // mt_accounts.password / admin 皆 NOT NULL 無預設
        $account = $this->accountModel::create([
            'vendor_info_id' => $params->vendorInfo->id,
            'username' => $username,
            'password' => '',
            'admin' => 1,
            'member_id' => -999999999,
        ]);

        if (!$account) {
            return CreateAccountResultData::from(['status' => false, 'username' => $username]);
        }

        // 必須與 getGameUrl 同式（getDefaultPassword），否則建出來的帳號登不進去
        $password = [REDACTED], $account->created_at->toDateTimeString());
        $account->update(['password' => $password]);

        if (!$this->createVendorAccount($username, $password)) {
            return CreateAccountResultData::from(['status' => false, 'username' => $username]);
        }

        $account->update(['member_id' => 0]);

        return CreateAccountResultData::from(['status' => true, 'username' => $username]);
    }

    /**
     * 建立三方遊戲商帳號（playerCreate2：參數同時放在 path 與 body）
     */
    private function createVendorAccount(string $username, string $password): bool
    {
        $merchantId = $this->infoDetail->MT_MERCHANID;
        $pwd = [REDACTED];
        $rawData = json_encode(['nickname' => '', 'playerLevel' => '0']);
        $code = md5($this->infoDetail->MT_KEY . $rawData);
        $base64RawData = base64_encode((string) $rawData);

        $data = [
            'username' => $username,
            'merchanId' => $merchantId,
            'pwd' => $pwd,
            'code' => $code,
            'data' => $base64RawData,
        ];

        $url = "playerCreate2/{$username}/{$merchantId}/{$pwd}/{$code}/{$base64RawData}";

        try {
            $response = $this->apiCall('CreateAccount', $url, $data);
        } catch (Throwable $e) {
            Log::error('[Mt][CreateAccount] request failed', [
                'username' => $username,
                'error' => $e->getMessage(),
                'trace' => Common::formatTrace($e),
            ]);

            return false;
        }

        if (!is_array($response) || ($response['resultCode'] ?? null) !== '1') {
            Log::error('[Mt][CreateAccount] Invalid response: resultCode is not 1', [
                'username' => $username,
                'response' => $response,
            ]);

            return false;
        }

        return true;
    }
}

class VendorInfoDetail extends Data
{
    public function __construct(
        public string $MT_API_URL,
        public string $MT_MERCHANID,
        public string $MT_KEY,
        public string $MT_CUR
    ) {}
}
