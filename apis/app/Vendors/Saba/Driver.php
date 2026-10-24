<?php

declare(strict_types=1);

namespace App\Vendors\Saba;

use App\Data\Vendor\CreateAccountParamsData;
use App\Data\Vendor\CreateAccountResultData;
use App\Data\VendorGameUrlParamsData;
use App\Data\VendorGameUrlResultData;
use App\Data\VendorTransferData;
use App\Enums\General\DeviceTypeEnum;
use App\Enums\LanguageEnum;
use App\Enums\Vendor\VendorTransferResultEnum;
use App\Exceptions\ApiException;
use App\Helpers\Common;
use App\Models\Member;
use App\Vendors\AbstractTransferWallet;
use App\Vendors\Contracts\ShouldScheduleAccountCreation;
use App\Vendors\Contracts\WidgetGameInterface;
use App\Vendors\Models\SabaAccount;
use App\Vendors\Models\SabaBetRecord;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class Driver extends AbstractTransferWallet implements ShouldScheduleAccountCreation, WidgetGameInterface
{
    protected string $accountModel = SabaAccount::class;

    protected string $betRecordModel = SabaBetRecord::class;

    protected string $actionTitle = 'SABA';

    protected string $apiUrl;

    protected string $apiVendorId;

    protected string $apiOperatorId;

    protected int $walletId;

    protected int $currency;

    protected string $oddsType;

    protected string $widgetId;

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
        $this->apiVendorId = $info['VENDOR_ID'];
        $this->apiOperatorId = $info['OPERATOR_ID'];
        $this->walletId = (int) $info['WALLET_ID'];
        $this->currency = (int) $info['CURRENCY'];
        $this->oddsType = (string) ($info['ODDSTYPE'] ?? '1');
        $this->widgetId = (string) ($info['WIDGET_ID'] ?? '00000000');
    }

    // =========================================================================
    // Private API Calls
    // =========================================================================

    /**
     * 統一驗證 API 回應格式及 error_code，失敗直接拋出 ApiException
     */
    private function validateResponse(?array $json): void
    {
        if (!$json) {
            throw new ApiException('Third party error: not json response');
        }

        if (!isset($json['error_code']) || !is_numeric($json['error_code'])) {
            throw new ApiException('Third party error: format error');
        }

        if ((string) $json['error_code'] !== '0') {
            $code = $json['error_code'];
            $msg = $json['message'] ?? '';
            throw new ApiException("Third party error: ({$code}) {$msg}");
        }
    }

    /**
     * 統一發送 POST 請求至 SABA API
     */
    private function callApi(string $action, string $endpoint, array $params, int $timeout = 60): ?array
    {
        return Http::logAction($action)
            ->timeout($timeout)
            ->asForm()
            ->post($this->apiUrl . $endpoint, $params)
            ->throw()
            ->json();
    }

    // =========================================================================
    // Public / Protected Business Logic
    // =========================================================================

    public function getGameUrl(VendorGameUrlParamsData $params): VendorGameUrlResultData
    {
        $language = $this->getLanguage($params->language->value);
        $platform = $params->deviceType === DeviceTypeEnum::MOBILE ? 2 : 1;

        $json = $this->callApi(
            action: $this->actionTitle . '_api_call_getGameUrl',
            endpoint: '/api/GetSabaUrl',
            params: [
                'vendor_id' => $this->apiVendorId,
                'platform' => $platform,
                'vendor_member_id' => $this->vendorAccount->username_modified,
            ],
        );

        $this->validateResponse($json);

        if (!isset($json['Data'])) {
            throw new ApiException('Third party error: format error');
        }

        $url = $json['Data'] . '&lang=' . $language;

        return VendorGameUrlResultData::from(['pc' => $url, 'mobile' => $url]);
    }

    public function getWidgetUrl(VendorGameUrlParamsData $params): VendorGameUrlResultData
    {
        $language = $this->getLanguage($params->language->value);

        $json = $this->callApi(
            action: $this->actionTitle . '_api_call_getWidgetUrl',
            endpoint: '/api/GetWidgetUrl',
            params: [
                'vendor_id' => $this->apiVendorId,
                'vendor_member_id' => $this->vendorAccount->username_modified,
                'language' => $language,
                'widgetId' => $this->widgetId,
                'domain' => $params->exitUrl,
                'odds_type' => $this->oddsType,
            ],
            timeout: 60,
        );

        $this->validateResponse($json);

        if (!isset($json['Data'])) {
            throw new ApiException('Third party error: format error');
        }

        return VendorGameUrlResultData::from(['pc' => $json['Data'], 'mobile' => $json['Data']]);
    }

    /**
     * 取得餘額
     */
    public function getBalance(): string
    {
        $json = $this->callApi(
            action: $this->actionTitle . '_Balance',
            endpoint: '/api/CheckUserBalance',
            params: [
                'vendor_id' => $this->apiVendorId,
                'vendor_member_ids' => $this->vendorAccount->username_modified,
                'wallet_id' => $this->walletId,
            ],
        );

        $this->validateResponse($json);

        return (string) ($json['Data'][0]['balance'] ?? 0);
    }

    /**
     * 轉帳
     */
    protected function transfer(string $username, int $type, string $credit, string $billNo): VendorTransferResultEnum
    {
        $direction = $type === 1 ? 1 : 0;

        $json = $this->callApi(
            action: $this->actionTitle . ($direction === 1 ? '_Deposit' : '_Withdrawal'),
            endpoint: '/api/FundTransfer',
            params: [
                'vendor_id' => $this->apiVendorId,
                'vendor_member_id' => $username,
                'vendor_trans_id' => $this->apiOperatorId . '_' . $billNo,
                'amount' => Common::numFloor($credit, 2),
                'currency' => $this->currency,
                'direction' => $direction,
                'wallet_id' => $this->walletId,
            ],
        );

        if (!$json) {
            return VendorTransferResultEnum::TIMEOUT;
        }

        if (
            !isset($json['error_code']) || !is_numeric($json['error_code'])
            || !isset($json['Data']['status']) || !is_numeric($json['Data']['status'])
        ) {
            throw new ApiException('Third party error: format error');
        }

        // ✦error code=0 代表成功
        // ✦error code=1 需确认 status code
        // status code=1 代表系统错误，转账失败
        // status code=2 代表状态未知，请 10 秒后呼叫 API"CheckFundTransfer"方法确认状态
        // ✦error code=2~16 转账失败，请参考下方错误讯息表格
        $errorCode = (string) $json['error_code'];
        $statusCode = (string) $json['Data']['status'];

        return match (true) {
            $errorCode === '0' => VendorTransferResultEnum::SUCCESS,
            $errorCode === '1' && $statusCode === '1' => VendorTransferResultEnum::FAILED,
            $errorCode === '1' && $statusCode === '2' => VendorTransferResultEnum::TIMEOUT,
            default => VendorTransferResultEnum::FAILED,
        };
    }

    /**
     * 玩家餘額轉入遊戲商 (存款)
     */
    public function transferToVendor(VendorTransferData $params): VendorTransferResultEnum
    {
        return $this->transfer(
            username: $this->vendorAccount->username_modified,
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
            username: $this->vendorAccount->username_modified,
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
        $json = $this->callApi(
            action: $this->actionTitle . '_checkTransferStatus',
            endpoint: '/api/CheckFundTransfer',
            params: [
                'vendor_id' => $this->apiVendorId,
                'vendor_trans_id' => $this->apiOperatorId . '_' . $transactionNo,
                'wallet_id' => $this->walletId,
            ],
        );

        if (!$json) {
            return VendorTransferResultEnum::TIMEOUT;
        }

        if (
            !isset($json['error_code']) || !is_numeric($json['error_code'])
            || !isset($json['Data']['status']) || !is_numeric($json['Data']['status'])
        ) {
            throw new ApiException('Third party error: format error');
        }

        $errorCode = (string) $json['error_code'];
        $statusCode = (string) $json['Data']['status'];

        // ✦error code=0 代表成功
        // status code=0 代表转账成功
        // status code=1 代表转账失败
        // ✦error code=1
        // status code=2 请再次调用 CheckFundTransfer
        // ✦error code=2
        // 没有回传 status code, 代表无此转账，请重新做转账
        // ✦error code=3
        // status code=2 代表请于 message 的秒数后再做 CheckFundTransfer
        return match (true) {
            $errorCode === '0' && $statusCode === '0' => VendorTransferResultEnum::SUCCESS,
            $errorCode === '0' && $statusCode === '1' => VendorTransferResultEnum::FAILED,
            $errorCode === '1' && $statusCode === '2' => VendorTransferResultEnum::TIMEOUT,
            $errorCode === '2' => VendorTransferResultEnum::FAILED,
            $errorCode === '3' => VendorTransferResultEnum::TIMEOUT,
            default => VendorTransferResultEnum::FAILED,
        };
    }

    public function getLanguage(int $languageId): string
    {
        return match ($languageId) {
            LanguageEnum::EN->value => 'en',
            LanguageEnum::ZH_TW->value => 'ch',
            LanguageEnum::ZH_CN->value => 'cs',
            LanguageEnum::ID_ID->value => 'id',
            LanguageEnum::VI_VN->value => 'vn',
            LanguageEnum::TH_TH->value => 'th',
            default => 'en',
        };
    }

    /**
     * 建立我方遊戲商帳號（遷自 RD-Studio create_saba_account）
     *
     * Saba 端帳號 = OPERATOR_ID + '_' + 我方帳號（IS_TEST 時再加 _test），
     * getGameUrl／getBalance／transfer 全靠 username_modified，建號時必寫。
     */
    public function createAccount(CreateAccountParamsData $params): CreateAccountResultData
    {
        $this->setVendorInfo($params->vendorInfo);
        $username = $params->username;
        $info = $params->vendorInfo->info;

        // CreateMember 必填的轉帳上下限；缺設定直接失敗，不靜默用 0
        if (!isset($info['MaxTransfer'], $info['MinTransfer'])) {
            Log::error('[Saba][createAccount] 商戶未設定 MaxTransfer/MinTransfer，無法建號', [
                'vendor_info_id' => $params->vendorInfo->id,
            ]);

            return CreateAccountResultData::from(['status' => false, 'username' => $username]);
        }

        $usernameModified = $this->apiOperatorId . '_' . $username . (($info['IS_TEST'] ?? false) ? '_test' : '');

        $account = $this->accountModel::create([
            'vendor_info_id' => $params->vendorInfo->id,
            'username' => $username,
            'username_modified' => $usernameModified,
            'admin' => 1,
            'member_id' => -999999999,
        ]);

        if (!$account) {
            return CreateAccountResultData::from(['status' => false, 'username' => $username]);
        }

        // Saba 限制 1 秒只能呼叫一次
        sleep(1);

        if (!$this->createVendorAccount($usernameModified, (string) $info['MaxTransfer'], (string) $info['MinTransfer'])) {
            return CreateAccountResultData::from(['status' => false, 'username' => $username]);
        }

        $account->update(['member_id' => 0]);

        return CreateAccountResultData::from(['status' => true, 'username' => $username]);
    }

    /**
     * 建立三方遊戲商帳號（/api/CreateMember；無簽名機制，僅 vendor_id + IP 白名單）
     */
    private function createVendorAccount(string $vendorMemberId, string $maxTransfer, string $minTransfer): bool
    {
        try {
            $json = $this->callApi(
                action: $this->actionTitle . '_createAccount',
                endpoint: '/api/CreateMember',
                params: [
                    'vendor_id' => $this->apiVendorId,
                    'Vendor_Member_ID' => $vendorMemberId,
                    'OperatorId' => $this->apiOperatorId,
                    'UserName' => $vendorMemberId,
                    'OddsType' => $this->oddsType,
                    'Currency' => $this->currency,
                    'MaxTransfer' => $maxTransfer,
                    'MinTransfer' => $minTransfer,
                ],
            );
        } catch (Throwable $e) {
            Log::error('[Saba][CreateMember] request failed', [
                'vendor_member_id' => $vendorMemberId,
                'error' => $e->getMessage(),
                'trace' => Common::formatTrace($e),
            ]);

            return false;
        }

        if (!is_array($json) || (string) ($json['error_code'] ?? '') !== '0') {
            Log::error('[Saba][CreateMember] failed', [
                'vendor_member_id' => $vendorMemberId,
                'response' => $json,
            ]);

            return false;
        }

        return true;
    }
}
