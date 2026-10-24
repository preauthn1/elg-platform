<?php

declare(strict_types=1);

namespace App\Vendors\PegasusSW;

use App\Data\GameInfoData;
use App\Data\Vendor\CreateAccountParamsData;
use App\Data\Vendor\CreateAccountResultData;
use App\Data\Vendor\GetGameListParamsData;
use App\Data\Vendor\GetGameListResultData;
use App\Data\VendorBonusCodeRequestData;
use App\Data\VendorBonusCodeResponseData;
use App\Data\VendorGameUrlParamsData;
use App\Data\VendorGameUrlResultData;
use App\Enums\Game\GameTypeEnum;
use App\Enums\LanguageEnum;
use App\Enums\Vendor\VendorChannelEnum;
use App\Exceptions\ApiException;
use App\Vendors\AbstractSingleWallet;
use App\Vendors\Contracts\BonusCodeInterface;
use App\Vendors\Contracts\HasAggregatorChannel;
use App\Vendors\Contracts\HasCallbackPath;
use App\Vendors\Contracts\ShouldScheduleAccountCreation;
use App\Vendors\Contracts\ShouldSyncGameList;
use App\Vendors\Models\PgsswAccount;
use App\Vendors\Models\PgsswBetRecord;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class Driver extends AbstractSingleWallet implements BonusCodeInterface, HasAggregatorChannel, HasCallbackPath, ShouldScheduleAccountCreation, ShouldSyncGameList
{
    /**
     * 三方 gameType → 站內 type_id
     *
     * 三方文件的類型定義：0 無分類／1000 老虎機／2000 棋牌／2500 視訊／
     * 3000 機台／4000 角鬥場／5000 彩票樂透／6000 柏青斯洛／7000 捕魚。
     * 機台、角鬥場、柏青斯洛站內皆歸 ARCADE。
     */
    private const array GAME_TYPE_MAP = [
        0 => GameTypeEnum::EVENT_AND_OTHERS,
        1000 => GameTypeEnum::SLOTS,
        2000 => GameTypeEnum::TABLE,
        2500 => GameTypeEnum::LIVE_CASINO,
        3000 => GameTypeEnum::ARCADE,
        4000 => GameTypeEnum::ARCADE,
        5000 => GameTypeEnum::LOTTERY,
        6000 => GameTypeEnum::ARCADE,
        7000 => GameTypeEnum::FISHING,
    ];

    protected string $accountModel = PgsswAccount::class;

    protected string $betRecordModel = PgsswBetRecord::class;

    /**
     * 接入通道：經由聚合商 PGSSW
     */
    public static function aggregatorChannel(): VendorChannelEnum
    {
        return VendorChannelEnum::PGSSW;
    }

    /**
     * 預設回調路徑後綴，對應 routes/external/sw.php 的 target 路由群組
     */
    public static function defaultCallbackPath(): string
    {
        return '/external/api/sw/target';
    }

    /**
     * 商戶配置透過 `$this->vendorInfo->info` 直接存取，無需額外解析。
     *
     * 必須覆寫：`setVendorInfo()` 會轉呼叫本方法，未覆寫時 base 實作會拋
     * `VendorSetupException`，使 `getGameList()` 這類以 `setVendorInfo()` 注入商戶的路徑無法運作。
     */
    public function setInfo(array $info): void
    {
        // 無需解析
    }

    public function getGameUrl(VendorGameUrlParamsData $params): VendorGameUrlResultData
    {
        $info = $this->vendorInfo->info;
        $api_url = $info['API_URL'];
        $app_id = $info['API_APPID'];
        $api_key = [REDACTED]'API_KEY'];
        $live_bet_limit = $info['LIVE_BET_LIMIT'] ?? null;
        $language = $this->getLanguage($params->language->value);
        $game_type = $params->gameCode;

        // 每次登入前更新session_token
        /** @var PgsswAccount $account */
        $account = $this->vendorAccount;
        $username = $account->username;
        $account->update(['session_token' => (string) Str::uuid()]);
        $session_token = [REDACTED];
        $api_url = rtrim($api_url, '/');
        $url = $api_url . '/game/launch-link';
        $params = [
            'playerUniqueID' => $username,
            'sessionToken' => $session_token,
            'merchantID' => $app_id,
            'merchantSecretKey' => $api_key,
            'lang' => $language,
            'GameID' => $game_type,
        ];

        if ($live_bet_limit !== null) {
            $params['liveCasinoLevelID'] = (string) $live_bet_limit;
        }

        // 三方業務錯誤（如 403911）以 4xx ＋ 完整 JSON envelope 回，交由下方 code 判斷拋 ApiException（AlertGate 靜音）；
        // 只有連 envelope 都沒有的傳輸／伺服器錯誤才拋 RequestException 發 TG 告警。
        $response = Http::logAction('PegasusSW_getGameUrl')
            ->get($url, $params)
            ->throwIf(fn (Response $response) => !isset($response->json()['code']))
            ->json();

        if (!is_array($response)) {
            throw new ApiException('Third party error: not json response');
        }

        if (($response['code'] ?? '') !== 0) {
            $code = $response['code'] ?? '';
            $error = $response['message'] ?? '';
            $error = 'Third party error: (' . $code . ') ' . $error;
            throw new ApiException($error);
        }
        $url = $response['data']['link'] ?? '';

        return VendorGameUrlResultData::from([
            'pc' => $url,
            'mobile' => $url,
        ]);
    }

    /**
     * 遊戲清單
     *
     * ⚠️ 本端點的密鑰參數用大寫開頭的 `MerchantSecretKey`。三方規格書通用約定寫的是
     * 小寫 `merchantSecretKey`，未對本端點另作說明；大寫是 RD-Studio 舊指令與同家族
     * `target\Driver` 的實測結果（兩者皆以大寫穩定運作），沿用實測值，勿逕自對齊文件。
     */
    public function getGameList(GetGameListParamsData $params): GetGameListResultData
    {
        $this->setVendorInfo($params->vendorInfo);

        $info = $this->vendorInfo->info;
        $url = rtrim($info['API_URL'], '/') . '/games';

        $response = Http::logAction('PegasusSW_getGameList')
            ->get($url, [
                'merchantID' => $info['API_APPID'],
                'MerchantSecretKey' => $info['API_KEY'],
            ])
            ->throw()
            ->json();

        // 錯誤處理分兩段，依三方規格書的 status code 定義（200 正常／400 參數與邏輯錯誤／500 伺服器錯誤）：
        // - HTTP 4xx、5xx：由上方 `throw()` 拋出。多屬商戶憑證或參數設定錯誤，要吵出來才會有人修。
        // - HTTP 200 但 code 非 0：回空清單並記 log。呼叫端（SyncVendorGameList）對空清單會提前
        //   return，不會讓差集比對把整批遊戲誤停用；聚合商多商戶下也不致每小時堆 failed_jobs。
        if (!is_array($response) || ($response['code'] ?? null) !== 0) {
            Log::error('[PegasusSW][GetGameList] Invalid response: code is not 0', [
                'vendor_id' => $this->vendor->id,
                'vendor_info_id' => $params->vendorInfo->id,
                'code' => $response['code'] ?? null,
                'message' => $response['message'] ?? null,
            ]);

            return GetGameListResultData::from(['gameList' => []]);
        }

        $gameList = [];
        foreach ($response['data'] ?? [] as $game) {
            // 缺 gameID 的列直接略過：以空字串當唯一鍵會與其他缺鍵列互相覆蓋，
            // 也會讓差集比對誤判而停用既有遊戲
            if (empty($game['gameID'])) {
                continue;
            }

            $gameCode = (string) $game['gameID'];

            $gameInfo = GameInfoData::from([
                'gameCode' => $gameCode,
                'gameName' => (string) ($game['gameName'] ?? ''),
                'imageUrl' => (string) ($game['imageUrl'] ?? ''),
                'gameType' => $this->convertGameType((int) ($game['gameType'] ?? 0)),
                'status' => 1,
                // 三方另有一組人類可讀代碼，與 gameID 並存，落 games.remark 供營運比對
                'remark' => isset($game['gameCode']) ? (string) $game['gameCode'] : null,
                'memo' => empty($game['isPrePaidMode']) ? null : 'isPrePaidMode',
            ]);

            foreach ($game['gameNameMappings'] ?? [] as $mapping) {
                $languageId = $this->convertLanguageCode($mapping['languageCode'] ?? '');
                if ($languageId !== null) {
                    $gameInfo->setGameNames($languageId, $mapping['text']);
                }
            }

            $gameList[$gameCode] = $gameInfo;
        }

        return GetGameListResultData::from(['gameList' => $gameList]);
    }

    public function getBonusCode(VendorBonusCodeRequestData $params): VendorBonusCodeResponseData
    {
        $info = $this->vendorInfo->info;
        $api_url = $info['API_URL'];
        $app_id = $info['API_APPID'];
        $api_key = [REDACTED]'API_KEY'];

        // 每次登入前更新session_token
        /** @var PgsswAccount $account */
        $account = $this->vendorAccount;
        $username = $account->username;
        $api_url = rtrim($api_url, '/');
        $url = $api_url . '/event/FreespinSpin/Promotion-Quota-Create';
        $params = [
            'players' => [$username],
            'rounds' => 10,
            'gameIDs' => [
                290089,
                290104,
                290108,
                290191,
                290196,
                290197,
                290202,
                290121,
                290103,
                290192,
            ],
            'merchantSecretKey' => $api_key,
            'merchantID' => $app_id,
            'isAutoCreateNonExistPlayer' => true,
        ];

        // 同 getGameUrl：帶 code envelope 的 4xx 屬三方業務錯誤，落到下方 code 判斷拋 ApiException，不發 TG。
        $response = Http::logAction('PegasusSW_getBonusCode')
            ->post($url, $params)
            ->throwIf(fn (Response $response) => !isset($response->json()['code']))
            ->json();

        if (!is_array($response)) {
            throw new ApiException('Third party error: not json response');
        }

        if (($response['code'] ?? '') !== 0) {
            $code = $response['code'] ?? '';
            $error = $response['message'] ?? '';
            $error = 'Third party error: (' . $code . ') ' . $error;
            throw new ApiException($error);
        }

        $bonusCode = (string) $response['data']['freespinID'];
        // 剩餘金額
        $remainAmount = $response['data']['remainAmount'];
        // 剩餘回合
        $remainRounds = $response['data']['remainRounds'];
        // 剩餘金額或回合不足代表已經沒有優惠了
        if ($remainAmount <= 0 || $remainRounds <= 0) {
            throw new ApiException('Third party error: promotion quota is not enough');
        }

        return VendorBonusCodeResponseData::from([
            'bonus_code' => $bonusCode,
        ]);
    }

    /**
     * 三方 gameType → 站內 type_id，未知類型歸「活動與其他」
     */
    private function convertGameType(int $gameType): int
    {
        return (self::GAME_TYPE_MAP[$gameType] ?? GameTypeEnum::EVENT_AND_OTHERS)->value;
    }

    /**
     * 三方語系代碼 → 站內 language_id，不支援的語系回 null（該筆名稱略過不寫）
     */
    private function convertLanguageCode(string $languageCode): ?int
    {
        return match ($languageCode) {
            'en-us' => LanguageEnum::EN->value,
            'zh-tw' => LanguageEnum::ZH_TW->value,
            'zh-cn' => LanguageEnum::ZH_CN->value,
            default => null,
        };
    }

    public function getLanguage(int $languageId): string
    {
        return match ($languageId) {
            LanguageEnum::ZH_TW->value => 'zh-cn',
            LanguageEnum::ZH_CN->value => 'zh-cn',
            default => 'en-us',
        };
    }

    /**
     * 建立我方遊戲商帳號（遷自 RD-Studio create_pgssw_account）
     *
     * 三方沒有註冊 API：玩家第一次 launch 時才會被建立，本方法只在我方 DB 建帳號列。
     * session_token 為 NOT NULL 無 default，實際值每次 getGameUrl 會重新產生。
     */
    public function createAccount(CreateAccountParamsData $params): CreateAccountResultData
    {
        $this->setVendorInfo($params->vendorInfo);

        $account = $this->accountModel::create([
            'vendor_info_id' => $params->vendorInfo->id,
            'username' => $params->username,
            'session_token' => (string) Str::uuid(),
            'admin' => 1,
        ]);

        return CreateAccountResultData::from([
            'status' => (bool) $account,
            'username' => $params->username,
        ]);
    }
}
