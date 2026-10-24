<?php

declare(strict_types=1);

namespace App\Vendors\AcewinSW;

use App\Data\GameInfoData;
use App\Data\Vendor\CreateAccountParamsData;
use App\Data\Vendor\CreateAccountResultData;
use App\Data\Vendor\GetGameListParamsData;
use App\Data\Vendor\GetGameListResultData;
use App\Data\VendorGameUrlParamsData;
use App\Data\VendorGameUrlResultData;
use App\Enums\Game\GameTypeEnum;
use App\Enums\LanguageEnum;
use App\Exceptions\ApiException;
use App\Vendors\AbstractSingleWallet;
use App\Vendors\Contracts\HasCallbackPath;
use App\Vendors\Contracts\ShouldScheduleAccountCreation;
use App\Vendors\Contracts\ShouldSyncGameList;
use App\Vendors\Models\AcewinAccount;
use App\Vendors\Models\AcewinBetRecord;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class Driver extends AbstractSingleWallet implements HasCallbackPath, ShouldScheduleAccountCreation, ShouldSyncGameList
{
    protected string $accountModel = AcewinAccount::class;

    protected string $betRecordModel = AcewinBetRecord::class;

    protected AcewinAccount $account;

    /**
     * 預設回調路徑後綴，對應 routes/external/sw.php 的 acewin 路由群組
     */
    public static function defaultCallbackPath(): string
    {
        return '/external/api/sw/acewin';
    }

    public function getGameUrl(VendorGameUrlParamsData $params): VendorGameUrlResultData
    {
        // 不需要 gamecode, 直接進大廳
        $this->account = $this->vendorAccount;
        $gameCode = $params->gameCode;
        $info = $this->vendorInfo->info;
        $language = $this->getLanguage($params->language->value);
        $session_token = [REDACTED];

        $this->account->session_token = [REDACTED];
        $this->account->save();

        $params = [
            'Token' => $session_token,
            'GameId' => $gameCode,
            'Lang' => $language,
            'AgentId' => $info['CLIENT_ID'],
        ];

        $params['Key'] = $this->getSignature($info['CLIENT_ID'], $info['CLIENT_SECRET'], $params);
        $url = $info['AUTH_URL'] . '/singleWallet/LoginWithoutRedirect';
        $response = Http::logAction('AcewinSW_getGameUrl')
            ->get($url, $params)
            ->throw();
        $json = $response->json();

        if (!$json) {
            throw new ApiException('Third party error: not json response');
        }

        if ($json['ErrorCode'] != 0) {
            throw new ApiException('Third party error: ' . ($json['Message'] ?? 'Unknown error'));
        }

        $url = $json['Data'];

        return VendorGameUrlResultData::from([
            'pc' => $url,
            'mobile' => $url,
        ]);
    }

    public function setInfo(array $info): void
    {
        // info 透過 $this->vendorInfo->info 直接存取，無需額外解析
    }

    /**
     * 遊戲清單（遊戲名稱以語系碼為 key 掛在 name 底下；
     * 圖檔照舊系統走 GCS 共用圖庫，bucket 路徑為多白標共用的 vworld 硬編碼）
     */
    public function getGameList(GetGameListParamsData $params): GetGameListResultData
    {
        $this->setVendorInfo($params->vendorInfo);

        $info = $this->vendorInfo->info;
        $queryParams = [
            'AgentId' => $info['CLIENT_ID'],
        ];
        $queryParams['Key'] = $this->getSignature($info['CLIENT_ID'], $info['CLIENT_SECRET'], $queryParams);

        $url = $info['AUTH_URL'] . '/api1/GetGameList';
        $response = Http::logAction('AcewinSW_getGameList')
            ->get($url, $queryParams)
            ->throw();
        $json = $response->json();

        if (!$json) {
            throw new ApiException('Third party error: not json response');
        }

        if (($json['ErrorCode'] ?? -1) != 0) {
            throw new ApiException('Third party error: ' . ($json['Message'] ?? 'Unknown error'));
        }

        // GameCategoryId 混用字串與數字型別
        $typeMap = [
            'live' => GameTypeEnum::LIVE_CASINO->value,
            1 => GameTypeEnum::SLOTS->value,
            'table' => GameTypeEnum::POKER->value,
            'lottery' => GameTypeEnum::LOTTERY->value,
            5 => GameTypeEnum::FISHING->value,
            'cockfighting' => GameTypeEnum::COKE_FIGHTING->value,
            'arcade' => GameTypeEnum::ARCADE->value,
            'sports' => GameTypeEnum::SPORTS->value,
            'runlight' => GameTypeEnum::RUN_LIGHT->value,
            3 => GameTypeEnum::REWARD->value, // 任務
        ];

        // 三方無繁中，繁中沿用簡中譯名
        $langMap = [
            'en-US' => [LanguageEnum::EN->value],
            'zh-CN' => [LanguageEnum::ZH_TW->value, LanguageEnum::ZH_CN->value],
            'ms-MY' => [LanguageEnum::MS_MY->value],
            'id-ID' => [LanguageEnum::ID_ID->value],
            'vi-VN' => [LanguageEnum::VI_VN->value],
            'th-TH' => [LanguageEnum::TH_TH->value],
            'my-MM' => [LanguageEnum::MY_MM->value],
        ];

        $data = [];
        foreach ($json['Data'] ?? [] as $game) {
            $gameCode = (string) $game['GameId'];
            $names = $game['name'] ?? [];
            $gameInfo = GameInfoData::from([
                'gameCode' => $gameCode,
                'gameName' => $names['en-US'] ?? $gameCode,
                'imageUrl' => 'https://example.invalid/cdn-betmonsters-com-cdn-bucket-online/vworld/game/acewin/en-US/' . $gameCode . '.png',
                'gameType' => $typeMap[$game['GameCategoryId'] ?? ''] ?? GameTypeEnum::EVENT_AND_OTHERS->value,
                'status' => 1,
                'info' => json_encode($game),
            ]);

            foreach ($langMap as $langCode => $languageIds) {
                if (empty($names[$langCode])) {
                    continue;
                }
                foreach ($languageIds as $languageId) {
                    $gameInfo->setGameNames($languageId, $names[$langCode]);
                }
            }

            $data[$gameCode] = $gameInfo;
        }

        return GetGameListResultData::from(['gameList' => $data]);
    }

    private function genToken(): string
    {
        return str_replace('-', '', Str::uuid()->toString()) . bin2hex(random_bytes(32));
    }

    /**
     * -取得遊戲商數據簽章
     *
     * @param  string  $agentId
     * @param  string  $agentKey
     * @param  array  $params
     * @return string
     */
    private function getSignature($agentId, $agentKey, $params)
    {
        $now = Carbon::now()->timezone('-4')->format('ymj');
        $keyG = md5($now . $agentId . $agentKey);
        $paramString = http_build_query($params);
        $key = Str::random(6) . md5($paramString . $keyG) . Str::random(6);

        return $key;
    }

    private function getLanguage(int $languageId): string
    {
        // 簡中 zh-cn
        // 英語 en-us
        // 泰文 th-th
        // 越南 vi-vn
        // 馬來 ms-my
        return match ($languageId) {
            LanguageEnum::EN->value => 'en-us',
            LanguageEnum::ZH_TW->value => 'zh-cn',
            LanguageEnum::ZH_CN->value => 'zh-cn',
            LanguageEnum::TH_TH->value => 'th-th',
            LanguageEnum::MS_MY->value => 'ms-my',
            LanguageEnum::VI_VN->value => 'vi-vn',
            default => 'en-us',
        };
    }

    /**
     * 建立我方遊戲商帳號（遷自 RD-Studio create_acewin_account）
     *
     * 三方無建號 API（舊系統即為「內部建立」），只寫我方帳號表；
     * 登入用的 session_token 由 getGameUrl 每次重新產生，此處僅給初值。
     */
    public function createAccount(CreateAccountParamsData $params): CreateAccountResultData
    {
        // 本 driver 的 setInfo 為 no-op，info 一律走 $this->vendorInfo->info
        $this->setVendorInfo($params->vendorInfo);
        $username = $params->username;

        $account = $this->accountModel::create([
            'vendor_info_id' => $params->vendorInfo->id,
            'username' => $username,
            'vendor_username' => $username . ($this->vendorInfo->info['CLIENT_CLASS'] ?? ''),
            'session_token' => $this->genToken(),
            'admin' => 1,
        ]);

        return CreateAccountResultData::from([
            'status' => (bool) $account,
            'username' => $username,
        ]);
    }
}
