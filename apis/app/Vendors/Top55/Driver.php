<?php

declare(strict_types=1);

namespace App\Vendors\Top55;

use App\Data\GameInfoData;
use App\Data\Vendor\CreateAccountParamsData;
use App\Data\Vendor\CreateAccountResultData;
use App\Data\Vendor\GetGameListParamsData;
use App\Data\Vendor\GetGameListResultData;
use App\Data\VendorGameUrlParamsData;
use App\Data\VendorGameUrlResultData;
use App\Enums\Game\GameTypeEnum;
use App\Enums\General\CacheTtl;
use App\Enums\Vendor\VendorChannelEnum;
use App\Enums\Vendor\VendorIdEnum;
use App\Exceptions\ApiException;
use App\Models\Member;
use App\Vendors\AbstractSingleWallet;
use App\Vendors\Contracts\HasAggregatorChannel;
use App\Vendors\Contracts\HasCallbackPath;
use App\Vendors\Contracts\ShouldScheduleAccountCreation;
use App\Vendors\Contracts\ShouldSyncGameList;
use App\Vendors\Models\Top55swAccount;
use App\Vendors\Models\Top55swBetRecord;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * @property Top55swAccount $vendorAccount
 */
class Driver extends AbstractSingleWallet implements HasAggregatorChannel, HasCallbackPath, ShouldScheduleAccountCreation, ShouldSyncGameList
{
    protected string $vendorName = 'Top55 SW';

    protected string $accountModel = Top55swAccount::class;

    protected string $betRecordModel = Top55swBetRecord::class;

    protected array $config = [];

    const VENDOR_CODE_MAP = [
        VendorIdEnum::TOP55SW_DG->value => 'casino-dream',
        VendorIdEnum::TOP55SW_MG->value => 'casino-micro',
        VendorIdEnum::TOP55SW_PL->value => 'casino-pragmatic',
        VendorIdEnum::TOP55SW_SA->value => 'casino-sa',
        VendorIdEnum::TOP55SW_WG->value => 'slot-wg',
        VendorIdEnum::TOP55SW_NOLIMITCITY->value => 'slot-nolimitcity',
        VendorIdEnum::TOP55SW_ATG->value => 'slot-atg',
        VendorIdEnum::TOP55SW_KA->value => 'slot-ka',
        VendorIdEnum::TOP55SW_HACKSAW->value => 'slot-hacksaw',
        VendorIdEnum::TOP55SW_POPIPLAY->value => 'slot-popiplay',
        VendorIdEnum::TOP55SW_EVOPLAY->value => 'slot-evoplay',
        VendorIdEnum::TOP55SW_MASCOT->value => 'slot-mascot',
        VendorIdEnum::TOP55SW_POPOK->value => 'slot-popok',
        VendorIdEnum::TOP55SW_CQ9->value => 'slot-cq9',
        VendorIdEnum::TOP55SW_TADA->value => 'slot-tada',
        VendorIdEnum::TOP55SW_JJBX->value => 'slot-homeslot',
        VendorIdEnum::TOP55SW_FACHAI->value => 'slot-fachai',
        VendorIdEnum::TOP55SW_JDB->value => 'slot-jdb',
        VendorIdEnum::TOP55SW_3OAKS->value => 'slot-3oaks',
        VendorIdEnum::TOP55SW_JILI->value => 'slot-jili',
        VendorIdEnum::TOP55SW_RUBY->value => 'slot-rubyplay',
        VendorIdEnum::TOP55SW_PLAYSON->value => 'slot-playson',
        VendorIdEnum::TOP55SW_DREAMTECH->value => 'slot-dreamtech',
        VendorIdEnum::TOP55SW_BOOONGO->value => 'slot-booongo',
        VendorIdEnum::TOP55SW_PP->value => 'slot-pragmatic',
        VendorIdEnum::TOP55SW_PGSOFT->value => 'slot-pgsoft',
        VendorIdEnum::TOP55SW_HABANERO->value => 'slot-habanero',
        VendorIdEnum::TOP55SW_AVIATOR->value => 'mini-aviator',
        VendorIdEnum::TOP55SW_SPRIBE->value => 'mini-spribe',
        VendorIdEnum::TOP55SW_INOUT->value => 'mini-inout',
        VendorIdEnum::TOP55SW_JDB_FISHING->value => 'fishing-jdb',
    ];

