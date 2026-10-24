<?php

declare(strict_types=1);

namespace App\Vendors\MotivationSW;

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
use App\Helpers\Common;
use App\Models\Member;
use App\Vendors\AbstractSingleWallet;
use App\Vendors\Contracts\HasCallbackPath;
use App\Vendors\Contracts\ShouldScheduleAccountCreation;
use App\Vendors\Contracts\ShouldSyncGameList;
use App\Vendors\Models\MotivationswAccount;
use App\Vendors\Models\MotivationswBetRecord;
use App\Vendors\MotivationSW\Datas\VendorInfoDetailData;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class Driver extends AbstractSingleWallet implements HasCallbackPath, ShouldScheduleAccountCreation, ShouldSyncGameList
{
    protected string $accountModel = MotivationswAccount::class;

    protected string $betRecordModel = MotivationswBetRecord::class;

    private MotivationswAccount $account;

    private VendorInfoDetailData $detailInfo;

    public function __construct(int $vendorId, ?Member $member)
    {
        parent::__construct($vendorId, $member);

        if (!$member) {
            return;
        }

        $this->setInfo($this->vendorInfo->info);
        $this->account = $this->vendorAccount;
        $this->detailInfo = VendorInfoDetailData::from($this->vendorInfo->info);
    }

    /**
     * 預設回調路徑後綴，對應 routes/external/sw.php 的 motivation 路由群組
     */
    public static function defaultCallbackPath(): string
    {
        return '/external/api/sw/motivation';
    }

    public function setInfo(array $info): void
    {
        $this->detailInfo = VendorInfoDetailData::from($info);
    }

    public function getGameUrl(VendorGameUrlParamsData $params): VendorGameUrlResultData
    {
        $gameInfo = json_decode($params->info, true);
        if (empty($gameInfo)) {
            throw new \Exception('Game info is empty, please check the value');
        }

        $postData = [
            'account' => $this->account->username,
            'gamehall' => $gameInfo['gamehall'],
            'gamecode' => $gameInfo['gamecode'],
            'gameplat' => 'web',
            'lang' => $this->mapLanguageCode($params->language),
        ];

        $this->check();

        $header = [
            'Authorization' => $this->detailInfo->token,
        ];

        $response = Http::logAction('motivationsw_SW_api_call_game_url')
            ->asForm()
            ->withHeaders($header)
            ->post($this->detailInfo->apiUrl . '/gameboy/player/sw/gamelink', $postData)
            ->throw()
            ->json();

        if (!is_array($response) || empty($response)) {
            throw new ApiException('[MotivationSW][getGameUrl] response is not valid format');
        }

        if ($response['status']['code'] != 0) {
            throw new ApiException('[MotivationSW][getGameUrl] ' . $response['status']['message']);
        }

        return VendorGameUrlResultData::from([
            'pc' => $response['data']['url'],
            'mobile' => $response['data']['url'],
        ]);
    }

    /**
     * 遊戲清單（兩段式：先取遊戲廳清單，再逐廳取遊戲；
     * info 必存原始遊戲資料——getGameUrl 依賴其中的 gamehall/gamecode）
     */
    public function getGameList(GetGameListParamsData $params): GetGameListResultData
    {
        $this->setVendorInfo($params->vendorInfo);
        $this->check();

        $typeMap = [
            'slot' => GameTypeEnum::SLOTS->value,
            'fish' => GameTypeEnum::FISHING->value,
            'arcade' => GameTypeEnum::ARCADE->value,
            'table' => GameTypeEnum::POKER->value,
        ];

        $halls = $this->sendGet('/gameboy/game/halls');

        $data = [];
        foreach ($halls['data'] ?? [] as $hall) {
            $gameList = $this->sendGet('/gameboy/game/list/' . $hall['gamehall']);

            foreach ($gameList['data'] ?? [] as $game) {
                $gameCode = (string) $game['gamecode'];
                $data[$gameCode] = GameInfoData::from([
                    'gameCode' => $gameCode,
                    'gameName' => $game['gamename'],
                    'imageUrl' => '',
                    'gameType' => $typeMap[$game['gametype'] ?? ''] ?? GameTypeEnum::EVENT_AND_OTHERS->value,
                    'status' => 1,
                    'info' => json_encode($game),
                ]);
            }
        }

        return GetGameListResultData::from(['gameList' => $data]);
    }

    private function sendGet(string $path): ?array
    {
        return Http::logAction('motivationsw_SW_api_call_' . trim($path, '/'))
            ->withHeaders(['Authorization' => $this->detailInfo->token])
            ->get($this->detailInfo->apiUrl . $path)
            ->throw()
            ->json();
    }

    private function check(): void
    {
        if (empty($this->detailInfo)) {
            throw new ApiException('VendorInfo detail is empty, use setVendorInfo() to set the value');
        }

        if (empty($this->detailInfo->apiUrl)) {
            throw new ApiException("VendorInfo detail's apiUrl is empty, please check the value");
        }

        if (empty($this->detailInfo->token)) {
            throw new ApiException("VendorInfo detail's token is empty, please check the value");
        }
    }

    private function mapLanguageCode(?LanguageEnum $language = null): string
    {
        if (empty($language)) {
            $languageId = Context::getHidden(ContextKeyEnum::LANGUAGE_ID->value);
        } else {
            $languageId = $language->value;
        }

        return match ($languageId) {
            LanguageEnum::ZH_TW->value => 'zh-cn',
            LanguageEnum::ZH_CN->value => 'zh-cn',
            LanguageEnum::VI_VN->value => 'vn',
            LanguageEnum::TH_TH->value => 'th',
            default => 'en',
        };
    }

    /**
     * 建立我方遊戲商帳號（遷自 RD-Studio create_motiv_sw_account）
     *
     * 三方以 lobbylink 當註冊入口，無密碼、無簽名。
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
        $this->check();

        try {
            $response = Http::logAction('motivationsw_SW_api_call_createAccount')
                ->asForm()
                ->withHeaders(['Authorization' => $this->detailInfo->token])
                ->post($this->detailInfo->apiUrl . '/gameboy/player/sw/lobbylink', ['account' => $username])
                ->throw()
                ->json();
        } catch (Throwable $e) {
            Log::error('[MotivationSW][createAccount] request failed', [
                'username' => $username,
                'error' => $e->getMessage(),
                'trace' => Common::formatTrace($e),
            ]);

            return false;
        }

        if (!is_array($response) || (int) ($response['status']['code'] ?? -1) !== 0) {
            Log::error('[MotivationSW][createAccount] status code is not 0', [
                'username' => $username,
                'response' => $response,
            ]);

            return false;
        }

        return true;
    }
}
