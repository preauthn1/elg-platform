<?php

declare(strict_types=1);

namespace App\Vendors\MetaGamingSW;

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
use App\Vendors\Models\MetaGamingSWAccount;
use App\Vendors\Models\MetaGamingSWBetRecord;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * @knowledge rules/game-list-sync
 */
class Driver extends AbstractSingleWallet implements HasCallbackPath, ShouldScheduleAccountCreation, ShouldSyncGameList
{
    protected string $accountModel = MetaGamingSWAccount::class;

    protected string $betRecordModel = MetaGamingSWBetRecord::class;

    protected string $actionPrefix = 'MetaGamingSW_';

    /**
     * 可上架遊戲代碼的格式：六位數字。GetGameList 同步時僅收錄符合本格式的遊戲。
     *
     * 三方四條產品線（MetaGaming／LGMetaGaming／MGH5MetaGaming／LGMGH5MetaGaming）全部可上架，
     * 不依產品線篩選；唯一的收錄條件是代碼為六位數字（客服確認，target/op_tickets#3347）。
     *
     * 以格式而非逐款代碼過濾，三方上的新遊戲免手動維護即可自動收錄。
     */
    public const GAME_CODE_PATTERN = '/^\d{6}$/';

    /**
     * 預設回調路徑後綴，對應 routes/external/sw.php 的 metagaming 路由群組
     */
    public static function defaultCallbackPath(): string
    {
        return '/external/api/sw/metagaming';
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
        $secretKey = $info['SECRET'];
        $requestDateTime = gmdate('Y-m-d H:i:s');

        $postData = [
            'OperatorId' => $info['OPERATOR_ID'],
            'RequestDateTime' => $requestDateTime,
        ];
        $postData['Signature'] = $this->makeSignature('GetGameList', $requestDateTime, $info['OPERATOR_ID'], $secretKey);

        $url = rtrim($info['API_URL'], '/') . '/GetGameList';

        $response = Http::logAction($this->actionPrefix . 'GetGameList')
            ->post($url, $postData)
            ->throw(function ($response, $e) {
                throw new ApiException(sprintf('Third party error: HttpStatus %s, Body %s', $response->status(), $response->body()));
            });

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
            if (!preg_match(self::GAME_CODE_PATTERN, $gameCode)) {
                continue;
            }
            $data[$gameCode] = GameInfoData::from([
                'gameCode' => $gameCode,
                'gameName' => $game['GameName'],
                'imageUrl' => $game['ImageUrl'] ?? '',
                'gameType' => $this->convertGameType((int) $game['GameType']),
                'status' => 1,
            ]);
        }

        return GetGameListResultData::from(['gameList' => $data]);
    }

    /**
     * 建立我方遊戲商帳號
     * 不需要真的呼叫第三方 API 建立帳號（MTG 的 GameLogin 會自動建立玩家），直接在資料庫建立帳號資料即可
     * 需加上商戶設定的 SUFFIX，才能對應到正確的第三方帳號
     */
    public function createAccount(CreateAccountParamsData $params): CreateAccountResultData
    {
        $this->setVendorInfo($params->vendorInfo);

        $suffix = $this->vendorInfo->info['SUFFIX'] ?? '';
        $username = $params->username . $suffix;

        $account = $this->accountModel::create([
            'vendor_info_id' => $this->vendorInfo->id,
            'username' => $username,
        ]);

        return CreateAccountResultData::from([
            'status' => (bool) $account,
            'username' => $username,
        ]);
    }

    public function getGameUrl(VendorGameUrlParamsData $params): VendorGameUrlResultData
    {
        $info = $this->vendorInfo->info;
        $secretKey = $info['SECRET'];
        $username = $this->vendorAccount->username;
        $apiUrl = $info['API_URL'];

        $authToken = [REDACTED]'-', '', Str::uuid()->toString());
        $this->vendorAccount->update(['session_token' => $authToken]);

        $functionName = 'GameLogin';
        $url = rtrim($apiUrl, '/') . '/' . $functionName;
        $language = $this->getLanguage($params->language->value);
        $requestDateTime = gmdate('Y-m-d H:i:s');

        $postData = [
            'OperatorId' => $info['OPERATOR_ID'],
            'RequestDateTime' => $requestDateTime,
            'PlayerId' => $username,
            'Ip' => $params->ip,
            'GameCode' => $params->gameCode,
            'Currency' => $info['CURRENCY'],
            'Lang' => $language,
            'RedirectUrl' => $params->exitUrl,
            'AuthToken' => $authToken,
            'LaunchDemo' => false,
        ];
        $postData['Signature'] = $this->makeSignature($functionName, $requestDateTime, $info['OPERATOR_ID'], $secretKey, $username);

        $response = Http::logAction($this->actionPrefix . 'GameLogin')->post($url, $postData)->throw(function ($response, $e) {
            throw new ApiException(sprintf('Third party error: HttpStatus %s, Body %s', $response->status(), $response->body()));
        });
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
     * 依各接口規則串接後取 MD5，呼叫端按 api.md §6 順序傳入各片段。
     * GetGameList: (GetGameList, RequestDateTime, OperatorId, SecretKey)
     * GameLogin:   (GameLogin, RequestDateTime, OperatorId, SecretKey, PlayerId)
     */
    private function makeSignature(string ...$parts): string
    {
        return md5(implode('', $parts));
    }

    private function convertGameType(int $type): int
    {
        return match ($type) {
            1 => GameTypeEnum::SLOTS->value,
            2, 5 => GameTypeEnum::ARCADE->value,
            3 => GameTypeEnum::POKER->value,
            6 => GameTypeEnum::FISHING->value,
            default => GameTypeEnum::EVENT_AND_OTHERS->value,
        };
    }

    /**
     * Language code（api.md §7.1）
     */
    private function getLanguage(int $languageId): string
    {
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
}
