<?php

declare(strict_types=1);

namespace App\Vendors\WudiSW;

use App\Data\GameInfoData;
use App\Data\Vendor\CreateAccountParamsData;
use App\Data\Vendor\CreateAccountResultData;
use App\Data\Vendor\GetGameListParamsData;
use App\Data\Vendor\GetGameListResultData;
use App\Data\VendorGameUrlParamsData;
use App\Data\VendorGameUrlResultData;
use App\Enums\Game\GameTypeEnum;
use App\Enums\General\ContextKeyEnum;
use App\Enums\LanguageEnum;
use App\Exceptions\ApiException;
use App\Exceptions\VendorSetupException;
use App\Models\EventVendorInfo;
use App\Models\Member;
use App\Models\VendorInfo;
use App\Vendors\AbstractSingleWallet;
use App\Vendors\Contracts\HasCallbackPath;
use App\Vendors\Contracts\ShouldScheduleAccountCreation;
use App\Vendors\Contracts\ShouldSyncGameList;
use App\Vendors\Models\AbstractAccount;
use App\Vendors\Models\EventWudiswAccount;
use App\Vendors\Models\WudiswAccount;
use App\Vendors\Models\WudiswBetRecord;
use App\Vendors\WudiSW\Datas\VendorInfoDetailData;
use Exception;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Http;

/**
 * WUDISW(170) / WUDISW_FREE(223) 共用同一支 Driver（見 config/vendor.php），
 * 帳號池以 vendor_info_id 區隔，createAccount() 對兩者行為一致
 */
class Driver extends AbstractSingleWallet implements HasCallbackPath, ShouldScheduleAccountCreation, ShouldSyncGameList
{
    protected string $accountModel = WudiswAccount::class;

    protected string $betRecordModel = WudiswBetRecord::class;

    /**
     * 一般會員流程放 {@see WudiswAccount}，舊版活動流程放 {@see EventWudiswAccount}；
     * 兩者是平行的 AbstractAccount 子類別，此處只取用 `username`（Eloquent magic property）
     */
    private AbstractAccount $account;

    private VendorInfoDetailData $infoDetail;

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
     * 預設回調路徑後綴，對應 routes/external/sw.php 的 wudi 路由群組
     */
    public static function defaultCallbackPath(): string
    {
        return '/external/api/sw/wudi';
    }

    public function setInfo(array $info): void
    {
        $this->infoDetail = VendorInfoDetailData::from($info);
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
     * 無三方建號 API（純寫本地帳號池，舊系統 RD-Studio `wudisw:create_account` /
     * `wudiswfree:create_account` 同行為）
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

    /**
     * 建構子只在有 member 時才把 vendorInfo/vendorAccount 轉存到本類別的私有屬性，
     * 舊版活動情境（`member: null` 建構）需在此補做同一件事，見 {@see BaseAbstractVendor::useLegacyEventContext()}
     */
    public function useLegacyEventContext(EventVendorInfo $eventVendorInfo, AbstractAccount $eventAccount): void
    {
        parent::useLegacyEventContext($eventVendorInfo, $eventAccount);

        if (!($eventAccount instanceof EventWudiswAccount)) {
            throw new VendorSetupException(static::class . '::useLegacyEventContext requires an EventWudiswAccount');
        }

        $this->setInfo($eventVendorInfo->info);
        $this->account = $eventAccount;
    }

    public function getGameUrl(VendorGameUrlParamsData $params): VendorGameUrlResultData
    {
        $data = $this->send('user_login', [
            'username' => $this->account->username,
            'game_code' => $params->gameCode,
            'lang' => $this->mapLanguageCode($params->language),
        ]);

        $gameUrl = $data['game_url'] ?? '';

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
            LanguageEnum::ZH_TW->value => 'zh-tw',
            LanguageEnum::ZH_CN->value => 'zh-cn',
            LanguageEnum::MS_MY->value => 'my',
            LanguageEnum::ID_ID->value => 'id',
            LanguageEnum::VI_VN->value => 'vi',
            LanguageEnum::TH_TH->value => 'th',
            default => 'en',
        };
    }

