<?php

declare(strict_types=1);

namespace App\Vendors\I1betnow;

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
use App\Vendors\Models\LbbAccount;
use App\Vendors\Models\LbbBetRecord;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * @property LbbAccount $vendorAccount
 */
class Driver extends AbstractTransferWallet implements ShouldScheduleAccountCreation
{
    protected string $accountModel = LbbAccount::class;

    protected string $betRecordModel = LbbBetRecord::class;

    protected string $apiUrl;

    protected string $accessId;

    protected string $accessToken;

    protected string $logo;

    protected string $actionPrefix = 'i1betnow_';

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
        $this->accessId = $info['ACCESS_ID'];
        $this->accessToken = [REDACTED]'ACCESS_TOKEN'];
        $this->logo = $info['LOGO'];
    }

    public function getGameUrl(VendorGameUrlParamsData $params): VendorGameUrlResultData
    {
        $language = $params->language;
        $exitUrl = $params->exitUrl;
        $languageCode = $this->mapLanguageCode($language);

        $data = [
            'module' => '/member/getLaunchURL',
            'accessId' => $this->accessId,
            'accessToken' => $this->accessToken,
            'username' => $this->vendorAccount->username_modified,
            'logo' => $this->logo,
            'lang' => $languageCode,
        ];

        $response = $this->sendRequest('getGameUrl', $data);

        if (!isset($response['status'])) {
            throw new ApiException('Third party error: status not found');
        }

        if ((string) $response['status'] !== 'SUCCESS') {
            $message = $response['data']['message'] ?? 'Unknown error';
            throw new ApiException(sprintf('Third party error: status [%s] is not SUCCESS, message: %s', $response['status'], $message));
        }

        if (!isset($response['data']['url'])) {
            throw new ApiException('Third party error: url not found');
        }

        $url = $response['data']['url'];

        return VendorGameUrlResultData::from([
            'pc' => $url,
            'mobile' => $url,
        ]);
    }

    /**
     * @throws ConnectionException
     */
    public function getBalance(): string
    {
        $data = [
            'module' => '/member/get',
            'accessId' => $this->accessId,
            'accessToken' => $this->accessToken,
            'username' => $this->vendorAccount->username_modified,
        ];

        $response = $this->sendRequest('getBalance', $data);

        if (!isset($response['status'])) {
            throw new ApiException('Third party error: status not found');
        }

        if ((string) $response['status'] !== 'SUCCESS') {
            $message = $response['data']['message'] ?? 'Unknown error';
            throw new ApiException(sprintf('Third party error: status [%s] is not SUCCESS, message: %s', $response['status'], $message));
        }

        if (!isset($response['data']['credit'])) {
            throw new ApiException('Third party error: credit not found');
        }

        $balance = (string) $response['data']['credit'];

        return $balance;
    }

    /**
     * @throws ApiException|ConnectionException
     */
    public function transferToLocal(VendorTransferData $params): VendorTransferResultEnum
    {
        return $this->transfer('withdrawal', $params);
    }

    /**
     * @throws ApiException|ConnectionException
     */
    public function transferToVendor(VendorTransferData $params): VendorTransferResultEnum
    {
        return $this->transfer('deposit', $params);
    }

    /**
     * @throws ApiException|ConnectionException
     */
    private function transfer(string $action, VendorTransferData $params): VendorTransferResultEnum
    {
        $credit = Common::numFloor($params->vendorAmount, 2);

        if ($action == 'withdrawal') {
            $credit = (float) Common::numSub('0', $credit);
        } else {
            $credit = (float) Common::numAdd('0', $credit);
        }

        $data = [
            'module' => '/member/setScore',
            'accessId' => $this->accessId,
            'accessToken' => $this->accessToken,
            'username' => $this->vendorAccount->username_modified,
            'amount' => $credit,
        ];

        $response = $this->sendRequest('transfer', $data);

        if (!isset($response['status'])) {
            throw new ApiException('Third party error: status not found');
        }

        if ((string) $response['status'] !== 'SUCCESS') {
            $message = $response['data']['message'] ?? 'Unknown error';
            throw new ApiException(sprintf('Third party error: status [%s] is not SUCCESS, message: %s', $response['status'], $message));
        }

        return VendorTransferResultEnum::SUCCESS;
    }

    /**
     * 封裝請求
     *
     * @throws ConnectionException
     */
    private function sendRequest(string $action, array $data = []): array
    {
        $url = $this->apiUrl;
        $response = Http::logAction($this->actionPrefix . $action)
            ->asForm()
            ->post($url, $data)
            ->throw()
            ->json();

        if (!$response) {
            throw new ApiException('Third party error: not json response');
        }

        return $response;
    }

    /**
     * 轉換語系代號, 不要再寫進 languages
     */
    private function mapLanguageCode(LanguageEnum $language): string
    {
        // EN, MS, ZH, TH, ID, VI, KM
        return match ($language) {
            LanguageEnum::EN => 'EN',
            LanguageEnum::MS_MY => 'MS',
            LanguageEnum::ZH_CN => 'ZH',
            LanguageEnum::TH_TH => 'TH',
            LanguageEnum::VI_VN => 'VI',
            LanguageEnum::ID_ID => 'ID',
            default => 'EN',
        };
    }

    /**
     * 建立我方遊戲商帳號（遷自 RD-Studio create_lbb_account）
     *
     * LBB 無密碼機制，登入／轉帳一律用 username_modified（同時是注單對帳鍵）。
     */
    public function createAccount(CreateAccountParamsData $params): CreateAccountResultData
    {
        $this->setVendorInfo($params->vendorInfo);
        $username = $params->username;

        $account = $this->accountModel::create([
            'vendor_info_id' => $params->vendorInfo->id,
            'username' => $username,
            'username_modified' => $this->accessId . '@' . $username,
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
     */
    private function createVendorAccount(LbbAccount $account): bool
    {
        $data = [
            'module' => '/member/create',
            'accessId' => $this->accessId,
            'accessToken' => $this->accessToken,
            'username' => $account->username_modified,
        ];

        try {
            $response = $this->sendRequest('createAccount', $data);
        } catch (Throwable $e) {
            Log::error('[I1betnow][createAccount] request failed', [
                'username' => $account->username,
                'error' => $e->getMessage(),
                'trace' => Common::formatTrace($e),
            ]);

            return false;
        }

        if ((string) ($response['status'] ?? '') === 'SUCCESS') {
            return true;
        }

        Log::error('[I1betnow][createAccount] status is not SUCCESS', [
            'username' => $account->username,
            'message' => $response['data']['message'] ?? 'Unknown error',
        ]);

        return false;
    }
}
