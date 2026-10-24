<?php

declare(strict_types=1);

namespace App\Vendors\Astar;

use App\Data\Vendor\CreateAccountParamsData;
use App\Data\Vendor\CreateAccountResultData;
use App\Data\VendorGameUrlParamsData;
use App\Data\VendorGameUrlResultData;
use App\Enums\General\ContextKeyEnum;
use App\Enums\LanguageEnum;
use App\Models\Member;
use App\Vendors\AbstractSingleWallet;
use App\Vendors\Astar\Datas\AstarVendorInfoDetail;
use App\Vendors\Contracts\HasCallbackPath;
use App\Vendors\Contracts\ShouldScheduleAccountCreation;
use App\Vendors\Models\AstarAccount;
use App\Vendors\Models\AstarBetRecord;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;

class Driver extends AbstractSingleWallet implements HasCallbackPath, ShouldScheduleAccountCreation
{
    protected string $accountModel = AstarAccount::class;

    protected string $betRecordModel = AstarBetRecord::class;

    private AstarVendorInfoDetail $infoDetail;

    private AstarAccount $account;

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
     * 預設回調路徑後綴，對應 routes/external/sw.php 的 astar/gamepool/{gamehall} 路由群組；
     * 後綴只到 gamepool，{gamehall} 由遊戲商依館別自行帶入
     */
    public static function defaultCallbackPath(): string
    {
        return '/external/api/sw/astar/gamepool';
    }

    public function setInfo(array $info): void
    {
        $this->infoDetail = AstarVendorInfoDetail::from($info);
    }

    public function getGameUrl(VendorGameUrlParamsData $params): VendorGameUrlResultData
    {
        $sessionToken = [REDACTED]'-', '', Str::uuid7()->toString() . bin2hex(random_bytes(32)));
        $this->account->update([
            'session_token' => $sessionToken,
        ]);

        $languageCode = $this->mapLanguageCode($params->language);
        $gameUrl = "{$this->infoDetail->GAME_URL}/{$this->infoDetail->GAMEHALL}?token=[REDACTED]";

        return new VendorGameUrlResultData(
            pc: $gameUrl,
            mobile: $gameUrl,
        );
    }

    private function mapLanguageCode(?LanguageEnum $language = null): string
    {
        if (empty($language)) {
            $languageId = Context::getHidden(ContextKeyEnum::LANGUAGE_ID->value);
        } else {
            $languageId = $language->value;
        }

        return match ($languageId) {
            LanguageEnum::ZH_TW->value => 'zh-tw',
            LanguageEnum::ZH_CN->value => 'zh-cn',
            LanguageEnum::VI_VN->value => 'vi-vn',
            LanguageEnum::TH_TH->value => 'th-th',
            default => 'en-us',
        };
    }

    /**
     * 建立我方遊戲商帳號（遷自 RD-Studio create_astar_account）
     *
     * 三方無建號 API（舊系統即為「內部建立」），首次進遊戲 URL 時才於三方落帳；
     * 登入 token 由 getGameUrl 每次重新產生，此處僅給初值。
     */
    public function createAccount(CreateAccountParamsData $params): CreateAccountResultData
    {
        $this->setVendorInfo($params->vendorInfo);
        $username = $params->username;

        $account = $this->accountModel::create([
            'vendor_info_id' => $params->vendorInfo->id,
            'username' => $username,
            // 注單以 vendor_username 對帳：GAMEHALL 前綴 + 帳號
            'vendor_username' => $this->infoDetail->GAMEHALL . $username,
            'session_token' => str_replace('-', '', Str::uuid7()->toString() . bin2hex(random_bytes(32))),
            'admin' => 1,
        ]);

        return CreateAccountResultData::from([
            'status' => (bool) $account,
            'username' => $username,
        ]);
    }
}
