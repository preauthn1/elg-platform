<?php

declare(strict_types=1);

namespace App\Vendors\DstPlaySW;

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
use App\Vendors\Models\DstPlaySwAccount;
use App\Vendors\Models\DstPlaySwBetRecord;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class Driver extends AbstractSingleWallet implements HasCallbackPath, ShouldScheduleAccountCreation, ShouldSyncGameList
{
    protected string $accountModel = DstPlaySwAccount::class;

    protected string $betRecordModel = DstPlaySwBetRecord::class;

    protected string $actionPrefix = 'DstPlaySW_';

    private DstPlaySwAccount $account;

    /**
     * 預設回調路徑後綴，對應 routes/external/sw.php 的 dstplaysw 路由群組
     */
    public static function defaultCallbackPath(): string
    {
        return '/external/api/sw/dstplaysw';
    }

    public function setInfo(array $info): void
    {
        // info 透過 $this->vendorInfo->info 直接存取，無需額外解析
    }

    /**
     * 建立我方遊戲商帳號，並呼叫廠商 /api/v1/created 建立第三方帳號
     */
    public function createAccount(CreateAccountParamsData $params): CreateAccountResultData
    {
        $this->setVendorInfo($params->vendorInfo);

        $account = $this->accountModel::create([
            'vendor_info_id' => $this->vendorInfo->id,
            'username' => $params->username,
        ]);

        if (!$account) {
            return CreateAccountResultData::from(['status' => false, 'username' => $params->username]);
        }

        try {
            $response = $this->sendRequest('created', ['user_id' => $params->username]);
            $success = ($response['code'] ?? null) === 0;
        } catch (\Throwable) {
            $success = false;
        }

        if (!$success) {
            $account->update(['member_id' => -999999999]); // 軟刪除，避免帳號池污染

            return CreateAccountResultData::from(['status' => false, 'username' => $params->username]);
        }

        return CreateAccountResultData::from(['status' => true, 'username' => $params->username]);
    }

    public function getGameUrl(VendorGameUrlParamsData $params): VendorGameUrlResultData
    {
        $this->account = $this->vendorAccount;

        $token = [REDACTED]'-', '', Str::uuid()->toString());
        $this->account->update(['session_token' => $token]);

        $response = $this->sendRequest('gameLaunchByToken', [
            'token' => $token,
            'game_code' => $params->gameCode,
            'language' => $this->mapLanguageCode($params->language),
            'return_url' => $params->exitUrl,
        ]);

        if (($response['code'] ?? null) !== 0) {
            throw new ApiException('[DstPlaySW] gameLaunchByToken failed: ' . ($response['msg'] ?? 'unknown error'));
        }

        $gameUrl = (string) ($response['gameUrl'] ?? '');

        return VendorGameUrlResultData::from([
            'pc' => $gameUrl,
            'mobile' => $gameUrl,
        ]);
    }

    /**
     * 索取遊戲列表（/api/v1/getGames，game_type=0 取得全部）
     */
    public function getGameList(GetGameListParamsData $params): GetGameListResultData
    {
        $this->setVendorInfo($params->vendorInfo);

        $response = $this->sendRequest('getGames', ['game_type' => 0]);

        if (($response['code'] ?? null) !== 0) {
            throw new ApiException('[DstPlaySW] getGames failed: ' . ($response['msg'] ?? 'unknown error'));
        }

        $gameList = [];
        foreach ($response['gameList'] ?? [] as $game) {
            $gameCode = (string) ($game['gameCode'] ?? '');
            if ($gameCode === '') {
                continue;
            }

            $gameList[$gameCode] = GameInfoData::from([
                'gameCode' => $gameCode,
                'gameName' => (string) ($game['gameName'] ?? $gameCode),
                'imageUrl' => "https://example.invalid/images/thumb/square/{$gameCode}.jpg",
                'gameType' => $this->mapGameType((string) ($game['gameType'] ?? '')),
                'status' => 1,
            ]);
        }

        return GetGameListResultData::from(['gameList' => $gameList]);
    }

    /**
     * @throws ApiException
     */
    private function sendRequest(string $endpoint, array $params): array
    {
        $info = $this->vendorInfo->info;
        $apiId = (string) $info['API_ID'];
        $apiKey = [REDACTED] $info['API_KEY'];
        $timestamp = $this->currentTimestampMs();

        $postData = array_merge([
            'api_id' => $apiId,
            'timestamp' => $timestamp,
            'sign' => md5($apiId . $timestamp . $apiKey),
        ], $params);

        $response = Http::logAction($this->actionPrefix . $endpoint)
            ->asForm()
            ->post(rtrim((string) $info['API_URL'], '/') . '/api/v1/' . $endpoint, $postData)
            ->throw()
            ->json();

        if (!is_array($response)) {
            throw new ApiException("[DstPlaySW][{$endpoint}] Invalid response format");
        }

        return $response;
    }

    private function currentTimestampMs(): string
    {
        return (string) intval(round(microtime(true) * 1000));
    }

    private function mapLanguageCode(LanguageEnum $language): string
    {
        return match ($language) {
            LanguageEnum::EN => 'en',
            LanguageEnum::ZH_TW => 'zhtw',
            LanguageEnum::ZH_CN => 'zhcn',
            LanguageEnum::MS_MY => 'ms',
            LanguageEnum::ID_ID => 'idn',
            LanguageEnum::VI_VN => 'vi',
            LanguageEnum::TH_TH => 'th',
            LanguageEnum::HI_IN => 'hi',
            LanguageEnum::MY_MM => 'my',
            default => 'en',
        };
    }

    private function mapGameType(string $gameType): int
    {
        return match (strtoupper($gameType)) {
            'ARCADE' => GameTypeEnum::ARCADE->value,
            default => GameTypeEnum::SLOTS->value,
        };
    }
}
