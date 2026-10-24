<?php

declare(strict_types=1);

namespace App\Vendors\WFSW;

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
use App\Helpers\Common;
use App\Vendors\AbstractSingleWallet;
use App\Vendors\Contracts\HasCallbackPath;
use App\Vendors\Contracts\ShouldScheduleAccountCreation;
use App\Vendors\Contracts\ShouldSyncGameList;
use App\Vendors\Models\WfswAccount;
use App\Vendors\Models\WfswBetRecord;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class Driver extends AbstractSingleWallet implements HasCallbackPath, ShouldScheduleAccountCreation, ShouldSyncGameList
{
    protected string $accountModel = WfswAccount::class;

    protected string $betRecordModel = WfswBetRecord::class;

    /**
     * 預設回調路徑後綴，對應 routes/external/sw.php 的 wf 路由群組
     */
    public static function defaultCallbackPath(): string
    {
        return '/external/api/sw/wf';
    }

    public function getGameUrl(VendorGameUrlParamsData $params): VendorGameUrlResultData
    {
        $info = $this->vendorInfo->info;
        $secretKey = $info['SecretKey'];
        $username = $this->vendorAccount->username;
        $gameCode = $params->gameCode;
        $exitUrl = $params->exitUrl;
        $ip = $params->ip;

        $funcName = 'GameLogin';
        $url = $info['API_URL'] . '/' . $funcName;
        $language = $this->getLanguage($params->language->value);

        $postData = [
            'OperatorId' => $info['OperatorId'],
            'RequestDateTime' => date('Y-m-d H:i:s'),
            'PlayerId' => $username,
            'Ip' => $ip,
            'RedirectUrl' => $exitUrl,
            'GameCode' => $gameCode,
            'Currency' => $this->vendorInfo->currency,
            'Lang' => $language,
        ];
        $postData['Signature'] = $this->makeSignature($funcName, $postData, $secretKey);

        $response = Http::logAction('WF_GameLogin')->post($url, $postData)->throw();
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
            'OperatorId' => $info['OperatorId'],
            'RequestDateTime' => date('Y-m-d H:i:s'),
        ];
        $postData['Signature'] = $this->makeSignature($funcName, $postData, $info['SecretKey']);

        $url = rtrim($info['API_URL'], '/') . '/' . $funcName;

        $response = Http::logAction('WF_GetGameList')->post($url, $postData)->throw();
        $json = $response->json();

        if (!$json) {
            throw new ApiException('Third party error: not json response');
        }

        if (($json['Status'] ?? '') != 200) {
            $code = $json['Status'] ?? '';
            $error = $json['Description'] ?? '';
            throw new ApiException('Third party error: (' . $code . ') ' . $error);
        }

        $typeMap = [
            'bet' => GameTypeEnum::ARCADE->value,
            'slots' => GameTypeEnum::SLOTS->value,
            'fishing' => GameTypeEnum::FISHING->value,
        ];

        // 三方只提供 zh-cn 譯名，繁中沿用同一組（比照舊系統 languages.wfsw 對照）
        $langMap = [
            'zh-cn' => [LanguageEnum::ZH_TW->value, LanguageEnum::ZH_CN->value],
        ];

        $data = [];
        foreach ($json['Game'] ?? [] as $game) {
            $gameCode = (string) $game['GameCode'];
            $gameInfo = GameInfoData::from([
                'gameCode' => $gameCode,
                'gameName' => $game['GameName'],
                'imageUrl' => $game['ImageUrl'] ?? '',
                'gameType' => $typeMap[strtolower($game['Method'] ?? '')] ?? GameTypeEnum::EVENT_AND_OTHERS->value,
                'status' => 1,
                'info' => json_encode($game),
            ]);

            // OtherName 格式為 "語系碼|名稱"
            foreach ($game['OtherName'] ?? [] as $otherName) {
                [$langCode, $name] = array_pad(explode('|', $otherName, 2), 2, '');
                foreach ($langMap[trim($langCode)] ?? [] as $languageId) {
                    if (trim($name) !== '') {
                        $gameInfo->setGameNames($languageId, trim($name));
                    }
                }
            }

            $data[$gameCode] = $gameInfo;
        }

        return GetGameListResultData::from(['gameList' => $data]);
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
     * 建立我方遊戲商帳號（遷自 RD-Studio create_wf_single_wallet_account）
     *
     * WF 沒有獨立的註冊 API，建號＝以 PlayerId 打一次 GameLogin。
     */
    public function createAccount(CreateAccountParamsData $params): CreateAccountResultData
    {
        $this->setVendorInfo($params->vendorInfo);
        $username = $params->username;

        $account = $this->accountModel::create([
            'vendor_info_id' => $params->vendorInfo->id,
            'username' => $username,
            'admin' => 1,
            'member_id' => -999999999,
        ]);

        if (!$account) {
            return CreateAccountResultData::from(['status' => false, 'username' => $username]);
        }

        if (!$this->createVendorAccount($username)) {
            return CreateAccountResultData::from(['status' => false, 'username' => $username]);
        }

        $account->update(['member_id' => 0]);

        return CreateAccountResultData::from(['status' => true, 'username' => $username]);
    }

    /**
     * 建立三方遊戲商帳號
     */
    private function createVendorAccount(string $username): bool
    {
        $info = $this->vendorInfo->info;
        $funcName = 'GameLogin';

        $postData = [
            'OperatorId' => $info['OperatorId'],
            'RequestDateTime' => date('Y-m-d H:i:s'),
            'PlayerId' => $username,
            'Ip' => '0.0.0.0',
            'GameCode' => '0',
            'Currency' => $this->vendorInfo->currency,
        ];
        $postData['Signature'] = $this->makeSignature($funcName, $postData, $info['SecretKey']);

        try {
            $json = Http::logAction('WF_createAccount')
                ->post(rtrim($info['API_URL'], '/') . '/' . $funcName, $postData)
                ->throw()
                ->json();
        } catch (Throwable $e) {
            Log::error('[WFSW][createAccount] request failed', [
                'username' => $username,
                'error' => $e->getMessage(),
                'trace' => Common::formatTrace($e),
            ]);

            return false;
        }

        if (!is_array($json) || ($json['Status'] ?? '') != 200) {
            Log::error('[WFSW][createAccount] failed', ['username' => $username, 'response' => $json]);

            return false;
        }

        return true;
    }
}