    public function __construct(int $vendorId, ?Member $member)
    {
        parent::__construct($vendorId, $member);

        if (!$member) {
            return;
        }

        $this->setInfo($this->vendorInfo->info);
    }

    /**
     * 接入通道：經由聚合商 Top55SW
     */
    public static function aggregatorChannel(): VendorChannelEnum
    {
        return VendorChannelEnum::TOP55SW;
    }

    /**
     * 預設回調路徑後綴，對應 routes/external/sw.php 的 top55 路由群組
     */
    public static function defaultCallbackPath(): string
    {
        return '/external/api/sw/top55';
    }

    public function setInfo(array $info): void
    {
        /**
         * API_URL
         * clientId
         * clientSecret
         */
        $this->config = $info;
    }

    /**
     * 建立我方遊戲商帳號
     */
    public function createAccount(CreateAccountParamsData $params): CreateAccountResultData
    {
        // 注入遊戲商戶資料
        $this->setVendorInfo($params->vendorInfo);

        $data = [
            'vendor_info_id' => $this->vendorInfo->id,
            'username' => $params->username,
        ];
        $account = $this->accountModel::create($data);

        if (!$account) {
            return CreateAccountResultData::from([
                'status' => false,
                'username' => $params->username,
            ]);
        }

        // 呼叫三方 API 建立遊戲商帳號
        if (!$this->createVendorAccount($account)) {
            // 如果失敗就刪除這筆記錄
            $account->update(['member_id' => -999999999]);

            return CreateAccountResultData::from([
                'status' => false,
                'username' => $params->username,
            ]);
        }

        return CreateAccountResultData::from([
            'status' => true,
            'username' => $params->username,
        ]);
    }

    /**
     * 建立三方遊戲商帳號
     */
    protected function createVendorAccount($account): bool
    {
        $data = [
            'userCode' => $account->username,
        ];

        $result = $this->sendApiRequest(path: 'user/create', data: $data);

        if ($result['success'] === true) {
            return true;
        }

        Log::error('top55 createVendorAccount failed', ['response' => $result]);

        return false;
    }

    /**
     * 遊戲清單 (寫死一組大廳)
     */
    public function getGameList(GetGameListParamsData $params): GetGameListResultData
    {
        // 注入遊戲商戶資料
        $this->setVendorInfo($params->vendorInfo);

        [$vendorCode, $gameType] = $vendorCode = $this->getVendorCode($params->vendorInfo->vendor_id);

        $newGameList = [];
        $data = [
            'vendorCode' => $vendorCode,
            'language' => 'en',
        ];

        $gameList = $this->sendApiRequest(path: 'games/list', data: $data);

        if ($gameList['success'] === false) {
            Log::warning('top55 getGameList failed', ['data' => $data, 'response' => $gameList]);

            throw new ApiException('top55 getGameList failed');
        }

        foreach ($gameList['message'] as $game) {
            $gameCode = $game['gameCode'];

            $newGame = GameInfoData::from([
                'gameCode' => $gameCode,
                'gameName' => $game['gameName'],
                'imageUrl' => $game['thumbnail'],
                'gameType' => $gameType,
                'remark' => $game['vendorCode'], // getGameUrl 會用到
                'info' => json_encode($game),
                'status' => 1,
            ]);

            $newGameList[$gameCode] = $newGame;
        }

        return GetGameListResultData::from([
            'gameList' => $newGameList,
        ]);
    }

