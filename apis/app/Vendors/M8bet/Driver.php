<?php

declare(strict_types=1);

namespace App\Vendors\M8bet;

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
use App\Vendors\Models\M8betAccount;
use App\Vendors\Models\M8betBetRecord;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Spatie\LaravelData\Data;
use Throwable;

class Driver extends AbstractTransferWallet implements ShouldScheduleAccountCreation
{
    protected string $accountModel = M8betAccount::class;

    protected string $betRecordModel = M8betBetRecord::class;

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
        $data = [
            'secret' => $this->infoDetail->CLIENT_SECRET,
            'action' => 'login',
            'agent' => $this->infoDetail->CLIENT_ID,
            'username' => $this->vendorAccount->username,
            'lang' => $this->mapLanguageCode($params->language),
            'accType' => 'MY', // 馬來西亞
            'ref' => '',
        ];
        $url = "{$this->infoDetail->AUTH_URL}/apijs.aspx?" . http_build_query($data);
        $action = 'GetGameUrl';

        $response = Http::logAction("M8bet_{$action}")
            ->post($url)
            ->throw()
            ->json();

        if (!is_array($response)) {
            throw new ApiException("[M8bet][{$action}] API error: bad response");
        }

        if (!isset($response['errcode']) || !isset($response['result'])) {
            throw new ApiException("[M8bet][{$action}] API error: data missing");
        }

        if ((string) $response['errcode'] !== '0') {
            throw new ApiException("[M8bet][{$action}] API error: " . $response['errtext'] ?? 'unknown error');
        }

        $result = $response['result'];

