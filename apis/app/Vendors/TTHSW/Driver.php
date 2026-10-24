<?php

declare(strict_types=1);

namespace App\Vendors\TTHSW;

use App\Data\GameInfoData;
use App\Data\Vendor\CreateAccountParamsData;
use App\Data\Vendor\CreateAccountResultData;
use App\Data\Vendor\GetGameListParamsData;
use App\Data\Vendor\GetGameListResultData;
use App\Data\VendorGameUrlParamsData;
use App\Data\VendorGameUrlResultData;
use App\Enums\Game\GameTypeEnum;
use App\Enums\General\DeviceTypeEnum;
use App\Enums\LanguageEnum;
use App\Enums\Vendor\VendorChannelEnum;
use App\Exceptions\ApiException;
use App\Models\Member;
use App\Vendors\AbstractSingleWallet;
use App\Vendors\Contracts\HasAggregatorChannel;
use App\Vendors\Contracts\HasCallbackPath;
use App\Vendors\Contracts\ShouldScheduleAccountCreation;
use App\Vendors\Contracts\ShouldSyncGameList;
use App\Vendors\Contracts\WidgetGameInterface;
use App\Vendors\Models\TTHSWAccount;
use App\Vendors\Models\TTHSWBetRecord;
use App\Vendors\TTHSW\Helpers\SignatureHelper;
use Exception;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * @property TTHSWAccount $vendorAccount
 */
class Driver extends AbstractSingleWallet implements HasAggregatorChannel, HasCallbackPath, ShouldScheduleAccountCreation, ShouldSyncGameList, WidgetGameInterface
{
    /**
     * 1.5 Widget 的 WidgetType：1 EMBED、2 REDIRECT_SINGLE、3 REDIRECT_MATCHES。
     * 各子遊戲商有沒有 widget、給哪一種由三方決定；目前只接得住回單一網址的 1、2。
     */
    private const WIDGET_TYPE_EMBED = 1;

    private const WIDGET_TYPE_REDIRECT_SINGLE = 2;

    /**
     * TTH 遊戲類型（API 文件 Game Types 附錄）→ 平台遊戲類型
     */
    private const GAME_TYPE_MAP = [
        1 => GameTypeEnum::SLOTS->value,          // Slot
        2 => GameTypeEnum::LIVE_CASINO->value,    // Live Casino
        3 => GameTypeEnum::SPORTS->value,         // Sportsbook
        4 => GameTypeEnum::ARCADE->value,         // Instant Win
        5 => GameTypeEnum::LOTTERY->value,        // Keno
        6 => GameTypeEnum::FISHING->value,        // Fishing
        7 => GameTypeEnum::COKE_FIGHTING->value,  // Cockfight
        8 => GameTypeEnum::ARCADE->value,         // Marble
        9 => GameTypeEnum::TABLE->value,          // Virtual Casino
        10 => GameTypeEnum::LOTTERY->value,       // Lottery
        13 => GameTypeEnum::SLOTS->value,         // 文件外代碼，三方曾以 13 回傳 Slot
        99 => GameTypeEnum::ARCADE->value,        // Other
    ];

    protected string $accountModel = TTHSWAccount::class;

    protected string $betRecordModel = TTHSWBetRecord::class;

    private string $apiUrl;

    private string $apiKey;

    private string $siteCode;

    private ?string $provider = null;

    public function __construct(int $vendorId, ?Member $member)
    {
        parent::__construct($vendorId, $member);

        if (!$member) {
            return;
        }

        $this->setInfo($this->vendorInfo->info);
    }

    /**
     * 接入通道：經由聚合商 EliteHub（代碼中的 TTHSW）
     */
    public static function aggregatorChannel(): VendorChannelEnum
    {
        return VendorChannelEnum::ELITE_HUB;
    }

