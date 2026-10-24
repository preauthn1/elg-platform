<?php

declare(strict_types=1);

namespace App\Vendors\MegaH5SW;

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
use App\Vendors\Models\MegaH5SWAccount;
use App\Vendors\Models\MegaH5SWBetRecord;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class Driver extends AbstractSingleWallet implements HasCallbackPath, ShouldScheduleAccountCreation, ShouldSyncGameList
{
    protected string $accountModel = MegaH5SWAccount::class;

    protected string $betRecordModel = MegaH5SWBetRecord::class;

    protected string $actionPrefix = 'MegaH5_';

    /**
     * 預設回調路徑後綴，對應 routes/external/sw.php 的 megah5 路由群組
     */
    public static function defaultCallbackPath(): string
    {
        return '/external/api/sw/megah5';
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
        $secretKey = $info['SECRET_KEY'];
        $requestDateTime = gmdate('Y-m-d\TH:i:s\Z');

        $postData = [
            'OperatorId' => $info['OPERATOR_ID'],
            'RequestDateTime' => $requestDateTime,
        ];
        $postData['Signature'] = $this->makeSignature('GetGameList', $requestDateTime, $info['OPERATOR_ID'], $secretKey);

        $url = rtrim($info['API_URL'], '/') . '/GetGameList';

        $response = Http::logAction($this->actionPrefix . 'GetGameList')
            ->post($url, $postData)
            ->throw();

        $json = $response->json();

        if (!$json) {
            throw new ApiException('Third party error: not json response');
        }

        if (($json['Status'] ?? '') != 200) {
            $code = $json['Status'] ?? '';
            $error = $json['Description'] ?? '';
            throw new ApiException('Third party error: (' . $code . ') ' . $error);
        }

        $data = [];
        foreach ($json['Game'] as $game) {
            $gameCode = (string) $game['GameCode'];
            $data[$gameCode] = GameInfoData::from([
                'gameCode' => $gameCode,
                'gameName' => $game['GameName'],
                'imageUrl' => $game['ImageUrl'],
                'gameType' => $this->convertGameType($game['GameType']),
                'status' => 1,
            ]);
        }

        return GetGameListResultData::from(['gameList' => $data]);
    }

    /**
     * 建立我方遊戲商帳號
     * 不需要真的呼叫第三方API建立帳號，直接在資料庫建立帳號資料即可
     * 但要注意必須加上商戶設定的SUFFIX，才能對應到正確的第三方帳號
     */
    public function createAccount(CreateAccountParamsData $params): CreateAccountResultData
    {
        // 注入遊戲商戶資料
        $this->setVendorInfo($params->vendorInfo);

        $suffix = $this->vendorInfo->info['SUFFIX'] ?? '';
        $username = $params->username . $suffix;

        $data = [
            'vendor_info_id' => $this->vendorInfo->id,
            'username' => $username,
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

    public function getGameUrl(VendorGameUrlParamsData $params): VendorGameUrlResultData
    {
        $info = $this->vendorInfo->info;
        $secretKey = $info['SECRET_KEY'];
        $username = $this->vendorAccount->username;
        $gameCode = $params->gameCode;
        $exitUrl = $params->exitUrl;
        $ip = $params->ip;
        $apiUrl = $info['API_URL'];

        $authToken = [REDACTED]'-', '', Str::uuid()->toString());
        $this->vendorAccount->update(['auth_token' => $authToken]);

        $functionName = 'GameLogin';
        $url = rtrim($apiUrl, '/') . '/' . $functionName;
        $language = $this->getLanguage($params->language->value);
        $requestDateTime = gmdate('Y-m-d\TH:i:s\Z');

        $postData = [
            'OperatorId' => $info['OPERATOR_ID'],
            'RequestDateTime' => $requestDateTime,
            'PlayerId' => $username,
            'Ip' => $ip,
            'GameCode' => $gameCode,
            'Currency' => $info['CURRENCY'],
            'Lang' => $language,
            'RedirectUrl' => $exitUrl,
            'AuthToken' => $authToken,
            'LaunchDemo' => false,
        ];
        $postData['Signature'] = $this->makeSignature($functionName, $requestDateTime, $info['OPERATOR_ID'], $secretKey, $username);

        $response = Http::logAction($this->actionPrefix . 'GameLogin')->post($url, $postData)->throw();
        $json = $response->json();

        if (!$json) {
            throw new ApiException('Third party error: not json response');
        }

        if (($json['Status'] ?? '') != 200) {
            $code = $json['Status'] ?? '';
            $error = $json['Description'] ?? '';
            throw new ApiException('Third party error: (' . $code . ') ' . $error);
        }

        $gameUrl = $json['Url'];

        return VendorGameUrlResultData::from([
            'pc' => $gameUrl,
            'mobile' => $gameUrl,
        ]);
    }

    /**
     * 依各接口規則串接後取 MD5，呼叫端按文檔順序傳入各片段。
     * GameLogin:           (GameLogin, RequestDateTime, OperatorId, SecretKey, PlayerId)
     * GetGameList:         (GetGameList, RequestDateTime, OperatorId, SecretKey)
     * Bet/Rollback:        (FunctionName, BetId, RequestDateTime, OperatorId, SecretKey, PlayerId)
     * GameResult:          (GameResult, ResultId, RequestDateTime, OperatorId, SecretKey, PlayerId)
     * CashBonus/Jackpot:   (FunctionName, TranId, RequestDateTime, OperatorId, SecretKey, PlayerId)
     */
    private function makeSignature(string ...$parts): string
    {
        return md5(implode('', $parts));
    }

    private function convertGameType(int $type): int
    {
        // MegaH5 Game Type: 0=Others, 1=Slots, 2=Arcade, 3=Table, 4=Event, 5=Mini Game, 6=Fish Arcade
        return match ($type) {
            1 => GameTypeEnum::SLOTS->value,
            2, 5 => GameTypeEnum::ARCADE->value,
            3 => GameTypeEnum::POKER->value,
            6 => GameTypeEnum::FISHING->value,
            default => GameTypeEnum::EVENT_AND_OTHERS->value,
        };
    }

    /**
     * Language code（依 MegaH5 文件 7.1 Supported Language Code 對照，非標準 ISO 639-1，
     * 例如 Malay 文件寫死 ml-my、Bengali 寫死 bn-bl，照文件字面值帶，不自行修正）。
     * TA(坦米爾語)/TPI(Tok Pisin)/HO(Hiri Motu) 不在 MegaH5 支援清單內，fallback en-us。
     */
    private function getLanguage(int $languageId): string
    {
        return match ($languageId) {
            LanguageEnum::EN->value => 'en-us',
            LanguageEnum::ZH_TW->value => 'zh-tw',
            LanguageEnum::ZH_CN->value => 'zh-cn',
            LanguageEnum::MS_MY->value => 'ml-my',
            LanguageEnum::ID_ID->value => 'id-id',
            LanguageEnum::VI_VN->value => 'vi-vn',
            LanguageEnum::TH_TH->value => 'th-th',
            LanguageEnum::TL_PH->value => 'fil-ph',
            LanguageEnum::MY_MM->value => 'my-mm',
            LanguageEnum::HI_IN->value => 'hi-in',
            LanguageEnum::RU->value => 'ru-ru',
            LanguageEnum::BN->value => 'bn-bl',
            default => 'en-us',
        };
    }
}
