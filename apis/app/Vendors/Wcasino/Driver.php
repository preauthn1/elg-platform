<?php

declare(strict_types=1);

namespace App\Vendors\Wcasino;

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
use App\Vendors\Models\WcasinoswAccount;
use App\Vendors\Models\WcasinoswBetRecord;
use App\Vendors\Wcasino\Datas\VendorInfoDetailData;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class Driver extends AbstractSingleWallet implements HasCallbackPath, ShouldScheduleAccountCreation
{
    protected string $accountModel = WcasinoswAccount::class;

    protected string $betRecordModel = WcasinoswBetRecord::class;

    private VendorInfoDetailData $infoDetail;

    public function __construct(int $vendorId, ?Member $member)
    {
        parent::__construct($vendorId, $member);

        if (!$member) {
            return;
        }

        $this->setInfo($this->vendorInfo->info);
    }

    /**
     * 預設回調路徑後綴，對應 routes/external/sw.php 的 wcasino/api 路由群組
     */
    public static function defaultCallbackPath(): string
    {
        return '/external/api/sw/wcasino/api';
    }

    public function setInfo(array $info): void
    {
        $this->infoDetail = VendorInfoDetailData::from($info);
    }

    public function getGameUrl(VendorGameUrlParamsData $params): VendorGameUrlResultData
    {
        $action = 'GetGameUrl';

        $data = [
            'appid' => $this->infoDetail->CLIENT_ID,
            'username' => $this->vendorAccount->username,
            'nickname' => $this->vendorAccount->username,
            'iscreate' => 1,
            'clienttype' => $params->deviceType === DeviceTypeEnum::PC ? 1 : 2,
            'language' => $this->mapLanguageCode($params->language),
            'returnurl' => $params->exitUrl,
        ];

        $sign = $this->sign($data);
        $queryString = http_build_query($data) . '&sign=' . $sign;
        $url = "{$this->infoDetail->AUTH_URL}/api/login?{$queryString}";

        $response = Http::logAction("Wcasino_{$action}")
            ->asJson()
            ->post($url)
            ->throw()
            ->json();

        if (!is_array($response) || !isset($response['result'])) {
            throw new ApiException("[Wcasino][{$action}] API error: bad response.");
        }

        if ((string) $response['result'] !== '0') {
            throw new ApiException("[Wcasino][{$action}] API error: failed.");
        }

        if (!isset($response['openurl'])) {
            throw new ApiException("[Wcasino][{$action}] API error: missing data.");
        }

        $this->setQuota();

        return VendorGameUrlResultData::from([
            'pc' => $response['openurl'],
            'mobile' => $response['openurl'],
        ]);
    }

    private function setQuota()
    {
        if (empty($this->infoDetail->CLIENT_QID)) {
            return;
        }

        $action = 'BetLimit';
        $data = [
            'appid' => $this->infoDetail->CLIENT_ID,
            'username' => $this->vendorAccount->username,
            'qids' => $this->infoDetail->CLIENT_QID,
        ];

        $sign = $this->sign($data);
        $queryString = http_build_query($data) . '&sign=' . $sign;
        $url = "{$this->infoDetail->AUTH_URL}/api/quota/set?{$queryString}";

        $response = Http::logAction("Wcasino_{$action}")
            ->asJson()
            ->post($url)
            ->throw()
            ->json();

        if (!is_array($response) || !isset($response['result'])) {
            throw new ApiException("[Wcasino][{$action}] API error: bad response.");
        }

        if ((string) $response['result'] !== '0') {
            throw new ApiException("[Wcasino][{$action}] API error: failed.");
        }
    }

    private function mapLanguageCode(?LanguageEnum $language = null): string
    {
        if (empty($language)) {
            $languageId = Context::getHidden(ContextKeyEnum::LANGUAGE_ID->value);
        } else {
            $languageId = $language->value;
        }

        return match ($languageId) {
            LanguageEnum::ZH_TW->value => '6',
            LanguageEnum::ZH_CN->value => '1',
            LanguageEnum::ID_ID->value => '11',
            LanguageEnum::VI_VN->value => '10',
            LanguageEnum::TH_TH->value => '5',
            default => '2',
        };
    }

    private function sign(array $fields): string
    {
        $data = [];
        foreach ($fields as $key => $value) {
            $data[] = strtolower($key) . '=' . $value;
        }
        sort($data);
        $rawData = implode('&', $data) . '&key=' . $this->infoDetail->CLIENT_SECRET;

        return md5($rawData);
    }

    /**
     * 建立我方遊戲商帳號（遷自 RD-Studio create_wcasino_single_wallet_account）
     *
     * Wcasino 沒有獨立註冊 API，建號＝以 iscreate=1 打一次 /api/login（與 getGameUrl 同一支）。
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
     * 建立三方遊戲商帳號
     */
    private function createVendorAccount(string $username): bool
    {
        $action = 'CreateAccount';

        $data = [
            'appid' => $this->infoDetail->CLIENT_ID,
            'username' => $username,
            'iscreate' => 1,
            'clienttype' => 1,
            'language' => 2,
        ];

        $queryString = http_build_query($data) . '&sign=' . $this->sign($data);

        try {
            $response = Http::logAction("Wcasino_{$action}")
                ->asJson()
                ->post("{$this->infoDetail->AUTH_URL}/api/login?{$queryString}")
                ->throw()
                ->json();
        } catch (Throwable $e) {
            Log::error("[Wcasino][{$action}] request failed", [
                'username' => $username,
                'error' => $e->getMessage(),
                'trace' => Common::formatTrace($e),
            ]);

            return false;
        }

        if (!is_array($response) || (string) ($response['result'] ?? '') !== '0') {
            Log::error("[Wcasino][{$action}] failed", ['username' => $username, 'response' => $response]);

            return false;
        }

        return true;
    }
}
