<?php

declare(strict_types=1);

namespace App\Vendors\FumaSW;

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
use App\Vendors\FumaSW\Datas\VendorInfoDetailData;
use App\Vendors\Models\AbstractAccount;
use App\Vendors\Models\EventFumaswAccount;
use App\Vendors\Models\FumaswAccount;
use App\Vendors\Models\FumaswBetRecord;
use Exception;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Http;

class Driver extends AbstractSingleWallet implements HasCallbackPath, ShouldScheduleAccountCreation, ShouldSyncGameList
{
    protected string $accountModel = FumaswAccount::class;

    protected string $betRecordModel = FumaswBetRecord::class;

    /**
     * 一般會員流程放 {@see FumaswAccount}，舊版活動流程放 {@see EventFumaswAccount}；
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
     * 預設回調路徑後綴，對應 routes/external/sw.php 的 fuma 單一入口路由
     */
    public static function defaultCallbackPath(): string
    {
        return '/external/api/sw/fuma';
    }

    public function setInfo(array $info): void
    {
        $this->infoDetail = VendorInfoDetailData::from($info);
    }

    /**
     * 建構子只在有 member 時才把 vendorInfo/vendorAccount 轉存到本類別的私有屬性，
     * 舊版活動情境（`member: null` 建構）需在此補做同一件事，見 {@see BaseAbstractVendor::useLegacyEventContext()}
     */
    public function useLegacyEventContext(EventVendorInfo $eventVendorInfo, AbstractAccount $eventAccount): void
    {
        parent::useLegacyEventContext($eventVendorInfo, $eventAccount);

        if (!($eventAccount instanceof EventFumaswAccount)) {
            throw new VendorSetupException(static::class . '::useLegacyEventContext requires an EventFumaswAccount');
        }

        $this->setInfo($eventVendorInfo->info);
        $this->account = $eventAccount;
    }

    /**
     * DB 儲存的帳號帶底線（`{prefix}_{8位流水號}`），沿用預設（無底線）會讓
     * VendorAccountPoolService 解析流水號時把底線後字串 CAST 成 0，見 PlayTechSW 同型修復
     */
    public function getStoredAccountPrefix(VendorInfo $vendorInfo): string
    {
        return $vendorInfo->account_prefix . '_';
    }

    public function createAccount(CreateAccountParamsData $params): CreateAccountResultData
    {
        // 注入遊戲商戶資料
        $this->setVendorInfo($params->vendorInfo);

        // $params->username 由 VendorAccountPoolService 以 account_prefix 直接組出（無底線），
        // 本商戶儲存格式帶底線，需自行以 serialNumber 重組，不可直接沿用
        $username = $params->vendorInfo->account_prefix . '_' . str_pad((string) ($params->serialNumber + 1), 8, '0', STR_PAD_LEFT);

        $data = [
            'vendor_info_id' => $this->vendorInfo->id,
            'username' => $username,
        ];

        $account = $this->accountModel::create($data);

        return CreateAccountResultData::from([
            'status' => $account ? true : false,
            'username' => $username,
        ]);
    }

    public function getGameUrl(VendorGameUrlParamsData $params): VendorGameUrlResultData
    {
        $language = $this->mapLanguageCode($params->language);

        $data = [
            'action' => 5,
            'ts' => round(microtime(true) * 1000),
            'parent' => $this->infoDetail->PARENT,
            'uid' => $this->account->username,
            'lang' => $language,
            'gType' => $params->gameCode,
            'windowMode' => '0',
            'lobbyURL' => '0',
            'backBtn' => '0',
            'mute' => '0',
        ];

        $response = $this->apiCall($data);
        $gameUrl = $response['path'] ?? '';

        return VendorGameUrlResultData::from([
            'pc' => $gameUrl,
            'mobile' => $gameUrl,
        ]);
    }

    /**
     * 遊戲清單（action 7；三方無類型欄位，一律 SLOTS）
     */
    public function getGameList(GetGameListParamsData $params): GetGameListResultData
    {
        $this->setVendorInfo($params->vendorInfo);

        $response = $this->apiCall([
            'action' => 7,
            'ts' => round(microtime(true) * 1000),
            'parent' => $this->infoDetail->PARENT,
            'lang' => 'en',
        ], 'Se_GameList.aspx', 'FUMA_SW_api_call_gameList');

        $data = [];
        foreach ($response['data'] ?? [] as $game) {
            $gameCode = (string) $game['gType'];
            $data[$gameCode] = GameInfoData::from([
                'gameCode' => $gameCode,
                'gameName' => $game['name'],
                'imageUrl' => $game['image'] ?? '',
                'gameType' => GameTypeEnum::SLOTS->value,
                'status' => 1,
                'info' => json_encode($game),
            ]);
        }

        return GetGameListResultData::from(['gameList' => $data]);
    }

    private function apiCall(array $data, string $endpoint = 'Se_token.aspx', string $action = 'FUMA_SW_api_call_get_game_url')
    {
        $dc = $this->infoDetail->DC;
        $key = $this->infoDetail->KEY;
        $iv = $this->infoDetail->IV;
        $apiUrl = $this->infoDetail->API_URL;

        // prepare action data
        $jsonString = json_encode($data);

        // encrypt data
        $encryptData = $this->encrypt($jsonString, $key, $iv);

        $data = [
            'dc' => $dc,
            'x' => $encryptData,
        ];

        $response = Http::logAction($action)
            ->asForm()
            ->post($apiUrl . '/' . $endpoint, $data)
            ->throw()
            ->json();

        if (empty($response)) {
            throw new Exception('VendorSend send response is empty');
        }

        if (empty($response['status']) || $response['status'] !== '0000') {
            // 三方業務性拒絕（維護中、帳號異常等），非系統故障：走 ApiException 讓 AlertGate 靜音不發 TG。
            throw new ApiException('VendorSend send response status is not 0000, error: ' . ($response['err_text'] ?? ''));
        }

        return $response;
    }

    private function encrypt(string $source, string $key, string $iv): string
    {
        $paddingChar = ' ';
        $size = 16;
        $x = strlen($source) % $size;
        $padLength = $size - $x;
        for ($i = 0; $i < $padLength; $i++) {
            $source .= $paddingChar;
        }

        $encryptData = openssl_encrypt($source, 'AES-128-CBC', $key, OPENSSL_NO_PADDING, $iv);
        $encryptData = base64_encode($encryptData);
        $encryptData = str_replace(['+', '/', '='], ['-', '_', ''], $encryptData);

        return $encryptData;
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
            LanguageEnum::ZH_TW->value => 'zhft',
            LanguageEnum::ZH_CN->value => 'zhft',
            LanguageEnum::ID_ID->value => 'id',
            LanguageEnum::VI_VN->value => 'vi',
            LanguageEnum::TH_TH->value => 'th',
            default => 'en',
        };
    }
}
