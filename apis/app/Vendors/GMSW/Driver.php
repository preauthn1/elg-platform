<?php

declare(strict_types=1);

namespace App\Vendors\GMSW;

use App\Data\GameInfoData;
use App\Data\Vendor\CreateAccountParamsData;
use App\Data\Vendor\CreateAccountResultData;
use App\Data\Vendor\GetGameListParamsData;
use App\Data\Vendor\GetGameListResultData;
use App\Data\VendorGameUrlParamsData;
use App\Data\VendorGameUrlResultData;
use App\Enums\Game\GameTypeEnum;
use App\Enums\LanguageEnum;
use App\Enums\Vendor\VendorChannelEnum;
use App\Enums\Vendor\VendorIdEnum;
use App\Exceptions\ApiException;
use App\Models\Member;
use App\Vendors\AbstractSingleWallet;
use App\Vendors\Contracts\HasAggregatorChannel;
use App\Vendors\Contracts\HasCallbackPath;
use App\Vendors\Contracts\ShouldScheduleAccountCreation;
use App\Vendors\Contracts\ShouldSyncGameList;
use App\Vendors\Models\GMSWAccount;
use App\Vendors\Models\GMSWBetRecord;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Spatie\LaravelData\Data;

class Driver extends AbstractSingleWallet implements HasAggregatorChannel, HasCallbackPath, ShouldScheduleAccountCreation, ShouldSyncGameList
{
    protected string $accountModel = GMSWAccount::class;

    protected string $betRecordModel = GMSWBetRecord::class;

    /**
     * vendor_id → GM API platform 整數
     *
     * @var array<int, int>
     */
    public const PLATFORM_VENDOR_MAP = [
        VendorIdEnum::GMSW_PG->value => 2,
        VendorIdEnum::GMSW_JILI->value => 3,
        VendorIdEnum::GMSW_PP->value => 4,
        VendorIdEnum::GMSW_OMG->value => 5,
        VendorIdEnum::GMSW_MINIGAME->value => 6,
        VendorIdEnum::GMSW_HACKSAW->value => 8,
        VendorIdEnum::GMSW_TADA->value => 23,
        VendorIdEnum::GMSW_CP->value => 24,
        VendorIdEnum::GMSW_ASKME->value => 25,
        VendorIdEnum::GMSW_JDB->value => 28,
    ];

    private VendorInfoDetailData $infoDetail;

    public function __construct(int $vendorId, ?Member $member)
    {
        parent::__construct($vendorId, $member);

        if (!$member) {
            return;
        }

        $this->infoDetail = VendorInfoDetailData::from($this->vendorInfo->info);
    }

    /**
     * 接入通道：經由聚合商 GMSW
     */
    public static function aggregatorChannel(): VendorChannelEnum
    {
        return VendorChannelEnum::GMSW;
    }

    /**
     * 預設回調路徑後綴，對應 routes/external/sw.php 的 gm 路由群組
     */
    public static function defaultCallbackPath(): string
    {
        return '/external/api/sw/gm';
    }

    public function createAccount(CreateAccountParamsData $params): CreateAccountResultData
    {
        $account = $this->accountModel::firstOrCreate(
            ['username' => $params->username],
            ['vendor_info_id' => $params->vendorInfo->id],
        );

        return CreateAccountResultData::from([
            'status' => (bool) $account,
            'username' => $params->username,
        ]);
    }

    public function getGameUrl(VendorGameUrlParamsData $params): VendorGameUrlResultData
    {
        $sessionToken = [REDACTED];
        $this->vendorAccount->update(['session_token' => $sessionToken]);

        $lang = $this->getLanguage($params->language->value);
        $nick = $this->vendorAccount->username;
        $gameId = $params->gameCode;

        $body = [
            'gameid' => $gameId,
            'token' => $sessionToken,
            'lang' => $lang,
            'nick' => $nick,
            'app_id' => $this->infoDetail->APP_ID,
        ];

        if ($params->exitUrl) {
            $body['back_url'] = $params->exitUrl;
        }

        $traceId = Str::random(16);
        $bodyJson = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $sign = md5("trace_id={$traceId}" . $bodyJson . $this->infoDetail->KEY);

        $response = Http::logAction('GMSW_getGameUrl')
            ->withHeader('sign', $sign)
            ->withBody($bodyJson, 'application/json')
            ->post($this->infoDetail->API_URL . '/api/usr/ingame?trace_id=' . $traceId)
            ->throw()
            ->json();

        if (($response['code'] ?? null) !== 0) {
            throw new ApiException('[GMSW] getGameUrl failed: ' . ($response['msg'] ?? 'unknown error'));
        }

        $gameUrl = $response['data']['gameurl'] ?? '';

        return VendorGameUrlResultData::from([
            'pc' => $gameUrl,
            'mobile' => $gameUrl,
        ]);
    }

    public function getGameList(GetGameListParamsData $params): GetGameListResultData
    {
        $this->infoDetail = VendorInfoDetailData::from($params->vendorInfo->info);

        $body = ['app_id' => $this->infoDetail->APP_ID];
        $traceId = Str::random(16);
        $bodyJson = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $sign = md5("trace_id={$traceId}" . $bodyJson . $this->infoDetail->KEY);

        $response = Http::logAction('GMSW_getGameList')
            ->withHeader('sign', $sign)
            ->withBody($bodyJson, 'application/json')
            ->post($this->infoDetail->API_URL . '/api/game/loadlist?trace_id=' . $traceId)
            ->throw()
            ->json();

        if (($response['code'] ?? null) !== 0) {
            return GetGameListResultData::from(['gameList' => []]);
        }

        $gameTypeMap = [
            1 => GameTypeEnum::SLOTS->value,
            2 => GameTypeEnum::LIVE_CASINO->value,
            3 => GameTypeEnum::FISHING->value,
            4 => GameTypeEnum::ARCADE->value,
        ];

        $targetPlatform = self::PLATFORM_VENDOR_MAP[$params->vendorInfo->vendor_id] ?? 0;

        $gameList = [];
        foreach ($response['data']['glist'] ?? [] as $game) {
            if ($targetPlatform !== 0 && (int) ($game['platform'] ?? -1) !== $targetPlatform) {
                continue;
            }

            $gameCode = (string) $game['gameid'];
            $gameList[$gameCode] = GameInfoData::from([
                'gameCode' => $gameCode,
                'gameName' => $game['name'],
                'imageUrl' => '',
                'gameType' => $gameTypeMap[(int) ($game['gametype'] ?? 1)] ?? GameTypeEnum::SLOTS->value,
                'status' => ((int) ($game['status'] ?? 1)) === 1 ? 1 : 0,
                'info' => json_encode(['platform' => $game['platform'] ?? '']),
            ]);
        }

        return GetGameListResultData::from(['gameList' => $gameList]);
    }

    private function getLanguage(int $languageId): string
    {
        return match ($languageId) {
            LanguageEnum::ZH_CN->value => 'zh',
            LanguageEnum::ZH_TW->value => 'zh',
            LanguageEnum::TH_TH->value => 'th',
            LanguageEnum::ID_ID->value => 'id',
            LanguageEnum::VI_VN->value => 'vi',
            LanguageEnum::MY_MM->value => 'my',
            default => 'en',
        };
    }
}

class VendorInfoDetailData extends Data
{
    public function __construct(
        public string $API_URL,
        public string $APP_ID,
        public string $KEY,
    ) {}
}
