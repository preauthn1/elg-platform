<?php

declare(strict_types=1);

namespace App\Vendors\Bolakk4d;

use App\Data\Vendor\CreateAccountParamsData;
use App\Data\Vendor\CreateAccountResultData;
use App\Data\VendorGameUrlParamsData;
use App\Data\VendorGameUrlResultData;
use App\Data\VendorTransferData;
use App\Enums\General\ContextKeyEnum;
use App\Enums\General\DeviceTypeEnum;
use App\Enums\LanguageEnum;
use App\Enums\Vendor\VendorTransferResultEnum;
use App\Exceptions\ApiException;
use App\Helpers\Common;
use App\Models\Member;
use App\Vendors\AbstractTransferWallet;
use App\Vendors\Contracts\ShouldScheduleAccountCreation;
use App\Vendors\Models\Bolakk4dAccount;
use App\Vendors\Models\Bolakk4dBetRecord;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use SimpleXMLElement;
use Spatie\LaravelData\Data;
use Throwable;

class Driver extends AbstractTransferWallet implements ShouldScheduleAccountCreation
{
    protected string $accountModel = Bolakk4dAccount::class;

    protected string $betRecordModel = Bolakk4dBetRecord::class;

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
    }

    public function getGameUrl(VendorGameUrlParamsData $params): VendorGameUrlResultData
    {
        $action = 'GetGameUrl';

        $data = [
            'apiUser' => $this->infoDetail->CLIENT_ID,
            'apiPass' => $this->infoDetail->CLIENT_SECRET,
            'user' => $this->vendorAccount->username,
            'pass' => $this->getDefaultPassword($this->vendorAccount->username, $this->vendorAccount->created_at->toDateTimeString()),
        ];

        $xml = $this->apiCall($action, 'betLogin.aspx', $data);

        if (!is_object($xml) || !isset($xml->sessionID, $xml->tokenCode)) {
            throw new ApiException("[Bolakk4d][{$action}] API error: response data is not correct.");
        }

        $urlParams = http_build_query([
            'sessionID' => (string) $xml->sessionID,
            'tokenCode' => (string) $xml->tokenCode,
            'lang' => $this->mapLanguageCode($params->language),
        ]);

        if ($params->deviceType === DeviceTypeEnum::MOBILE) {
            $gameUrl = "{$this->infoDetail->AUTH_WAP_URL}/auth?{$urlParams}";
        } else {
            $gameUrl = "{$this->infoDetail->AUTH_URL}/api/web-bet?{$urlParams}";
        }

        return new VendorGameUrlResultData(
            pc: $gameUrl,
            mobile: $gameUrl,
        );
    }

    public function getBalance(): string
    {
        $action = 'GetBalance';

        $data = [
            'apiUser' => $this->infoDetail->CLIENT_ID,
            'apiPass' => $this->infoDetail->CLIENT_SECRET,
            'user' => $this->infoDetail->CLIENT_AGENT,
            'pass' => $this->infoDetail->CLIENT_AGENT_PASS,
            'loginID' => $this->vendorAccount->username,
        ];

        $xml = $this->apiCall($action, 'getProfile.aspx', $data);

        if (!is_object($xml) || !isset($xml->errorCode, $xml->balance)) {
            throw new ApiException("[Bolakk4d][{$action}] API error: response data is not correct.");
        }

        if ((string) $xml->errorCode !== '0') {
            throw new ApiException("[Bolakk4d][{$action}] API error: {$xml->errorCode} - {$xml->errorMessage}");
        }

        return Common::numFloor((string) $xml->balance, 4);
    }

    /**
     * 玩家餘額轉入遊戲商 (存款), origin: type = 1
     */
    public function transferToVendor(VendorTransferData $params): VendorTransferResultEnum
    {
        return $this->transfer($params, 'deposit.aspx', 'TransferToVendor');
    }

    /**
     * 轉出遊戲商餘額回本地 (提款), origin: type != 1
     */
    public function transferToLocal(VendorTransferData $params): VendorTransferResultEnum
    {
        return $this->transfer($params, 'withdraw.aspx', 'TransferToLocal');
    }

    private function transfer(VendorTransferData $params, string $endPoint, string $action): VendorTransferResultEnum
    {
        $data = [
            'apiUser' => $this->infoDetail->CLIENT_ID,
            'apiPass' => $this->infoDetail->CLIENT_SECRET,
            'user' => $this->infoDetail->CLIENT_AGENT,
            'pass' => $this->infoDetail->CLIENT_AGENT_PASS,
            'loginID' => $this->vendorAccount->username,
            'amount' => $params->vendorAmount,
            'amountm' => 0,
        ];

        $xml = $this->apiCall($action, $endPoint, $data);

        if (!is_object($xml) || !isset($xml->errorCode)) {
            Log::error("[Bolakk4d][{$action}] API error: response data is not correct.");

            return VendorTransferResultEnum::TIMEOUT;
        }

        $errorCode = (string) $xml->errorCode;
        if ($errorCode === '0') {
            return VendorTransferResultEnum::SUCCESS;
        }

        if (in_array($errorCode, [
            '1',      // 錯誤API登入名或密碼
            '2',      // 錯誤⽤⼾登入名或密碼
            '3',      // 輸入參數不⿑全
            '4',  	  // 錯誤下線⼾⼝
            '5',  	  // 提現數額超出
            '999',    // 系統錯誤信息，請聯絡管理員
        ], true)) {
            Log::error("[Bolakk4d][{$action}] API error: {$errorCode} - {$xml->errorMessage}");

            return VendorTransferResultEnum::FAILED;
        }

        Log::error("[Bolakk4d][{$action}] API unknown error: {$errorCode} - {$xml->errorMessage}");

        return VendorTransferResultEnum::TIMEOUT;
    }

    private function apiCall(string $action, string $endPoint, array $data): bool|SimpleXMLElement
    {
        $url = "{$this->infoDetail->AUTH_URL}/api/{$endPoint}";

        $response = Http::logAction("Bolakk4d_{$action}")
            ->asJson()
            ->post($url, $data)
            ->throw()
            ->body();

        return simplexml_load_string($response, 'SimpleXMLElement', LIBXML_NOCDATA);
    }

    private function mapLanguageCode(?LanguageEnum $language = null): string
    {
        if (empty($language)) {
            $languageId = Context::getHidden(ContextKeyEnum::LANGUAGE_ID->value);
        } else {
            $languageId = $language->value;
        }

        return match ($languageId) {
            LanguageEnum::ZH_TW->value => 'zh-cn',
            LanguageEnum::ZH_CN->value => 'zh-cn',
            default => 'en-us',
        };
    }

    /**
     * 建立我方遊戲商帳號（遷自 RD-Studio create_bolakk4d_account）
     */
    public function createAccount(CreateAccountParamsData $params): CreateAccountResultData
    {
        $this->setVendorInfo($params->vendorInfo);
        $username = $params->username;

        $account = $this->accountModel::create([
            'vendor_info_id' => $params->vendorInfo->id,
            'username' => $username,
            // vendor_username 是注單 SP 的 JOIN 鍵：CLIENT_CLASS 前綴 + 帳號
            'vendor_username' => $this->infoDetail->CLIENT_CLASS . $username,
            'admin' => 1,
            'member_id' => -999999999,
        ]);

        if (!$account) {
            return CreateAccountResultData::from(['status' => false, 'username' => $username]);
        }

        if (!$this->createVendorAccount($account)) {
            return CreateAccountResultData::from(['status' => false, 'username' => $username]);
        }

        $account->update(['member_id' => 0]);

        return CreateAccountResultData::from(['status' => true, 'username' => $username]);
    }

    /**
     * 建立三方遊戲商帳號
     *
     * loginPass 必須與 getGameUrl 的 getDefaultPassword(username, created_at) 完全同式，
     * 否則建出來的帳號無法登入；故一定要 create 之後才組請求。
     */
    private function createVendorAccount(Bolakk4dAccount $account): bool
    {
        $action = 'CreatePlayer';

        $data = [
            'apiUser' => $this->infoDetail->CLIENT_ID,
            'apiPass' => $this->infoDetail->CLIENT_SECRET,
            'user' => $this->infoDetail->CLIENT_AGENT,
            'pass' => $this->infoDetail->CLIENT_AGENT_PASS,
            'loginID' => $account->username,
            'fullName' => $account->username,
            'loginPass' => $this->getDefaultPassword($account->username, $account->created_at->toDateTimeString()),
        ];

        try {
            $xml = $this->apiCall($action, 'createPlayer.aspx', $data);
        } catch (Throwable $e) {
            Log::error("[Bolakk4d][{$action}] API error", [
                'username' => $account->username,
                'error' => $e->getMessage(),
                'trace' => Common::formatTrace($e),
            ]);

            return false;
        }

        if (!is_object($xml) || !isset($xml->errorCode)) {
            Log::error("[Bolakk4d][{$action}] API error: response data is not correct", ['username' => $account->username]);

            return false;
        }

        if ((string) $xml->errorCode !== '0') {
            Log::error("[Bolakk4d][{$action}] API error: {$xml->errorCode} - " . ($xml->errorMessage ?? ''), ['username' => $account->username]);

            return false;
        }

        return true;
    }
}

class VendorInfoDetail extends Data
{
    public function __construct(
        public string $AUTH_URL,
        public string $AUTH_WAP_URL,
        public string $CLIENT_ID,
        public string $CLIENT_SECRET,
        public string $CLIENT_AGENT,
        public string $CLIENT_AGENT_PASS,
        public string $CLIENT_CLASS,
        public string $CURRENCY,
    ) {
        $this->AUTH_URL = trim($this->AUTH_URL, '/');
        $this->AUTH_WAP_URL = trim($this->AUTH_WAP_URL, '/');
    }
}
