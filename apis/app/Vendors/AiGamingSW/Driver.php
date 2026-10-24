<?php

declare(strict_types=1);

namespace App\Vendors\AiGamingSW;

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
use App\Vendors\Models\AiGamingSwAccount;
use App\Vendors\Models\AiGamingSwBetRecord;
use Illuminate\Support\Facades\Http;

class Driver extends AbstractSingleWallet implements HasCallbackPath, ShouldScheduleAccountCreation, ShouldSyncGameList
{
    protected string $accountModel = AiGamingSwAccount::class;

    protected string $betRecordModel = AiGamingSwBetRecord::class;

    protected string $actionPrefix = 'AiGamingSW_';

    /**
     * 預設回調路徑後綴，對應 routes/external/sw.php 的 aigaming 路由群組
     */
    public static function defaultCallbackPath(): string
    {
        return '/external/api/sw/aigaming';
    }

    public function setInfo(array $info): void
    {
        // info 透過 $this->vendorInfo->info 直接存取，無需額外解析
    }

    /**
     * 建立我方遊戲商帳號，並呼叫廠商 /user-center/openApi/createUser 建立第三方帳號
     */
    public function createAccount(CreateAccountParamsData $params): CreateAccountResultData
    {
        $this->setVendorInfo($params->vendorInfo);

        // 兩段式 member_id：插入即以 -999999999 佔位，三方建成才翻 0 入池，
        // 杜絕「三方尚未建成就被玩家綁走」的競態（原寫法先入池、失敗才標記）
        $account = $this->accountModel::create([
            'vendor_info_id' => $this->vendorInfo->id,
            'username' => $params->username,
            'member_id' => -999999999,
        ]);

        if (!$account) {
            return CreateAccountResultData::from(['status' => false, 'username' => $params->username]);
        }

        try {
            $response = $this->sendRequest(
                endpoint: 'user-center/openApi/createUser',
                params: ['username' => $params->username],
                signFields: fn (string $timestamp) => $this->provider() . $timestamp . $params->username . $this->walletType(),
            );
            // 帳號已存在於三方（userexists）視為成功，保持冪等
            $success = ($response['code'] ?? '') === '0'
                || ($response['errorKey'] ?? '') === 'userexists';
        } catch (\Throwable) {
            $success = false;
        }

        if (!$success) {
            return CreateAccountResultData::from(['status' => false, 'username' => $params->username]);
        }

        $account->update(['member_id' => 0]);

        return CreateAccountResultData::from(['status' => true, 'username' => $params->username]);
    }

    /**
     * 取得遊戲啟動網址（/user-center/openApi/authenticate）
     */
    public function getGameUrl(VendorGameUrlParamsData $params): VendorGameUrlResultData
    {
        $username = $this->vendorAccount->username;
        $gameCode = $params->gameCode;

        $response = $this->sendRequest(
            endpoint: 'user-center/openApi/authenticate',
            params: [
                'username' => $username,
                'gameCode' => $gameCode,
                'lang' => $this->mapLanguageCode($params->language),
            ],
            signFields: fn (string $timestamp) => $gameCode . $this->provider() . $timestamp . $username . $this->walletType(),
        );

        if (($response['code'] ?? '') !== '0') {
            throw new ApiException('[AiGamingSW] authenticate failed: ' . ($response['message'] ?? 'unknown error'));
        }

        $gameUrl = (string) ($response['data']['gameUrl'] ?? '');
        if ($gameUrl === '') {
            throw new ApiException('[AiGamingSW] authenticate failed: empty gameUrl');
        }

        if (!empty($params->exitUrl)) {
            $gameUrl .= '&origin=' . $params->exitUrl;
        }

        return VendorGameUrlResultData::from([
            'pc' => $gameUrl,
            'mobile' => $gameUrl,
        ]);
    }

    /**
     * 索取遊戲列表（GET /game/getGameList?platform={provider}，無簽名）
     */
    public function getGameList(GetGameListParamsData $params): GetGameListResultData
    {
        $this->setVendorInfo($params->vendorInfo);

        $response = Http::logAction($this->actionPrefix . 'getGameList')
            ->get($this->apiUrl() . '/game/getGameList', ['platform' => $this->provider()])
            ->throw()
            ->json();

        if (!is_array($response) || ($response['code'] ?? '') !== '0') {
            throw new ApiException('[AiGamingSW] getGameList failed: ' . ($response['message'] ?? 'invalid response'));
        }

        $gameList = [];
        foreach ($response['data']['gameList'] ?? [] as $game) {
            $gameCode = (string) ($game['gameCode'] ?? '');
            if ($gameCode === '') {
                continue;
            }

            $gameList[$gameCode] = GameInfoData::from([
                'gameCode' => $gameCode,
                'gameName' => (string) ($game['gameName'] ?? $gameCode),
                'imageUrl' => (string) ($game['imageUrl'] ?? ''),
                'gameType' => GameTypeEnum::SLOTS->value,
                'status' => ($game['status'] ?? '') === 'ACTIVE' ? 1 : 0,
            ]);
        }

        return GetGameListResultData::from(['gameList' => $gameList]);
    }

    /**
     * @param  \Closure(string):string  $signFields  以 timestamp 組出 apiKey 之後的簽名串接段
     *
     * @throws ApiException
     */
    private function sendRequest(string $endpoint, array $params, \Closure $signFields): array
    {
        $timestamp = (string) intval(round(microtime(true) * 1000));

        $postData = array_merge([
            'provider' => $this->provider(),
            'walletType' => $this->walletType(),
            'timestamp' => $timestamp,
            'sign' => strtolower(md5($this->apiKey() . $signFields($timestamp))),
        ], $params);

        $response = Http::logAction($this->actionPrefix . basename($endpoint))
            ->post($this->apiUrl() . '/' . $endpoint, $postData)
            ->json();

        if (!is_array($response)) {
            throw new ApiException("[AiGamingSW][{$endpoint}] Invalid response format");
        }

        return $response;
    }

    private function apiUrl(): string
    {
        return rtrim((string) $this->vendorInfo->info['API_URL'], '/');
    }

    private function apiKey(): string
    {
        return (string) $this->vendorInfo->info['API_KEY'];
    }

    private function provider(): string
    {
        return (string) $this->vendorInfo->info['PROVIDER'];
    }

    private function walletType(): string
    {
        return (string) $this->vendorInfo->currency;
    }

    private function mapLanguageCode(LanguageEnum $language): string
    {
        return match ($language) {
            LanguageEnum::ZH_TW => 'zh-tw',
            LanguageEnum::ZH_CN => 'zh',
            default => 'en',
        };
    }
}
