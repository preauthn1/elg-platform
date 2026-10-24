<?php

declare(strict_types=1);

namespace App\Vendors\MegawinSW;

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
use App\Models\Member;
use App\Vendors\AbstractSingleWallet;
use App\Vendors\Contracts\HasCallbackPath;
use App\Vendors\Contracts\ShouldScheduleAccountCreation;
use App\Vendors\Contracts\ShouldSyncGameList;
use App\Vendors\MegawinSW\Datas\MegaWinSWVendorInfoDetailData;
use App\Vendors\MegawinSW\Externals\CipherService;
use App\Vendors\Models\MegawinswAccount;
use App\Vendors\Models\MegawinswBetRecord;
use Exception;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class Driver extends AbstractSingleWallet implements HasCallbackPath, ShouldScheduleAccountCreation, ShouldSyncGameList
{
    protected string $accountModel = MegawinswAccount::class;

    protected string $betRecordModel = MegawinswBetRecord::class;

    private MegaWinSWVendorInfoDetailData $infoDetail;

    private MegawinswAccount $account;

    private CipherService $cipherService;

    private array $languageMap = [
        LanguageEnum::EN->value => 'en',
        LanguageEnum::ZH_CN->value => 'cn',
        LanguageEnum::ZH_TW->value => 'hk',
        LanguageEnum::TH_TH->value => 'th',
        LanguageEnum::MY_MM->value => 'en',
        LanguageEnum::HI_IN->value => '',
        LanguageEnum::TPI->value => 'en',
        LanguageEnum::HO->value => 'en',
    ];

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
     * 預設回調路徑後綴，對應 routes/external/sw.php 的 megawin 路由群組
     */
    public static function defaultCallbackPath(): string
    {
        return '/external/api/sw/megawin';
    }

    public function setInfo(array $info): void
    {
        $this->infoDetail = MegaWinSWVendorInfoDetailData::from($info);
        $this->cipherService = App::make(CipherService::class, [
            'info' => $this->infoDetail,
        ]);
    }

    public function getGameUrl(VendorGameUrlParamsData $params): VendorGameUrlResultData
    {
        $api = 'Oauth';
        $postData = [
            'uid' => $this->account->username,
            'utoken' => $this->account->user_token,
            'firmId' => $this->infoDetail->FIRM_ID,
            'currency' => $this->infoDetail->CURRENCY,
            'gameId' => $params->gameCode,
            'lang' => $this->languageMap[$params->language->value],
            'clientDate' => date('Y-m-d H:i:s'),
        ];

        $response = $this->send($api, $postData);

        $gameUrl = $response['interface2'] ?? '';

        return VendorGameUrlResultData::from([
            'pc' => $gameUrl,
            'mobile' => $gameUrl,
        ]);
    }

    /**
     * 遊戲清單（主名稱為簡中，比照舊系統以 lang=cn 取得）
     */
    public function getGameList(GetGameListParamsData $params): GetGameListResultData
    {
        $this->setVendorInfo($params->vendorInfo);

        $response = $this->send('GameInfo', [
            'lang' => 'cn',
            'timestamp' => time(),
        ]);

        $typeMap = [
            4 => GameTypeEnum::ARCADE->value, // 街機遊戲
            5 => GameTypeEnum::SLOTS->value, // 老虎機遊戲
            6 => GameTypeEnum::TABLE->value, // 桌面遊戲
            7 => GameTypeEnum::FISHING->value, // 魚機遊戲
        ];

        $data = [];
        foreach ($response['games'] ?? [] as $game) {
            $gameCode = (string) $game['gameId'];
            $data[$gameCode] = GameInfoData::from([
                'gameCode' => $gameCode,
                'gameName' => $game['gameName'],
                'imageUrl' => $game['gameIcon'] ?? '',
                'gameType' => $typeMap[(int) ($game['gameCategory'] ?? -1)] ?? GameTypeEnum::EVENT_AND_OTHERS->value,
                'status' => 1,
                'info' => json_encode($game),
            ]);
        }

        return GetGameListResultData::from(['gameList' => $data]);
    }

    private function send(string $api, array $postData): array
    {
        if (empty($this->infoDetail)) {
            throw new Exception('VendorInfo info is empty');
        }

        $action = empty($action) ? "MegaWin_SW_api_call_{$api}" : $action;
        $endPoint = '/api/nmw/common/' . $api;

        $domain = trim($this->getActualDomain(), '/');

        $keyData = $this->cipherService->encrypt($postData);

        $response = Http::logAction($action)
            ->withHeaders([
                'Content-Type' => 'application/json',
                'method' => $api,
                'siteId' => $this->infoDetail->SITE_ID,
            ])
            ->post("{$domain}/{$endPoint}", $keyData)
            ->throw();

        $responseJson = $response->body();
        if (!is_string($responseJson) || empty($responseJson)) {
            throw new Exception('VendorSend send response is not string or is empty');
        }

        $responseJson = json_decode($responseJson, true);
        $response = $this->cipherService->decrypt($responseJson['key'], $responseJson['data']);

        if ($response['ret'] !== '0000') {
            // 三方業務性拒絕，非系統故障：走 ApiException 讓 AlertGate 靜音不發 TG。
            throw new ApiException("VendorSend send response ret is not 0, msg: {$response['msg']}");
        }

        return $response ?? [];
    }

    private function getActualDomain(): string
    {
        $data = [
            'timestamp' => time(),
        ];

        $keyData = $this->cipherService->encrypt($data);

        $siteUrl = rtrim($this->infoDetail->SITE_URL, '/');
        $response = Http::logAction('Megawin_SW_api_call_Get_Actual_Domain')
            ->withHeaders([
                'Content-Type' => 'application/json',
                'method' => 'Domain',
                'siteId' => $this->infoDetail->SITE_ID,
            ])
            ->post("{$siteUrl}/as-lobby/api/nmw/common/Domain", $keyData)
            ->throw();

        $responseJson = $response->body();
        if (!is_string($responseJson) || empty($responseJson)) {
            throw new Exception('VendorSend send response is not string or is empty');
        }

        $responseJson = json_decode($responseJson, true);
        $response = $this->cipherService->decrypt($responseJson['key'], $responseJson['data']);

        return $response['domain'] ?? '';
    }

    /**
     * 建立我方遊戲商帳號（遷自 RD-Studio create_megawin_single_wallet_account）
     *
     * MegaWin 沒有密碼，登入（getGameUrl）拿 user_token 當 utoken 換 URL：
     * 這裡產生的 user_token 必須先入庫、再用同一值送三方 Oauth，兩邊不同就登不進去。
     */
    public function createAccount(CreateAccountParamsData $params): CreateAccountResultData
    {
        // setInfo 同時建立 cipherService，send() 依賴它，不可省略
        $this->setVendorInfo($params->vendorInfo);
        $username = $params->username;

        $account = $this->accountModel::create([
            'vendor_info_id' => $params->vendorInfo->id,
            'username' => $username,
            'user_token' => Str::random(32),
            'admin' => 1,
            'member_id' => -999999999,
        ]);

        if (!$account) {
            return CreateAccountResultData::from(['status' => false, 'username' => $username]);
        }

        if (!$this->createVendorAccount($account)) {
            return CreateAccountResultData::from(['status' => false, 'username' => $username]);
        }

        $account->update(['member_id' => 0]);

        return CreateAccountResultData::from(['status' => true, 'username' => $username]);
    }

    /**
     * 建立三方遊戲商帳號（不帶 gameId 的 Oauth；send() 內部已檢查 ret === '0000'）
     */
    private function createVendorAccount(MegawinswAccount $account): bool
    {
        try {
            $this->send('Oauth', [
                'uid' => $account->username,
                'utoken' => $account->user_token,
                'firmId' => $this->infoDetail->FIRM_ID,
                'currency' => $this->infoDetail->CURRENCY,
            ]);

            return true;
        } catch (Throwable $e) {
            Log::error('[MegawinSW][createAccount] Oauth failed', [
                'username' => $account->username,
                'error' => $e->getMessage(),
                'trace' => Common::formatTrace($e),
            ]);

            return false;
        }
    }
}
