<?php

declare(strict_types=1);

namespace App\Vendors\Rcb988;

use App\Data\Vendor\CreateAccountParamsData;
use App\Data\Vendor\CreateAccountResultData;
use App\Data\VendorGameUrlParamsData;
use App\Data\VendorGameUrlResultData;
use App\Enums\General\ContextKeyEnum;
use App\Enums\General\DeviceTypeEnum;
use App\Enums\LanguageEnum;
use App\Exceptions\ApiException;
use App\Helpers\Common;
use App\Models\Member;
use App\Vendors\AbstractSingleWallet;
use App\Vendors\Contracts\HasCallbackPath;
use App\Vendors\Contracts\ShouldScheduleAccountCreation;
use App\Vendors\Models\Rcb988Account;
use App\Vendors\Models\Rcb988BetRecord;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class Driver extends AbstractSingleWallet implements HasCallbackPath, ShouldScheduleAccountCreation
{
    protected string $accountModel = Rcb988Account::class;

    protected string $betRecordModel = Rcb988BetRecord::class;

    private Rcb988VendorInfoDetail $infoDetail;

    public function __construct(int $vendorId, ?Member $member)
    {
        parent::__construct($vendorId, $member);

        if (!$member) {
            return;
        }

        $this->setInfo($this->vendorInfo->info);
    }

    /**
     * 預設回調路徑後綴，對應 routes/external/sw.php 的 rcb988 單一入口路由
     */
    public static function defaultCallbackPath(): string
    {
        return '/external/api/sw/rcb988';
    }

    public function setInfo(array $info): void
    {
        $this->infoDetail = Rcb988VendorInfoDetail::from($info);
    }

    public function getGameUrl(VendorGameUrlParamsData $params): VendorGameUrlResultData
    {
        $url = "{$this->infoDetail->AUTH_URL}/wallet/doLoginAndLaunchGame";
        $action = 'GetGameUrl';

        $data = [
            'cert' => $this->infoDetail->CLIENT_SECRET,
            'agentId' => $this->infoDetail->CLIENT_ID,
            'userId' => $this->vendorAccount->username,
            'password' => $this->getDefaultPassword($this->vendorAccount->username, $this->vendorAccount->created_at->toDateTimeString()),
            'platform' => $this->infoDetail->CLIENT_GAME_PLATFORM,
            'gameType' => $this->infoDetail->CLIENT_GAME_TYPE,
            'gameCode' => $this->infoDetail->CLIENT_GAME_CODE,
            'currency' => $this->infoDetail->CURRENCY,
            'isMobileLogin' => $params->deviceType === DeviceTypeEnum::MOBILE,
            'language' => $this->mapLanguageCode($params->language),
            'autoBetMode' => 0,
            'betLimit' => json_encode([
                $this->infoDetail->CLIENT_GAME_PLATFORM => [
                    $this->infoDetail->CLIENT_GAME_TYPE => [
                        'minbet' => $this->infoDetail->CLIENT_MAJOR_MIN_BET,
                        'maxbet' => $this->infoDetail->CLIENT_MAJOR_MAX_BET,
                        'maxBetSumPerHorse' => $this->infoDetail->CLIENT_MAJOR_MAX_BET_SUM_PER_HORSE,
                        'minorMinbet' => $this->infoDetail->CLIENT_MINOR_MIN_BET,
                        'minorMaxbet' => $this->infoDetail->CLIENT_MINOR_MAX_BET,
                        'minorMaxBetSumPerHorse' => $this->infoDetail->CLIENT_MINOR_MAX_BET_SUM_PER_HORSE,
                    ],
                ],
            ]),
        ];

        $response = Http::logAction("Rcb988_{$action}")
            ->asForm()
            ->post($url, $data)
            ->throw()
            ->json();

        if (!is_array($response) || !isset($response['status'])) {
            throw new ApiException("[Rcb988][{$action}] API error: bad response.");
        }

        if ((string) $response['status'] !== '0000') {
            throw new ApiException("[Rcb988][{$action}] API error: failed.");
        }

        if (!isset($response['url'])) {
            throw new ApiException("[Rcb988][{$action}] API error: missing data.");
        }

        return VendorGameUrlResultData::from([
            'pc' => $response['url'],
            'mobile' => $response['url'],
        ]);
    }

    private function mapLanguageCode(?LanguageEnum $language = null): string
    {
        if (empty($language)) {
            $languageId = Context::getHidden(ContextKeyEnum::LANGUAGE_ID->value);
        } else {
            $languageId = $language->value;
        }

        return match ($languageId) {
            LanguageEnum::ZH_TW->value => 'cn',
            LanguageEnum::ZH_CN->value => 'cn',
            LanguageEnum::VI_VN->value => 'vn',
            LanguageEnum::TH_TH->value => 'th',
            default => 'en',
        };
    }

    /**
     * 建立我方遊戲商帳號（遷自 RD-Studio create_rcb988_account）
     *
     * 建號不送密碼（同舊系統）；登入由 getGameUrl 以 getDefaultPassword 重算。
     */
    public function createAccount(CreateAccountParamsData $params): CreateAccountResultData
    {
        $this->setVendorInfo($params->vendorInfo);
        $username = $params->username;

        $account = $this->accountModel::create([
            'vendor_info_id' => $params->vendorInfo->id,
            'username' => $username,
            // vendor_username 為 NOT NULL 無 default，且是注單對照欄
            'vendor_username' => $this->infoDetail->CLIENT_CLASS . $username,
            'session_token' => str_replace('-', '', Str::uuid()->toString()) . bin2hex(random_bytes(32)),
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
     * 建立三方遊戲商帳號（/wallet/createMember；betLimit 結構與 getGameUrl 相同）
     */
    private function createVendorAccount(string $username): bool
    {
        $info = $this->infoDetail;
        $action = 'CreateAccount';

        $data = [
            'cert' => $info->CLIENT_SECRET,
            'agentId' => $info->CLIENT_ID,
            'currency' => $info->CURRENCY,
            'userId' => $username,
            'userName' => $username,
            'language' => 'en',
            'betLimit' => json_encode([
                $info->CLIENT_GAME_PLATFORM => [
                    $info->CLIENT_GAME_TYPE => [
                        'minbet' => $info->CLIENT_MAJOR_MIN_BET,
                        'maxbet' => $info->CLIENT_MAJOR_MAX_BET,
                        'maxBetSumPerHorse' => $info->CLIENT_MAJOR_MAX_BET_SUM_PER_HORSE,
                        'minorMinbet' => $info->CLIENT_MINOR_MIN_BET,
                        'minorMaxbet' => $info->CLIENT_MINOR_MAX_BET,
                        'minorMaxBetSumPerHorse' => $info->CLIENT_MINOR_MAX_BET_SUM_PER_HORSE,
                    ],
                ],
            ]),
        ];

        try {
            $response = Http::logAction("Rcb988_{$action}")
                ->asForm()
                ->post("{$info->AUTH_URL}/wallet/createMember", $data)
                ->throw()
                ->json();
        } catch (Throwable $e) {
            Log::error("[Rcb988][{$action}] request failed", [
                'username' => $username,
                'error' => $e->getMessage(),
                'trace' => Common::formatTrace($e),
            ]);

            return false;
        }

        if (!is_array($response) || (string) ($response['status'] ?? '') !== '0000') {
            Log::error("[Rcb988][{$action}] failed", ['username' => $username, 'response' => $response]);

            return false;
        }

        return true;
    }
}
