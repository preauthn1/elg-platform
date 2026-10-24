<?php

declare(strict_types=1);

namespace App\Vendors\VpowerSW;

use App\Data\GameInfoData;
use App\Data\Vendor\CreateAccountParamsData;
use App\Data\Vendor\CreateAccountResultData;
use App\Data\Vendor\GetGameListParamsData;
use App\Data\Vendor\GetGameListResultData;
use App\Data\VendorGameUrlParamsData;
use App\Data\VendorGameUrlResultData;
use App\Enums\Game\GameTypeEnum;
use App\Enums\General\ContextKeyEnum;
use App\Enums\LanguageEnum;
use App\Exceptions\ApiException;
use App\Exceptions\VendorSetupException;
use App\Helpers\Common;
use App\Models\EventVendorInfo;
use App\Models\Member;
use App\Vendors\AbstractSingleWallet;
use App\Vendors\Contracts\HasCallbackPath;
use App\Vendors\Contracts\ShouldScheduleAccountCreation;
use App\Vendors\Contracts\ShouldSyncGameList;
use App\Vendors\Models\AbstractAccount;
use App\Vendors\Models\EventVpowerswAccount;
use App\Vendors\Models\VpowerswAccount;
use App\Vendors\Models\VpowerswBetRecord;
use App\Vendors\VpowerSW\Datas\VendorInfoDetailData;
use Exception;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class Driver extends AbstractSingleWallet implements HasCallbackPath, ShouldScheduleAccountCreation, ShouldSyncGameList
{
    protected string $accountModel = VpowerswAccount::class;

    protected string $betRecordModel = VpowerswBetRecord::class;

    private VendorInfoDetailData $infoDetail;

    /**
     * 一般會員流程放 {@see VpowerswAccount}，舊版活動流程放 {@see EventVpowerswAccount}；
     * 兩者是平行的 AbstractAccount 子類別，此處只取用 `username`（Eloquent magic property）
     */
    private AbstractAccount $account;

    public function __construct(int $vendorId, ?Member $member)
    {
        parent::__construct($vendorId, $member);

        if (!$member) {
            return;
        }

        $this->setInfo($this->vendorInfo->info);
        $this->account = $this->vendorAccount;
    }

    /**
     * 預設回調路徑後綴，對應 routes/external/sw.php 的 vpower 路由群組
     */
    public static function defaultCallbackPath(): string
    {
        return '/external/api/sw/vpower';
    }

    public function setInfo(array $info): void
    {
        $this->infoDetail = VendorInfoDetailData::from($info);
    }

    /**
     * 建構子只在有 member 時才把 vendorInfo/vendorAccount 轉存到本類別的嚴格型別屬性，
     * 舊版活動情境（`member: null` 建構）需在此補做同一件事，見 {@see BaseAbstractVendor::useLegacyEventContext()}
     */
    public function useLegacyEventContext(EventVendorInfo $eventVendorInfo, AbstractAccount $eventAccount): void
    {
        parent::useLegacyEventContext($eventVendorInfo, $eventAccount);

        if (!($eventAccount instanceof EventVpowerswAccount)) {
            throw new VendorSetupException(static::class . '::useLegacyEventContext requires an EventVpowerswAccount');
        }

        $this->setInfo($eventVendorInfo->info);
        $this->account = $eventAccount;
    }

    public function getGameUrl(VendorGameUrlParamsData $params): VendorGameUrlResultData
    {
        $exitUrl = $params->exitUrl;

        $token = [REDACTED];

        $params = [
            'appId' => $this->infoDetail->APP_ID,
            'timestamp' => (int) (microtime(true) * 1000),
            'back' => base64_encode($exitUrl),
            'token' => $token,
            'id' => $params->gameCode,
            'closeBack' => 1,
            'lang' => $this->mapLanguageCode(),
        ];
        $this->addSign($params);
        $params = urldecode(http_build_query($params));
        $gameUrl = $this->infoDetail->GAME_URL . '?' . $params;

        return VendorGameUrlResultData::from([
            'pc' => $gameUrl,
            'mobile' => $gameUrl,
        ]);
    }

    /**
     * 遊戲清單
     */
    public function getGameList(GetGameListParamsData $params): GetGameListResultData
    {
        $this->setVendorInfo($params->vendorInfo);

        $response = $this->sendRequest('gameList');

        $typeMap = [
            'bet' => GameTypeEnum::ARCADE->value,
            'slots' => GameTypeEnum::SLOTS->value,
            'fishing' => GameTypeEnum::FISHING->value,
        ];

        $data = [];
        foreach ($response['data'] ?? [] as $game) {
            $gameCode = (string) $game['id'];
            $data[$gameCode] = GameInfoData::from([
                'gameCode' => $gameCode,
                'gameName' => $game['name'],
                'imageUrl' => $game['squareIcon'] ?? '',
                'gameType' => $typeMap[strtolower($game['type'] ?? '')] ?? GameTypeEnum::EVENT_AND_OTHERS->value,
                'status' => 1,
                'info' => json_encode($game),
            ]);
        }

        return GetGameListResultData::from(['gameList' => $data]);
    }

    private function userLogin(): string
    {
        $username = $this->account?->username ?? '';

        if (empty($username)) {
            throw new Exception('Username is empty');
        }

        $api = 'player/login';
        $postData = [
            'username' => $username,
        ];

        $response = $this->sendRequest($api, $postData);

        $token = [REDACTED]'data']['token'] ?? '';
        if (empty($token)) {
            throw new Exception('Login failed, token is empty');
        }

        return $token;
    }

    /**
     * Summary of send
     *
     * @return mixed
     *
     * @throws Exception Url or securityKey is empty, use setVendorInfo() to set the value
     * @throws Exception VendorSend send response is not string or is empty
     */
    private function sendRequest(string $api, array $postData = [], string $action = ''): ?array
    {
        $action = empty($action) ? "Vpower_SW_api_call_{$api}" : $action;

        $postData['appId'] = $this->infoDetail->APP_ID;
        $postData['timestamp'] = (int) (microtime(true) * 1000);

        $this->addSign($postData);

        $apiUrl = trim($this->infoDetail->API_URL, '/');
        $api = trim($api, '/');

        $response = Http::logAction($action)
            ->withHeaders(['Content-Type:application/json'])
            ->post("{$apiUrl}/{$api}", $postData)
            ->throw()
            ->json();

        if (empty($response)) {
            throw new Exception("[$action] response is empty");
        }

        if (!isset($response['code']) || $response['code'] !== 200) {
            // 三方業務性拒絕，非系統故障：走 ApiException 讓 AlertGate 靜音不發 TG。
            throw new ApiException("[$action] response code is not 200, code: {$response['code']}, msg: " . ($response['msg'] ?? ''));
        }

        return $response;
    }

    /**
     * Sort keys asc, then build query string, then append APP_SECRET to the end, then md5
     *
     * @param  array  $postData  reference
     */
    private function addSign(array &$postData): void
    {
        unset($postData['sign']);

        ksort($postData);

        $sign = urldecode(http_build_query($postData)) . $this->infoDetail->APP_SECRET;
        $postData['sign'] = md5($sign);
    }

    private function mapLanguageCode(): string
    {
        $languageId = Context::getHidden(ContextKeyEnum::LANGUAGE_ID->value);

        return match ($languageId) {
            LanguageEnum::EN->value => 'en',
            LanguageEnum::ZH_TW->value => 'cn',
            LanguageEnum::ZH_CN->value => 'cn',
            LanguageEnum::MS_MY->value => 'my',
            LanguageEnum::ID_ID->value => 'ida',
            LanguageEnum::VI_VN->value => 'vi',
            LanguageEnum::TH_TH->value => 'th',
            default => 'en',
        };
    }

    /**
     * 建立我方遊戲商帳號（遷自 RD-Studio create_vpower_single_wallet_account）
     *
     * 無密碼；登入由 player/login 只帶 username 取 token。
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
     * 建立三方遊戲商帳號（/createAccount；sendRequest 已驗 code === 200，這裡再擋 data.error）
     */
    private function createVendorAccount(string $username): bool
    {
        try {
            $response = $this->sendRequest('createAccount', [
                'username' => $username,
            ]);
        } catch (Throwable $e) {
            Log::error('[VpowerSW][createAccount] request failed', [
                'username' => $username,
                'error' => $e->getMessage(),
                'trace' => Common::formatTrace($e),
            ]);

            return false;
        }

        if (isset($response['data']['error'])) {
            Log::error('[VpowerSW][createAccount] rejected', [
                'username' => $username,
                'error' => $response['data']['error'],
            ]);

            return false;
        }

        return true;
    }
}