    /**
     * 3.0 Player Login
     */
    public function getGameUrl(VendorGameUrlParamsData $params): VendorGameUrlResultData
    {
        $data = [
            'vendorCode' => $params->remark,
            'gameCode' => $params->gameCode,
            'userCode' => $this->vendorAccount->username,
            'language' => $this->getVendorLanguage($params->language->value),
        ];

        $result = $this->sendApiRequest(path: 'game/launch-url', data: $data);

        if ($result['success'] === false) {
            throw new ApiException(sprintf('The three-party response errorCode:  (%s) %s', $result['errorCode'], $result['message'] ?? ''));
        }

        return VendorGameUrlResultData::from([
            'mobile' => $result['message'],
            'pc' => $result['message'],
        ]);
    }

    private function sendApiRequest(string $path, array $data = [], string $mothod = 'POST', int $retry = 1): array
    {
        try {
            if ($retry > 3) {
                throw new ApiException('top55 retry too many times');
            }

            $url = $this->config['API_URL'] . '/' . $path;

            $header = [
                'Authorization' => 'Bearer ' . $this->getToken(),
            ];

            $request = Http::logAction($url, $data)
                ->withHeaders($header)
                ->asJson()
                ->throw();

            switch ($mothod) {
                case 'POST':
                    $request = $request->post($url, $data);
                    break;
                case 'GET':
                    $request = $request->get($url, $data);
                    break;
                default:
                    $request = $request->post($url, $data);
            }

            $response = $request->json();

            return $response;
        } catch (\Exception $e) {
            if ($e->getCode() === 401) {
                Log::warning('top55 ' . $this->config['clientId'] . ' token expired');
                Cache::forget('top55_token:' . $this->config['clientId']);

                return $this->sendApiRequest(path: $path, data: $data, mothod: $mothod, retry: $retry + 1);
            }

            throw $e;
        }
    }

    private function getToken(): string
    {
        // safeRemember 原子鎖防擊穿：三方 createtoken 限流 5 次/30 秒，
        // 整點 20+ 個 SyncVendorGameList job 同時 cache miss 會並發搶建 token 撞 429
        return Cache::safeRemember('top55_token:' . $this->config['clientId'], CacheTtl::HALF_DAY->value, function () {
            $url = $this->config['API_URL'] . '/auth/createtoken';

            $data = [
                'clientId' => $this->config['clientId'],
                'clientSecret' => $this->config['clientSecret'],
            ];

            $response = Http::logAction($url, $data)
                ->asJson()
                ->post($url, $data)
                ->throw()
                ->json();

            return $response['token'];
        });

    }

    private function getVendorCode(int $vendorId): array
    {
        $vendorCode = null;

        if (isset(self::VENDOR_CODE_MAP[$vendorId])) {
            $vendorCode = self::VENDOR_CODE_MAP[$vendorId];
        }

        if (isset($this->config['vendorCode'])) {
            $vendorCode = $this->config['vendorCode'];
        }

        if ($vendorCode === null) {
            throw new ApiException('vendorCode not set');
        }

        $vendorList = $this->sendApiRequest(path: 'vendors/list', mothod: 'GET');

        if ($vendorList['success'] === false) {
            throw new ApiException($vendorList['message']);
        }

        foreach ($vendorList['message'] as $item) {
            if ($item['vendorCode'] === $vendorCode) {
                return [
                    $vendorCode,
                    $this->getGameType($item['type']),
                ];
            }
        }

        throw new ApiException('vendorCode not found');
    }

    private function getGameType($gameType): int
    {
        return match ($gameType) {
            1 => GameTypeEnum::LIVE_CASINO->value,
            2 => GameTypeEnum::SLOTS->value,
            4 => GameTypeEnum::FISHING->value,
            default => GameTypeEnum::EVENT_AND_OTHERS->value,
        };
    }

    private function getVendorLanguage($languageCode): string
    {
        return match ($languageCode) {
            1 => 'en',
            2 => 'zh',
            3 => 'zh',
            4 => 'ms',
            5 => 'id',
            6 => 'vi',
            7 => 'th',
            default => 'en',
        };
    }
}
