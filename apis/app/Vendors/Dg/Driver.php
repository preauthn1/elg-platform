<?php

declare(strict_types=1);

namespace App\Vendors\Dg;

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
use App\Vendors\Models\DgAccount;
use App\Vendors\Models\DgBetRecord;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Spatie\LaravelData\Data;

class Driver extends AbstractTransferWallet implements ShouldScheduleAccountCreation
{
    protected string $accountModel = DgAccount::class;

    protected string $betRecordModel = DgBetRecord::class;

    private VendorInfoDetailData $infoDetail;

    private string $actionPrefix = 'DG_';

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
        $this->infoDetail = VendorInfoDetailData::from($info);
    }

    /**
     * 建立我方遊戲商帳號
     */
    public function createAccount(CreateAccountParamsData $params): CreateAccountResultData
    {
        // setVendorInfo 同時設定 $this->vendorInfo（無 member 建構時未初始化）並載入 info
        $this->setVendorInfo($params->vendorInfo);
        $username = $params->username;

        // dg_accounts 無 password 欄；三方密碼固定 md5(username)，與 getGameUrl 一致
        $account = $this->accountModel::create([
            'vendor_info_id' => $params->vendorInfo->id,
            'username' => $username,
            'member_id' => -999999999,
        ]);

        if (!$account) {
            return CreateAccountResultData::from([
                'status' => false,
                'username' => $username,
            ]);
        }

        // 呼叫三方 API 建立遊戲商帳號
        if (!$this->createVendorAccount($account)) {
            return CreateAccountResultData::from([
                'status' => false,
                'username' => $username,
            ]);
        }

        $account->update(['member_id' => 0]);

        return CreateAccountResultData::from([
            'status' => true,
            'username' => $username,
        ]);
    }

    /**
     * 建立三方遊戲商帳號
     */
    protected function createVendorAccount($account): bool
    {
        $info = $this->infoDetail;
        $username = $account->username;

        $data = [
            'username' => $username,
            'password' => md5($username),
            'currencyName' => $info->CURRENCY_NAME,
            'winLimit' => 0,
        ];
        $action = $this->actionPrefix . 'createAccount';

        $result = $this->sendRequest($action, '/v2/api/signup', $data);

        if (!is_array($result)) {
            return false;
        }

        if ((string) $result['codeId'] === '0') {
            return true;
        }

        return false;
    }

    public function getGameUrl(VendorGameUrlParamsData $params): VendorGameUrlResultData
    {
        $username = $this->vendorAccount->username;

        $data = [
            'username' => $username,
            'password' => md5($username),
            'currencyName' => $this->infoDetail->CURRENCY_NAME,
            'winLimit' => 0,
            'language' => $this->mapLanguageCode(),
        ];

        if ($this->infoDetail->TABLE_LIMIT) {
            $data['limitGroup'] = $this->infoDetail->TABLE_LIMIT;
        }

        if ($this->infoDetail->LOGIN_MIN_AMOUNT > 0 && $this->infoDetail->LOGIN_MAX_AMOUNT > 0) {
            $data['limits'][] = [
                'min' => $this->infoDetail->LOGIN_MIN_AMOUNT,
                'max' => $this->infoDetail->LOGIN_MAX_AMOUNT,
            ];
        }

        $action = $this->actionPrefix . 'getGameUrl';
        $result = $this->sendRequest($action, '/v2/api/login', $data);

        if (!is_array($result)) {
            throw new ApiException('[Dg][GetGameUrl] API error: response is not array.');
        }

        if (!isset($result['codeId']) || $result['codeId'] !== 0) {
            throw new ApiException('[Dg][GetGameUrl] API error: codeId is not 0.');
        }

        if (!isset($result['list']) || !isset($result['token'])) {
            throw new ApiException('[Dg][GetGameUrl] API error: list or token is not set.');
        }

        $gameUrl = $result['list'][0];

        return VendorGameUrlResultData::from([
            'pc' => $gameUrl,
            'mobile' => $gameUrl,
        ]);
    }

    public function getBalance(): string
    {
        $postData = [
            'username' => $this->vendorAccount->username,
        ];

        $action = $this->actionPrefix . 'getBalance';
        $result = $this->sendRequest($action, '/v2/api/balance', $postData);

        if (!is_array($result)) {
            throw new ApiException('[Dg][GetBalance] API error: response is not array.');
        }

        if (!isset($result['codeId']) || $result['codeId'] !== 0) {
            throw new ApiException('[Dg][GetBalance] API error: codeId is not 0.');
        }

        if (!isset($result['balance'])) {
            throw new ApiException('[Dg][GetBalance] API error: response data is not correct.');
        }

        return Common::numFloor($result['balance'], 4);
    }

    /**
     * 玩家餘額轉入遊戲商 (存款), origin: type = 1
     */
    public function transferToVendor(VendorTransferData $params): VendorTransferResultEnum
    {
        // 將金額保留兩位小數
        $amount = Common::numFloor($params->vendorAmount, 2);

        $data = [
            'username' => $this->vendorAccount->username,
            'amount' => (float) $amount,
            'serial' => $params->transactionNo,
        ];

        return $this->transfer($data);
    }

    /**
     * 轉出遊戲商餘額回本地 (提款), origin: type != 1
     */
    public function transferToLocal(VendorTransferData $params): VendorTransferResultEnum
    {
        // 將金額轉為負數並保留兩位小數
        $amount = Common::numFloor(Common::numMul($params->vendorAmount, -1), 2);

        $data = [
            'username' => $this->vendorAccount->username,
            'amount' => (float) $amount,
            'serial' => $params->transactionNo,
        ];

        return $this->transfer($data);
    }

    /**
     * https://example.invalid/doc.html?language=zh-CN#/V2/%E8%BD%AC%E8%B4%A6%E6%A8%A1%E5%BC%8F%E6%8E%A5%E5%8F%A3/transfer
     */
    private function transfer(array $data): VendorTransferResultEnum
    {
        $action = (Common::numCompare($data['amount'], 0) > 0) ? 'transferToVendor' : 'transferToLocal';

        $action = $this->actionPrefix . $action;
        $result = $this->sendRequest($action, '/v2/api/transfer', $data);

        if (!is_array($result)) {
            return VendorTransferResultEnum::TIMEOUT;
        }

        if (!isset($result['codeId'])) {
            return VendorTransferResultEnum::TIMEOUT;
        }

        if ((string) $result['codeId'] === '0') {
            return VendorTransferResultEnum::SUCCESS;
        }

        if ((string) $result['codeId'] === '324') {
            return VendorTransferResultEnum::FAILED;
        }

        return VendorTransferResultEnum::TIMEOUT;
    }

    /**
     * https://example.invalid/doc.html?language=zh-CN#/V2/%E8%BD%AC%E8%B4%A6%E6%A8%A1%E5%BC%8F%E6%8E%A5%E5%8F%A3/checkTransfer
     */
    public function checkTransferStatus(string $transactionNo): VendorTransferResultEnum
    {
        $data = [
            'serial' => $transactionNo,
        ];

        $action = $this->actionPrefix . 'checkTransferStatus';
        $result = $this->sendRequest($action, '/v2/api/checkTransfer', $data);

        $logData = [
            'request' => $data,
            'response' => $result,
        ];

        if (!is_array($result)) {
            Log::error('[Dg][CheckTransferStatus] API error: response data is not array.', $logData);

            return VendorTransferResultEnum::TIMEOUT;
        }

        if (!isset($result['codeId'])) {
            Log::error('[Dg][CheckTransferStatus] API error: response data is not correct.', $logData);

            return VendorTransferResultEnum::TIMEOUT;
        }

        if ((string) $result['codeId'] === '0') {
            return VendorTransferResultEnum::SUCCESS;
        } elseif ((string) $result['codeId'] === '324') {
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
            LanguageEnum::ZH_TW->value => 'tw',
            LanguageEnum::ZH_CN->value => 'cn',
            LanguageEnum::VI_VN->value => 'vi',
            default => 'en',
        };
    }

    private function sendRequest(string $action, string $endpoint, array $data)
    {
        $info = $this->infoDetail;

        $apiUrl = rtrim($info->API_URL, '/');

        $url = $apiUrl . $endpoint;

        $timestamp = (int) Carbon::now()->getPreciseTimestamp(3);

        $headers = [
            'agent' => $info->AGENT_NAME,
            'sign' => md5($info->AGENT_NAME . $info->API_KEY . $timestamp),
            'time' => $timestamp,
        ];

        $result = Http::logAction($action)
            ->asJson()
            ->withHeaders($headers)
            ->post($url, $data)
            ->throw()
            ->json();

        return $result;
    }
}

class VendorInfoDetailData extends Data
{
    public function __construct(
        public string $API_URL,
        public string $AGENT_NAME,
        public string $API_KEY,
        public string $CURRENCY_NAME,

        public ?string $TABLE_LIMIT = '',
        public ?int $LOGIN_MIN_AMOUNT = 0,
        public ?int $LOGIN_MAX_AMOUNT = 0,
    ) {
        $this->API_URL = rtrim($this->API_URL, '/');
    }
}