        return VendorGameUrlResultData::from([
            'pc' => $result['login']['weburl'],
            'mobile' => $result['login']['mobiurl'],
        ]);
    }

    public function getBalance(): string
    {
        $data = [
            'secret' => $this->infoDetail->CLIENT_SECRET,
            'action' => 'balance',
            'agent' => $this->infoDetail->CLIENT_ID,
            'username' => $this->vendorAccount->username,
        ];
        $url = "{$this->infoDetail->AUTH_URL}/apijs.aspx?" . http_build_query($data);
        $action = 'GetBalance';

        $response = Http::logAction("M8bet_{$action}")
            ->timeout(60)
            ->post($url)
            ->throw()
            ->json();

        if (!is_array($response)) {
            throw new ApiException("[M8bet][{$action}] API error: bad response");
        }

        if (!isset($response['errcode']) || !isset($response['result'])) {
            throw new ApiException("[M8bet][{$action}] API error: data missing");
        }

        if ((string) $response['errcode'] !== '0') {
            throw new ApiException("[M8bet][{$action}] API error: " . $response['errtext'] ?? 'unknown error');
        }

        return Common::numFloor($response['result']);
    }

    public function checkTransferStatus(string $transactionNo): VendorTransferResultEnum
    {
        $data = [
            'action' => 'check_payment',
            'secret' => $this->infoDetail->CLIENT_SECRET,
            'agent' => $this->infoDetail->CLIENT_ID,
            'username' => $this->vendorAccount->username,
            'serial' => $transactionNo,
        ];
        $url = "{$this->infoDetail->AUTH_URL}/apijs.aspx?" . http_build_query($data);

        $response = Http::logAction('M8bet_CheckTransferStatus')
            ->post($url)
            ->throw()
            ->json();

        $logData = [
            'request' => $data,
            'response' => $response,
        ];

        if (!is_array($response)) {
            Log::error('[M8bet][CheckTransferStatus] API error: bad response', $logData);

            return VendorTransferResultEnum::TIMEOUT;
        }

        if (!isset($response['errcode']) || !isset($response['result'])) {
            Log::error('[M8bet][CheckTransferStatus] API error: data missing', $logData);

            return VendorTransferResultEnum::TIMEOUT;
        }

        if ((string) $response['errcode'] !== '0') {
            Log::error('[M8bet][CheckTransferStatus] API error: ' . $response['errtext'] ?? 'unknown error', $logData);

            return VendorTransferResultEnum::FAILED;
        }

        return VendorTransferResultEnum::SUCCESS;
    }

    /**
     * 玩家餘額轉入遊戲商 (存款), origin: type = 1
     */
    public function transferToVendor(VendorTransferData $params): VendorTransferResultEnum
    {
        return $this->transfer($params, 'deposit');
    }

    /**
     * 轉出遊戲商餘額回本地 (提款), origin: type != 1
     */
    public function transferToLocal(VendorTransferData $params): VendorTransferResultEnum
    {
        return $this->transfer($params, 'withdraw');
    }

    private function transfer(VendorTransferData $params, string $action): VendorTransferResultEnum
    {
        $data = [
            'secret' => $this->infoDetail->CLIENT_SECRET,
            'action' => $action,
            'agent' => $this->infoDetail->CLIENT_ID,
            'username' => $this->vendorAccount->username,
            'serial' => $params->transactionNo,
            'amount' => $params->vendorAmount,
        ];
        $url = "{$this->infoDetail->AUTH_URL}/apijs.aspx?" . http_build_query($data);

        $response = Http::logAction("M8bet_{$action}")
            ->post($url)
            ->throw()
            ->json();

        $logData = [
            'request' => $data,
            'response' => $response,
        ];

        if (!is_array($response)) {
            Log::error("[M8bet][{$action}] API error: bad response", $logData);

            return VendorTransferResultEnum::TIMEOUT;
        }

        if (!isset($response['errcode']) || !isset($response['result'])) {
            Log::error("[M8bet][{$action}] API error: data missing", $logData);

            return VendorTransferResultEnum::TIMEOUT;
        }

        $errcode = (string) $response['errcode'];

        // Request response with error code 0 mean request successful, other error code mean request failed
        if ($errcode === '0') {
            return VendorTransferResultEnum::SUCCESS;
        }

        Log::error("[M8bet][{$action}] API error: " . $response['errtext'] ?? 'unknown error', $logData);

        return VendorTransferResultEnum::FAILED;
    }

    private function mapLanguageCode(?LanguageEnum $language = null): string
    {
        if (empty($language)) {
            $languageId = Context::getHidden(ContextKeyEnum::LANGUAGE_ID->value);
        } else {
            $languageId = $language->value;
        }

        return match ($languageId) {
            LanguageEnum::ZH_TW->value => 'ZH-CN',
            LanguageEnum::ZH_CN->value => 'ZH-CN',
            LanguageEnum::ID_ID->value => 'ID-ID',
            LanguageEnum::VI_VN->value => 'VI-VN',
            LanguageEnum::TH_TH->value => 'TH-TH',
            default => 'EN-US',
        };
    }

    /**
     * 建立我方遊戲商帳號（遷自 RD-Studio create_m8bet_account）
     *
     * M8bet 無密碼機制（action=login 只帶 username）；
     * password／session_token／vendor_uid 皆不使用。
     */
    public function createAccount(CreateAccountParamsData $params): CreateAccountResultData
    {
        $this->setVendorInfo($params->vendorInfo);
        $username = $params->username;

        $account = $this->accountModel::create([
            'vendor_info_id' => $params->vendorInfo->id,
            'username' => $username,
            // 注單以 vendor_username 對帳，必寫
            'vendor_username' => $this->infoDetail->CLIENT_CLASS . $username,
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
     * 注意：建號走 /api.aspx 且回應是 XML，與本 driver 其他方法的 /apijs.aspx（JSON）不同，
     * 送出的 username 是 raw username（不含 CLIENT_CLASS），皆比照舊系統。
     */
    private function createVendorAccount(string $username): bool
    {
        $action = 'CreateAccount';
        $query = [
            'secret' => $this->infoDetail->CLIENT_SECRET,
            'agent' => $this->infoDetail->CLIENT_ID,
            'username' => $username,
            'action' => 'create',
        ];
        $url = "{$this->infoDetail->AUTH_URL}/api.aspx?" . http_build_query($query);

        try {
            $body = Http::logAction("M8bet_{$action}")
                ->post($url)
                ->throw()
                ->body();
        } catch (Throwable $e) {
            Log::error("[M8bet][{$action}] API error: request failed", [
                'username' => $username,
                'error' => $e->getMessage(),
                'trace' => Common::formatTrace($e),
            ]);

            return false;
        }

        $xml = simplexml_load_string($body, 'SimpleXMLElement', LIBXML_NOCDATA);

        if ($xml === false) {
            Log::error("[M8bet][{$action}] API error: bad xml response", ['username' => $username, 'body' => $body]);

            return false;
        }

        $result = json_decode((string) json_encode($xml), true);

        if (!is_array($result) || !isset($result['errcode'])) {
            Log::error("[M8bet][{$action}] API error: errcode missing", ['username' => $username, 'response' => $result]);

            return false;
        }

        if ((string) $result['errcode'] === '0') {
            return true;
        }

        Log::error("[M8bet][{$action}] API error: errcode is not 0", [
            'username' => $username,
            'response' => $result,
        ]);

        return false;
    }
}

class VendorInfoDetail extends Data
{
    public function __construct(
        public string $AUTH_URL,
        public string $CLIENT_ID,
        public string $CLIENT_SECRET,
        public string $CLIENT_CLASS,
        public string $CURRENCY,
    ) {
        $this->AUTH_URL = trim($this->AUTH_URL, '/');
    }
}
