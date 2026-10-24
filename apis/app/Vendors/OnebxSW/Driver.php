<?php

declare(strict_types=1);

namespace App\Vendors\OnebxSW;

use App\Data\Vendor\CreateAccountParamsData;
use App\Data\Vendor\CreateAccountResultData;
use App\Data\VendorGameUrlParamsData;
use App\Data\VendorGameUrlResultData;
use App\Enums\General\ContextKeyEnum;
use App\Enums\LanguageEnum;
use App\Models\Member;
use App\Models\VendorInfo;
use App\Vendors\AbstractSingleWallet;
use App\Vendors\Contracts\HasCallbackPath;
use App\Vendors\Contracts\ShouldScheduleAccountCreation;
use App\Vendors\Models\OnebxswAccount;
use App\Vendors\Models\OnebxswBetRecord;
use App\Vendors\OnebxSW\Datas\VendorInfoDetail;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Http;

class Driver extends AbstractSingleWallet implements HasCallbackPath, ShouldScheduleAccountCreation
{
    protected string $accountModel = OnebxswAccount::class;

    protected string $betRecordModel = OnebxswBetRecord::class;

    private OnebxswAccount $account;

    private VendorInfoDetail $infoDetail;

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
     * 預設回調路徑後綴，對應 routes/external/sw.php 的 onebx/api 路由群組
     */
    public static function defaultCallbackPath(): string
    {
        return '/external/api/sw/onebx/api';
    }

    public function setInfo(array $info): void
    {
        $this->infoDetail = VendorInfoDetail::from($this->vendorInfo->info);
    }

    /**
     * DB 儲存的帳號帶底線（`{prefix}_{8位流水號}`），沿用預設（無底線）會讓
     * VendorAccountPoolService 解析流水號時把底線後字串 CAST 成 0，見 PlayTechSW 同型修復
     */
    public function getStoredAccountPrefix(VendorInfo $vendorInfo): string
    {
        return $vendorInfo->account_prefix . '_';
    }

    /**
     * 無三方建號 API（純寫本地帳號池，舊系統 RD-Studio `onebxsw:create_account` 同行為）
     */
    public function createAccount(CreateAccountParamsData $params): CreateAccountResultData
    {
        $this->setVendorInfo($params->vendorInfo);

        // $params->username 由 VendorAccountPoolService 以 account_prefix 直接組出（無底線），
        // 本商戶儲存格式帶底線，需自行以 serialNumber 重組，不可直接沿用
        $username = $params->vendorInfo->account_prefix . '_' . str_pad((string) ($params->serialNumber + 1), 8, '0', STR_PAD_LEFT);

        $account = $this->accountModel::create([
            'vendor_info_id' => $this->vendorInfo->id,
            'username' => $username,
        ]);

        return CreateAccountResultData::from([
            'status' => (bool) $account,
            'username' => $username,
        ]);
    }

    public function getGameUrl(VendorGameUrlParamsData $params): VendorGameUrlResultData
    {
        $language = $this->mapLanguageCode($params->language);

        $postData = [
            'merchantid' => $this->infoDetail->MERCHANT_ID,
            'secretkey' => $this->infoDetail->SECRET_KEY,
            'username' => $this->account->username,
            'lang' => $language,
        ];

        $response = Http::logAction('OneBxSw_api_call_mem/login')
            ->withHeaders([
                'Content-Type' => 'application/json',
            ])
            ->post("{$this->infoDetail->API_URL}/api/v1/mem/login", $postData)
            ->throw()
            ->json();

        $gameUrl = $response['data']['url'] ?? '';

        return VendorGameUrlResultData::from([
            'pc' => $gameUrl,
            'mobile' => $gameUrl,
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
            LanguageEnum::EN->value => 'en',
            LanguageEnum::ZH_TW->value => 'cn',
            LanguageEnum::ZH_CN->value => 'cn',
            LanguageEnum::TH_TH->value => 'th',
            default => 'en',
        };
    }
}