    /**
     * 遊戲清單（回應為 {遊戲類型: [遊戲]} 巢狀結構，遊戲名稱以語系碼為 key 直接掛在遊戲上）
     */
    public function getGameList(GetGameListParamsData $params): GetGameListResultData
    {
        $this->setVendorInfo($params->vendorInfo);

        $response = $this->send('get_gamelist', []);

        $typeMap = [
            'table' => GameTypeEnum::TABLE->value,
            'slots' => GameTypeEnum::SLOTS->value,
            'fish' => GameTypeEnum::FISHING->value,
            'p2p' => GameTypeEnum::POKER->value,
            'arcade' => GameTypeEnum::ARCADE->value,
            'live' => GameTypeEnum::LIVE_CASINO->value,
        ];

        $langMap = [
            'en' => LanguageEnum::EN->value,
            'tw' => LanguageEnum::ZH_TW->value,
            'cn' => LanguageEnum::ZH_CN->value,
            'id' => LanguageEnum::ID_ID->value,
            'vi' => LanguageEnum::VI_VN->value,
            'th' => LanguageEnum::TH_TH->value,
        ];

        $data = [];
        foreach ($response ?? [] as $gameType => $gameList) {
            foreach ($gameList as $game) {
                $gameCode = (string) $game['game_code'];
                $gameInfo = GameInfoData::from([
                    'gameCode' => $gameCode,
                    'gameName' => $game['en'],
                    'imageUrl' => '',
                    'gameType' => $typeMap[strtolower((string) $gameType)] ?? GameTypeEnum::EVENT_AND_OTHERS->value,
                    'status' => 1,
                    'info' => json_encode($game),
                ]);

                foreach ($langMap as $langCode => $languageId) {
                    if (!empty($game[$langCode])) {
                        $gameInfo->setGameNames($languageId, $game[$langCode]);
                    }
                }

                $data[$gameCode] = $gameInfo;
            }
        }

        return GetGameListResultData::from(['gameList' => $data]);
    }

    /**
     * Summary of send
     *
     * @return mixed
     *
     * @throws Exception Url or securityKey is empty, use setVendorInfo() to set the value
     * @throws Exception VendorSend send response is not string or is empty
     */
    private function send(string $api, array $postData): ?array
    {
        $action = "Wudi_SW_api_call_{$api}";

        $this->check();

        $postData['account_id'] = $this->infoDetail->ACCOUNT_ID;
        $postData['check_code'] = $this->makeCheckCode($postData);

        $apiUrl = trim($this->infoDetail->API_URL, '/');
        $api = trim($api, '/');

        $response = Http::logAction($action)
            ->withHeaders([
                'Content-Type:application/json',
            ])
            ->post("{$apiUrl}/agent/{$api}", $postData)
            ->throw()
            ->json();

        if (empty($response)) {
            throw new Exception('VendorSend send response is empty');
        }

        if (!isset($response['status']['code']) || $response['status']['code'] !== 1000) {
            // 三方業務性拒絕（維護中、帳號異常等），非系統故障：走 ApiException 讓 AlertGate 靜音不發 TG。
            throw new ApiException('VendorSend send response code is not 1000, msg: ' . ($response['status']['message'] ?? ''));
        }

        return $response['data'] ?? [];
    }

    private function check(): void
    {
        if (empty($this->infoDetail)) {
            throw new Exception('VendorInfo detail is empty, use setVendorInfo() to set the value');
        }

        if (empty($this->infoDetail->API_URL)) {
            throw new Exception("VendorInfo detail's API_URL is empty, please check the value");
        }

        if (empty($this->infoDetail->ACCOUNT_ID)) {
            throw new Exception("VendorInfo detail's ACCOUNT_ID is empty, please check the value");
        }

        if (empty($this->infoDetail->MD5_KEY)) {
            throw new Exception("VendorInfo detail's MD5_KEY is empty, please check the value");
        }

        if (empty($this->infoDetail->SECURITY_CODE)) {
            throw new Exception("VendorInfo detail's SECURITY_CODE is empty, please check the value");
        }
    }

    /**
     * Sort keys asc, then build query string, then append APP_SECRET to the end, then md5
     *
     * @param  array  $postData  reference
     * @return void
     */
    private function makeCheckCode(array $postData): string
    {
        unset($postData['check_code']);

        ksort($postData);
        $sign = 'security_code=' . $this->infoDetail->SECURITY_CODE . '&' . urldecode(http_build_query($postData));

        return md5($sign);
    }
}