    /**
     * 預設回調路徑後綴，對應 routes/external/sw.php 的 tthsw 路由群組
     */
    public static function defaultCallbackPath(): string
    {
        return '/external/api/sw/tthsw';
    }

    public function setInfo(array $info): void
    {
        $this->apiUrl = $info['API_URL'] ?? '';
        $this->apiKey = [REDACTED]'API_KEY'] ?? '';
        $this->siteCode = $info['SITE_CODE'] ?? '';
        $this->provider = $info['PROVIDER'] ?? null;
    }

    /**
     * 登入遊戲網址
     */
    public function getGameUrl(VendorGameUrlParamsData $params): VendorGameUrlResultData
    {
        $authToken = [REDACTED]; // Start game auth token set to max length of 20 alphanumeric only
        $this->vendorAccount->update(['auth_token' => $authToken]);

        $url = $this->apiUrl . '/api/sm/services/start';

        $data = [
            'SiteCode' => $this->siteCode,
            'GameCode' => $params->gameCode,
            'AuthToken' => $authToken,
            'Platform' => $this->mapPlatform($params->deviceType),
            'Demo' => false,
            'Lang' => $this->mapLanguageCode($params->language),
            'Orientation' => $this->mapOrientation($params->deviceType),
        ];

        if ($params->exitUrl) {
            $data['BackUrl'] = $params->exitUrl;
        }

        $timestamp = (int) (microtime(true) * 1000);
        $signature = SignatureHelper::generateSignature($data, $this->apiKey, $timestamp);

        $response = Http::logAction('TTHSW_getGameUrl', $data)
            ->withHeaders([
                'X-Sign' => $signature,
                'X-Ts' => (string) $timestamp,
                'Content-Type' => 'application/json',
            ])
            ->post($url, $data)
            ->throw()
            ->json();

        if (!is_array($response)) {
            throw new ApiException('Invalid response format');
        }

        // 檢查回應
        if (!isset($response['Code']) || $response['Code'] !== 0) {
            $message = $response['Message'] ?? 'Unknown error';
            throw new ApiException("Failed to get game URL: {$message}");
        }

        if (!isset($response['Value'])) {
            throw new ApiException('Game URL not found in response');
        }

        $gameUrl = $response['Value'];

        return VendorGameUrlResultData::from([
            'pc' => $gameUrl,
            'mobile' => $gameUrl,
        ]);
    }

    /**
     * 1.5 Widget 網址
     */
    public function getWidgetUrl(VendorGameUrlParamsData $params): VendorGameUrlResultData
    {
        $authToken = [REDACTED];
        $this->vendorAccount->update(['auth_token' => $authToken]);

        $gameInfo = json_decode($params->info ?? '', true);

        $data = [
            'SiteCode' => $this->siteCode,
            'ProviderCode' => $this->provider,
            'AuthToken' => $authToken,
            'GameType' => (int) ($gameInfo['Type'] ?? 3),
            'Platform' => $this->mapPlatform($params->deviceType),
            'Lang' => $this->mapLanguageCode($params->language),
            'Domain' => parse_url((string) $params->exitUrl, PHP_URL_HOST) ?: (string) $params->exitUrl,
        ];

        $timestamp = (int) (microtime(true) * 1000);
        $signature = SignatureHelper::generateSignature($data, $this->apiKey, $timestamp);

        $response = Http::logAction('TTHSW_getWidgetUrl', $data)
            ->withHeaders([
                'X-Sign' => $signature,
                'X-Ts' => (string) $timestamp,
                'Content-Type' => 'application/json',
            ])
            ->post($this->apiUrl . '/api/sm/services/widget', $data)
            ->throw()
            ->json();

        if (!is_array($response) || ($response['Code'] ?? null) !== 0) {
            $message = is_array($response) ? ($response['Message'] ?? 'Unknown error') : 'Invalid response format';
            throw new ApiException("Failed to get widget URL: {$message}");
        }

        $value = $response['Value'] ?? [];
        $url = match ($value['WidgetType'] ?? null) {
            self::WIDGET_TYPE_EMBED => $value['EmbedScriptUrl'] ?? null,
            self::WIDGET_TYPE_REDIRECT_SINGLE => $value['RedirectUrl'] ?? null,
            default => null,
        };

        if (empty($url)) {
            throw new ApiException('Widget type not supported: ' . ($value['WidgetType'] ?? 'unknown'));
        }

        return VendorGameUrlResultData::from([
            'pc' => $url,
            'mobile' => $url,
        ]);
    }

    /**
     * 創建遊戲帳號
     */
    public function createAccount(CreateAccountParamsData $params): CreateAccountResultData
    {
        // 注入遊戲商戶資料
        $vendorInfo = $params->vendorInfo;
        $this->setInfo($vendorInfo->info);

        // 取目前帳號最大值
        $latestNum = (string) $params->serialNumber;
        $prefix = $vendorInfo->account_prefix;
        $username = $prefix . str_pad((string) ($latestNum + 1), 8, '0', STR_PAD_LEFT);

        // 建立我方玩家帳號
        $data = [
            'vendor_info_id' => $vendorInfo->id,
            'username' => $username,
            'status' => 1,
        ];

        $account = $this->accountModel::create($data);

        if (!$account) {
            return CreateAccountResultData::from([
                'status' => false,
                'username' => $username,
            ]);
        }

        return CreateAccountResultData::from([
            'status' => true,
            'username' => $username,
        ]);
    }

    /**
     * 取得遊戲清單
     */
    public function getGameList(GetGameListParamsData $params): GetGameListResultData
    {
        $vendorInfo = $params->vendorInfo;
        $this->setInfo($vendorInfo->info);

        $games = $this->fetchGames();

        if (empty($games)) {
            return GetGameListResultData::from(['gameList' => []]);
        }

        $gameList = [];
        $unknownTypes = [];
        foreach ($games as $gameData) {
            $gameCode = $gameData['Code'] ?? '';
            $gameName = $gameData['Name'] ?? '';
            $rawType = (int) ($gameData['Type'] ?? 0);
            $gameType = $this->mapGameType($rawType);
            $imageUrl = $this->extractImageUrl($gameData['Thumbnails'] ?? []);
            $status = 1;

            if (!isset(self::GAME_TYPE_MAP[$rawType])) {
                $unknownTypes[$rawType] = [
                    'type' => $rawType,
                    'category' => $gameData['Category'] ?? null,
                    'game_code' => $gameCode,
                ];
            }

            $gameInfo = GameInfoData::from([
                'gameCode' => $gameCode,
                'gameName' => $gameName,
                'gameType' => $gameType,
                'imageUrl' => $imageUrl,
                'status' => $status,
                'info' => json_encode($gameData),
            ]);

            foreach ($gameData['NameLocalizations'] ?? [] as $localization) {
                $langCode = $localization['Language'] ?? '';
                $localizedName = $localization['Name'] ?? '';
                if ($langCode && $localizedName) {
                    $gameInfo->setGameNames($this->mapGameLanguageId($langCode), $localizedName);
                }
            }

            $gameList[] = $gameInfo;
        }

        if (!empty($unknownTypes)) {
            Log::warning('TTHSW 遊戲清單出現對應表外的遊戲類型', [
                'vendor_info_id' => $vendorInfo->id,
                'unknown_types' => array_values($unknownTypes),
            ]);
        }

        return GetGameListResultData::from(['gameList' => $gameList]);

    }

    /**
     * 對應平台類型
     */
    private function mapPlatform(?DeviceTypeEnum $deviceType): int
    {
        return match ($deviceType) {
            DeviceTypeEnum::MOBILE => 2,   // Mobile
            default => 1,                  // Web
        };
    }

    /**
     * 對應語言ID
     */
    private function mapLanguageCode(?LanguageEnum $language): int
    {
        if (empty($language)) {
            return 1; // English (default)
        }

        return match ($language) {
            LanguageEnum::EN => 1,      // English
            LanguageEnum::ZH_TW => 3,   // Traditional Chinese
            LanguageEnum::ZH_CN => 2,   // Simplified Chinese
            LanguageEnum::MS_MY => 27,  // Malay
            LanguageEnum::ID_ID => 71,  // Indonesian
            LanguageEnum::VI_VN => 174, // Vietnamese
            LanguageEnum::TH_TH => 160, // Thai
            default => 1,               // Default to English
        };
    }

    private function mapOrientation(?DeviceTypeEnum $deviceType): int
    {
        return match ($deviceType) {
            DeviceTypeEnum::MOBILE => 2,   // Vertical
            default => 1,                  // Horizontal
        };
    }

    /**
     * 對應遊戲類型
     *
     * 對應表外的 Type 一律歸 ARCADE，避免存入無效的 type_id 0。
     */
    private function mapGameType(int $type): int
    {
        return self::GAME_TYPE_MAP[$type] ?? GameTypeEnum::ARCADE->value;
    }

    /**
     * 對應遊戲語言 ID
     */
    private function mapGameLanguageId(string $langCode): int
    {
        $langCode = strtolower($langCode);

        return match ($langCode) {
            'en' => LanguageEnum::EN->value,
            'zh-tw' => LanguageEnum::ZH_TW->value,
            'zhtw' => LanguageEnum::ZH_TW->value,
            'zh' => LanguageEnum::ZH_CN->value,
            'my' => LanguageEnum::MS_MY->value,
            'id' => LanguageEnum::ID_ID->value,
            'vi' => LanguageEnum::VI_VN->value,
            'th' => LanguageEnum::TH_TH->value,

            default => LanguageEnum::EN->value,
        };
    }

    /**
     * 提取圖片 URL
     */
    private function extractImageUrl(array $thumbnails): string
    {
        if (is_array($thumbnails) && count($thumbnails) > 0) {
            return $thumbnails[0]['ImagePath'] ?? '';
        }

        return '';
    }

    /**
     * 獲取遊戲清單
     */
    private function fetchGames(): array
    {
        $pageSize = 100;
        $pageNo = 1;
        $allGames = [];
        $totalGames = 0;

        while (true) {
            $data = [
                'SiteCode' => $this->siteCode,
                'PageNo' => $pageNo,
                'PageSize' => $pageSize,
            ];

            if ($this->provider !== null) {
                $data['ProviderCodes'] = [$this->provider];
            }

            $timestamp = (int) (microtime(true) * 1000);
            $signature = SignatureHelper::generateSignature($data, $this->apiKey, $timestamp);

            $response = Http::logAction('TTHSW_getGameList', $data)
                ->withHeaders([
                    'X-Sign' => $signature,
                    'X-Ts' => (string) $timestamp,
                    'Content-Type' => 'application/json',
                ])
                ->post($this->apiUrl . '/api/sm/services/games', $data)
                ->throw()
                ->json();

            if (!is_array($response)) {
                throw new Exception('Invalid response format');
            }

            if (isset($response['Code']) && $response['Code'] !== 0) {
                $message = $response['Message'] ?? 'Unknown error';
                // 三方業務性拒絕，非系統故障：走 ApiException 讓 AlertGate 靜音不發 TG。
                throw new ApiException("API returned error: {$message}");
            }

            $value = $response['Value'] ?? [];
            $games = $value['Games'] ?? [];
            $totalGames = $value['Total'] ?? 0;

            if (empty($games)) {
                break;
            }

            $allGames = array_merge($allGames, $games);

            if (count($allGames) >= $totalGames) {
                break;
            }

            $pageNo++;
        }

        $allGames = collect($allGames)->unique('Code')->values()->all();

        return $allGames;
    }
}
