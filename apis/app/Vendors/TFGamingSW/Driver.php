<?php

declare(strict_types=1);

namespace App\Vendors\TFGamingSW;

use App\Data\Vendor\CreateAccountParamsData;
use App\Data\Vendor\CreateAccountResultData;
use App\Data\VendorGameUrlParamsData;
use App\Data\VendorGameUrlResultData;
use App\Exceptions\ApiException;
use App\Helpers\Common;
use App\Models\Member;
use App\Vendors\AbstractSingleWallet;
use App\Vendors\Contracts\HasCallbackPath;
use App\Vendors\Contracts\ShouldScheduleAccountCreation;
use App\Vendors\Models\TfgamingswAccount;
use App\Vendors\Models\TfgamingswBetRecord;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class Driver extends AbstractSingleWallet implements HasCallbackPath, ShouldScheduleAccountCreation
{
    protected string $accountModel = TfgamingswAccount::class;

    protected string $betRecordModel = TfgamingswBetRecord::class;

    private VendorInfoDetailData $infoDetail;

    private TfgamingswAccount $account;

    public function __construct(int $vendorId, ?Member $member)
    {
        parent::__construct($vendorId, $member);

        if (!$member) {
            return;
        }

        $this->setInfo($this->vendorInfo->info);
        $this->account = $this->vendorAccount;
    }

    /**
     * 預設回調路徑後綴，對應 routes/external/sw.php 的 tfgaming 路由群組
     */
    public static function defaultCallbackPath(): string
    {
        return '/external/api/sw/tfgaming';
    }

    public function setInfo(array $info): void
    {
        $this->infoDetail = VendorInfoDetailData::from($info);
    }

    public function getGameUrl(VendorGameUrlParamsData $params): VendorGameUrlResultData
    {
        $this->userLogout();
        $gameUrl = $this->userLogin();

        return VendorGameUrlResultData::from([
            'pc' => $gameUrl,
            'mobile' => $gameUrl,
        ]);
    }

    private function userLogout(): void
    {
        $url = "{$this->infoDetail->API_URL}/api/v2/partner-account-logout/";

        $response = Http::logAction('TF_Gaming_SW_UserLogout')
            ->withHeaders([
                'Content-Type' => 'application/json',
                'Authorization' => $this->infoDetail->PRIVATE_TOKEN,
            ])
            ->post($url, [
                'member' => $this->account->username,
                'operator_id' => $this->infoDetail->OPERATOR_ID,
            ])
            ->throw()
            ->json();

        if (!isset($response['member'])) {
            throw new ApiException('[TFGaming SW][userLogout] Member not found');
        }
    }

    private function userLogin(): string
    {
        $url = "{$this->infoDetail->API_URL}/api/v2/member-login/";

        $response = Http::logAction('TF_Gaming_SW_UserLogin')
            ->withHeaders([
                'Content-Type' => 'application/json',
                'Authorization' => $this->infoDetail->PRIVATE_TOKEN,
            ])
            ->post($url, [
                'member_code' => $this->account->username,
            ])
            ->throw()
            ->json();

        if (!isset($response['launch_url'])) {
            throw new ApiException('[TFGaming SW][userLogin] Launch URL not found');
        }

        return $response['launch_url'];
    }

    /**
     * 建立我方遊戲商帳號（遷自 RD-Studio create_tfgaming_single_wallet_account）
     *
     * TFGaming 無密碼機制（member-login 只帶 member_code）。
     */
    public function createAccount(CreateAccountParamsData $params): CreateAccountResultData
    {
        $this->setVendorInfo($params->vendorInfo);
        $username = $params->username;

        $account = $this->accountModel::create([
            'vendor_info_id' => $params->vendorInfo->id,
            'username' => $username,
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
     * 建立三方會員（POST {API_URL}/api/v2/members/；尾斜線為三方要求，不可省）
     */
    private function createVendorAccount(string $username): bool
    {
        try {
            $response = Http::logAction('TF_Gaming_SW_api_call_members')
                ->withHeaders([
                    'Content-Type' => 'application/json',
                    'Authorization' => $this->infoDetail->PRIVATE_TOKEN,
                ])
                ->post("{$this->infoDetail->API_URL}/api/v2/members/", [
                    'member_code' => $username,
                ])
                ->throw()
                ->json();
        } catch (Throwable $e) {
            Log::error('[TFGamingSW][createAccount] request failed', [
                'username' => $username,
                'error' => $e->getMessage(),
                'trace' => Common::formatTrace($e),
            ]);

            return false;
        }

        if (!is_array($response) || isset($response['errors']) || ($response['member_code'] ?? null) !== $username) {
            Log::error('[TFGamingSW][createAccount] rejected', [
                'username' => $username,
                'response' => $response,
            ]);

            return false;
        }

        return true;
    }
}
