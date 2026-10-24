<?php

declare(strict_types=1);

namespace App\Vendors\EpicWinSW;

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
use App\Vendors\Models\EpicSwAccount;
use App\Vendors\Models\EpicSwBetRecord;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class Driver extends AbstractSingleWallet implements HasCallbackPath, ShouldScheduleAccountCreation, ShouldSyncGameList
{
    protected string $accountModel = EpicSwAccount::class;

    protected string $betRecordModel = EpicSwBetRecord::class;

    /**
     * 預設回調路徑後綴，對應 routes/external/sw.php 的 epic 路由群組
     */
    public static function defaultCallbackPath(): string
    {
        return '/external/api/sw/epic';
    }

    public function getGameUrl(VendorGameUrlParamsData $params): VendorGameUrlResultData
    {
        $info = $this->vendorInfo->info;
        $secretKey = $info['SECRET_KEY'];
        $username = $this->vendorAccount->username;
        $gameCode = $params->gameCode;
        $exitUrl = $params->exitUrl;
        $ip = $params->ip;
        $ApiUrl = $info['API_URL'];

        $sessionToken = [REDACTED]'-', '', Str::uuid()->toString());
        $this->vendorAccount->update(['session_token' => $sessionToken]);

        $funcName = 'GameLogin';
        $url = rtrim($ApiUrl, '/') . '/' . $funcName;
        $language = $this->getLanguage($params->language->value);

        $postData = [
            'OperatorId' => $info['OPERATOR_ID'],
            'RequestDateTime' => date('Y-m-d H:i:s'),
            'PlayerId' => $username,
            'Ip' => $ip,
            'RedirectUrl' => $exitUrl,
            'GameCode' => $gameCode,
            'Currency' => $info['CURRENCY'],
            'Lang' => $language,
            'AuthToken' => $sessionToken,
            'LaunchDemo' => false,
        ];
        $postData['Signature'] = $this->makeSignature($funcName, $postData, $secretKey);

        $response = Http::logAction('EpicSW_GameLogin')->post($url, $postData)->throw();
        $json = $response->json();

        if (!$json) {
            throw new ApiException('Third party error: not json response');
        }

        if (($json['Status'] ?? '') != 200) {
            $code = $json['Status'] ?? '';
            $error = $json['Description'] ?? '';
            $error = 'Third party error: (' . $code . ') ' . $error;
            throw new ApiException($error);
        }

        $url = $json['Url'];

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
     * 遊戲清單
     */
    public function getGameList(GetGameListParamsData $params): GetGameListResultData
    {
        $this->setVendorInfo($params->vendorInfo);

        $info = $this->vendorInfo->info;
        $funcName = 'GetGameList';
        $postData = [
            'OperatorId' => $info['OPERATOR_ID'],
            'RequestDateTime' => date('Y-m-d H:i:s'),
        ];
        $postData['Signature'] = $this->makeSignature($funcName, $postData, $info['SECRET_KEY']);

        $url = rtrim($info['API_URL'], '/') . '/' . $funcName;

        $response = Http::logAction('EpicSW_GetGameList')->post($url, $postData)->throw();
        $json = $response->json();

        if (!$json) {
            throw new ApiException('Third party error: not json response');
        }

        if (($json['Status'] ?? '') != 200) {
            $code = $json['Status'] ?? '';
            $error = $json['Description'] ?? ($json['Title'] ?? '');
            throw new ApiException('Third party error: (' . $code . ') ' . $error);
        }

        $langMap = [
            'zh-tw' => LanguageEnum::ZH_TW->value,
            'zh-cn' => LanguageEnum::ZH_CN->value,
        ];

        $data = [];
        foreach ($json['Game'] ?? [] as $game) {
            $gameCode = (string) $game['GameCode'];
            $gameInfo = GameInfoData::from([
                'gameCode' => $gameCode,
                'gameName' => $game['GameName'],
                'imageUrl' => $game['ImageUrl'] ?? '',
                'gameType' => $this->convertGameType((int) $game['GameType']),
                'status' => 1,
                'info' => json_encode($game),
            ]);

            // OtherName 格式為 "語系碼|名稱"
            foreach ($game['OtherName'] ?? [] as $otherName) {
                [$langCode, $name] = array_pad(explode('|', $otherName, 2), 2, '');
                $languageId = $langMap[trim($langCode)] ?? null;
                if ($languageId !== null && trim($name) !== '') {
                    $gameInfo->setGameNames($languageId, trim($name));
                }
            }

            $data[$gameCode] = $gameInfo;
        }

        return GetGameListResultData::from(['gameList' => $data]);
    }

    private function convertGameType(int $type): int
    {
        // EpicWin Game Type: 0=Others, 1=Slots, 2=Arcade, 3=Table, 4=Event, 5=Mini Game, 6=Fish Arcade, 101=Cash Bonus
        return match ($type) {
            1, 101 => GameTypeEnum::SLOTS->value,
            2 => GameTypeEnum::ARCADE->value,
            3 => GameTypeEnum::LIVE_CASINO->value,
            6 => GameTypeEnum::FISHING->value,
            default => GameTypeEnum::EVENT_AND_OTHERS->value,
        };
    }

    private function makeSignature(string $api, array $postData, string $secretKey): string
    {
        $FunctionName = $api;
        $RequestDateTime = $postData['RequestDateTime'];
        $OperatorId = $postData['OperatorId'];
        $SecurityKey = $secretKey;
        $playerId = $postData['PlayerId'] ?? '';

        return md5($FunctionName . $RequestDateTime . $OperatorId . $SecurityKey . $playerId);
    }

    private function getLanguage(int $languageId): string
    {
        // English en-us
        // Chinese zh-cn
        // Thai th-th
        // Burmese/Myanmar my-mm
        // Khmer km-kh
        // Indonesia id-id
        // Korean ko-kr
        // Chinese (Trad) zh-tw

        return match ($languageId) {
            LanguageEnum::EN->value => 'en-us',
            LanguageEnum::ZH_CN->value => 'zh-cn',
            LanguageEnum::ZH_TW->value => 'zh-tw',
            LanguageEnum::TH_TH->value => 'th-th',
            LanguageEnum::MY_MM->value => 'my-mm',
            LanguageEnum::ID_ID->value => 'id-id',
            default => 'en-us',
        };
    }

    /**
     * 建立我方遊戲商帳號（遷自 RD-Studio create_epic_sw_account）
     *
     * EpicWin 沒有註冊 API：帳號由三方在首次 GameLogin 時自動建立，本方法只寫本地表。
     * session_token 欄位 NOT NULL 無 default，需給初值；getGameUrl 每次登入都會覆寫它。
     */
    public function createAccount(CreateAccountParamsData $params): CreateAccountResultData
    {
        $account = $this->accountModel::create([
            'vendor_info_id' => $params->vendorInfo->id,
            'username' => $params->username,
            'session_token' => str_replace('-', '', Str::uuid()->toString()),
            'admin' => 1,
        ]);

        return CreateAccountResultData::from([
            'status' => (bool) $account,
            'username' => $params->username,
        ]);
    }
}
