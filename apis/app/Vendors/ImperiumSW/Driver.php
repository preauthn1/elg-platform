<?php

declare(strict_types=1);

namespace App\Vendors\ImperiumSW;

use App\Data\GameInfoData;
use App\Data\Vendor\GetGameListParamsData;
use App\Data\Vendor\GetGameListResultData;
use App\Data\VendorGameUrlParamsData;
use App\Data\VendorGameUrlResultData;
use App\Enums\Game\GameTypeEnum;
use App\Enums\LanguageEnum;
use App\Exceptions\ApiException;
use App\Vendors\AbstractSingleWallet;
use App\Vendors\Contracts\HasCallbackPath;
use App\Vendors\Contracts\ShouldSyncGameList;
use App\Vendors\Models\ImpswAccount;
use App\Vendors\Models\ImpswBetRecord;
use Illuminate\Support\Facades\Http;

class Driver extends AbstractSingleWallet implements HasCallbackPath, ShouldSyncGameList
{
    protected string $accountModel = ImpswAccount::class;

    protected string $betRecordModel = ImpswBetRecord::class;

    /**
     * 預設回調路徑後綴，對應 routes/external/sw.php 的 imperium 單一入口路由
     */
    public static function defaultCallbackPath(): string
    {
        return '/external/api/sw/imperium';
    }

    public function getGameUrl(VendorGameUrlParamsData $params): VendorGameUrlResultData
    {
        $gameCode = $params->gameCode;
        $language = $this->getLanguage($params->language->value);
        $exitUrl = $params->exitUrl;
        $postData = [
            'cmd' => 'openGame',
            'hall' => $this->vendorInfo->info['hallId'],
            'key' => $this->vendorInfo->info['hallKey'],
            'domain' => $exitUrl,
            'language' => $language,
            'login' => $this->vendorAccount->username,
            'gameId' => $gameCode,
            'demo' => 0,
            'exitUrl' => $exitUrl,
        ];

        $action = 'IMPSW_api_call_getGameUrl';

        $url = $this->vendorInfo->info['apiUrl'] . '/API/openGame/';
        $response = Http::logAction($action)->post($url, $postData)->throw();
        $json = $response->json();

        if (!$json) {
            throw new ApiException('Third party error: not json response');
        }

        if (($json['status'] ?? '') != 'success') {
            throw new ApiException('Third party error: ' . ($json['error'] ?? ''));
        }

        $url = $json['content']['game']['url'] ?? '';

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
     * 遊戲清單（回應 content 依遊戲提供商分組，需攤平；三方無類型欄位，一律 SLOTS）
     */
    public function getGameList(GetGameListParamsData $params): GetGameListResultData
    {
        $this->setVendorInfo($params->vendorInfo);

        $postData = [
            'hall' => $this->vendorInfo->info['hallId'],
            'key' => $this->vendorInfo->info['hallKey'],
            'cmd' => 'getGamesList',
            'img' => 'game_img_6',
        ];

        $url = $this->vendorInfo->info['apiUrl'] . '/API/';
        $response = Http::logAction('IMPSW_api_call_getGameList')->post($url, $postData)->throw();
        $json = $response->json();

        if (!$json) {
            throw new ApiException('Third party error: not json response');
        }

        $data = [];
        foreach ($json['content'] ?? [] as $items) {
            foreach ($items as $game) {
                $gameCode = (string) $game['id'];
                $data[$gameCode] = GameInfoData::from([
                    'gameCode' => $gameCode,
                    'gameName' => $game['name'],
                    'imageUrl' => $game['img'] ?? '',
                    'gameType' => GameTypeEnum::SLOTS->value,
                    'status' => 1,
                    'info' => json_encode($game),
                ]);
            }
        }

        return GetGameListResultData::from(['gameList' => $data]);
    }

    public function getLanguage(int $languageId): string
    {
        return match ($languageId) {
            LanguageEnum::EN->value => 'en',
            LanguageEnum::ZH_TW->value => 'zh',
            LanguageEnum::ZH_CN->value => 'zh',
            default => 'en',
        };
    }
}
