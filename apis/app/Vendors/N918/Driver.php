<?php

declare(strict_types=1);

namespace App\Vendors\N918;

use App\Data\VendorGameUrlParamsData;
use App\Data\VendorGameUrlResultAppInfoData;
use App\Data\VendorGameUrlResultData;
use App\Data\VendorTransferData;
use App\Enums\LanguageEnum;
use App\Enums\Vendor\VendorTransferResultEnum;
use App\Exceptions\ApiException;
use App\Helpers\Common;
use App\Models\Member;
use App\Vendors\AbstractTransferWallet;
use App\Vendors\Models\N918Account;
use App\Vendors\Models\N918BetRecord;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

class Driver extends AbstractTransferWallet
{
    protected string $accountModel = N918Account::class;

    protected string $betRecordModel = N918BetRecord::class;

    protected string $apiUrl;

    protected string $agentId;

    protected string $authCode;

    protected string $secretKey;

    protected string $apiPassword;

    protected string $downloadPage;

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
        $this->agentId = $info['AGENT_ID'];
        $this->authCode = $info['AUTH_CODE'];
        $this->secretKey = $info['SECRET_KEY'];
        $this->apiPassword = [REDACTED]'API_PASSWORD'];
        $this->downloadPage = $info['DOWNLOAD_PAGE'];
    }

    /**
     * 玩家餘額轉入遊戲商 (存款)
     */
    public function transferToVendor(VendorTransferData $params): VendorTransferResultEnum
    {
        return $this->transfer(
            username: $this->vendorAccount->player_id,
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
            username: $this->vendorAccount->player_id,
            type: 2,
            credit: $params->vendorAmount,
            billNo: $params->transactionNo,
        );
    }

    /**
     * 取得遊戲網址
     */
    public function getGameUrl(VendorGameUrlParamsData $params): VendorGameUrlResultData
    {
        $username = $this->vendorAccount->player_id;
        $password = [REDACTED];
        $appInfo = VendorGameUrlResultAppInfoData::from([
            'username' => $username,
            'password' => $password,
            'ios_downloads' => [$this->downloadPage],
            'ios_open' => "lobbykissgame://account={$username}&password=[REDACTED]",
            'android_downloads' => [$this->downloadPage],
            'android_open' => "lobbykiss://lobbykiss?account={$username}&password=[REDACTED]",
            'app_icon' => $this->vendor->app_icon,
        ]);

        return VendorGameUrlResultData::from([
            // 'pc' => '',
            // 'mobile' => '',
            'app_infos' => $appInfo,
        ]);

    }

    /**
     * 取得餘額
     */
    public function getBalance(): string
    {
        $url = $this->apiUrl;
        $action = 'N918_getBalance';

        $now = Carbon::now()->getTimestamp();

        $data = [
            'action' => 'getUserInfo',
            'userName' => $this->vendorAccount->player_id,
            'time' => $now,
            'authcode' => $this->authCode,
            'sign' => $this->sign($this->authCode . $this->vendorAccount->player_id . $now . $this->secretKey),
        ];

        $response = Http::logAction($action)->get($url, $data)->throw();
        $json = $response->json();

        if (!$json) {
            throw new ApiException('Third party error: not json response');
        }
        if (!isset($json['error'])) {
            throw new ApiException('Third party error: format error, error not found');
        }
        if ((string) $json['error'] != '0') {
            throw new ApiException('Third party error: error code: ' . $json['error']);
        }
        if (!isset($json['score'])) {
            throw new ApiException('Third party error: format error, score not found');
        }

        return (string) $json['score'];
    }

    /**
     * 轉帳
     */
    protected function transfer(string $username, int $type, string $credit, string $billNo): VendorTransferResultEnum
    {
        $url = $this->apiUrl;
        $headers = [];

        if ($type == 1) {
            $headers['API'] = 'deposit';
            $action = 'N918_Deposit';
            $amount = (float) Common::numAdd('0', $credit);
        } else {
            $headers['API'] = 'withdraw';
            $action = 'N918_Withdrawal';
            $amount = (float) Common::numSub('0', $credit);
        }

        $now = Carbon::now()->getTimestamp();
        $data = [
            'action' => 'setServerScore',
            'orderid' => $billNo,
            'scoreNum' => $amount,
            'userName' => $username,
            'time' => $now,
            'authcode' => $this->authCode,
            'sign' => $this->sign($this->authCode . $username . $now . $this->secretKey),
        ];

        $response = Http::logAction($action)->get($url, $data)->throw();
        $json = $response->json();

        if (!$json) {
            return VendorTransferResultEnum::TIMEOUT;
        }

        if (!$json) {
            throw new ApiException('Third party error: not json response');
        }
        if (!isset($json['error'])) {
            throw new ApiException('Third party error: format error, error not found');
        }
        if ((string) $json['error'] != '0') {
            throw new ApiException('Third party error: error code: ' . $json['error']);
        }

        return VendorTransferResultEnum::SUCCESS;
    }

    protected function getLanguage(int $languageId): string
    {
        return match ($languageId) {
            LanguageEnum::EN->value => 'en_US',
            LanguageEnum::ZH_CN->value => 'zh_CN',
            LanguageEnum::ZH_TW->value => 'zh_TW',
            LanguageEnum::TH_TH->value => 'th_TH',
            LanguageEnum::ID_ID->value => 'id_ID',
            LanguageEnum::VI_VN->value => 'vi_VN',
            LanguageEnum::MY_MM->value => 'my_MM',
            LanguageEnum::HI_IN->value => 'hi_IN',
            default => 'en_US',
        };
    }

    protected function sign(string $string): string
    {
        return strtoupper(md5(strtolower($string)));
    }
}
