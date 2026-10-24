<?php

declare(strict_types=1);

namespace App\Vendors\AFBSW;

use App\Data\GameInfoData;
use App\Data\Vendor\CreateAccountParamsData;
use App\Data\Vendor\CreateAccountResultData;
use App\Data\Vendor\GetGameListParamsData;
use App\Data\Vendor\GetGameListResultData;
use App\Data\VendorGameUrlParamsData;
use App\Data\VendorGameUrlResultData;
use App\Enums\Game\GameTypeEnum;
use App\Exceptions\ApiException;
use App\Models\Member;
use App\Vendors\AbstractSingleWallet;
use App\Vendors\Contracts\HasCallbackPath;
use App\Vendors\Contracts\ShouldScheduleAccountCreation;
use App\Vendors\Contracts\ShouldSyncGameList;
use App\Vendors\Models\AfbswAccount;
use App\Vendors\Models\AfbswBetRecord;
use Illuminate\Support\Facades\Http;
use Spatie\LaravelData\Data;

class Driver extends AbstractSingleWallet implements HasCallbackPath, ShouldScheduleAccountCreation, ShouldSyncGameList
{
    protected string $accountModel = AfbswAccount::class;

    protected string $betRecordModel = AfbswBetRecord::class;

    private VendorInfoDetailData $infoDetail;

    private AfbswAccount $account;

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
     * 預設回調路徑後綴，對應 routes/external/sw.php 的 afb 單一入口路由
     */
    public static function defaultCallbackPath(): string
    {
        return '/external/api/sw/afb';
    }

    public function setInfo(array $info): void
    {
        $this->infoDetail = VendorInfoDetailData::from($info);
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
        $username = $account->username;

        $data = [
            'method' => 'newAccount',
            'account' => $username,
            'nick' => '',
            'currency' => $this->infoDetail->CURRENCY,
            'agent_id' => 0,
        ];

        $result = $this->sendRequest($data);

        if (!is_array($result)) {
            return false;
        }

        if ($result['status'] != 0) {
            return false;
        }

        return true;
    }

    public function getGameList(GetGameListParamsData $params): GetGameListResultData
    {
        // 注入遊戲商戶資料
        $this->setVendorInfo($params->vendorInfo);

        $data = [
            'method' => 'gameList',
            'lang' => 'en',
        ];

        $gameList = $this->sendRequest($data);

        foreach ($gameList['game_list'] as $game) {
            $gameCode = $game['code'];

            $newGame = GameInfoData::from([
                'gameCode' => $gameCode,
                'gameName' => $game['name'],
                'imageUrl' => $game['picture'],
                'gameType' => $this->getGameType($game['type']),
                'info' => json_encode($game),
                'status' => 1,
            ]);

            $newGameList[$gameCode] = $newGame;
        }

        return GetGameListResultData::from([
            'gameList' => $newGameList,
        ]);
    }

    public function getGameUrl(VendorGameUrlParamsData $params): VendorGameUrlResultData
    {
        $username = $this->account->username;

        $data = [
            'method' => 'getLauncherUrl',
            'account' => $username,
            'currency' => $this->infoDetail->CURRENCY,
            'game' => $params->gameCode,
            'lang' => $params->language,
        ];

        $result = $this->sendRequest($data);

        if (!is_array($result)) {
            throw new ApiException('[AFBSW][GetGameUrl] API error: response is not array.');
        }

        if (!isset($result['status']) || $result['status'] != 0) {
            throw new ApiException('[AFBSW][GetGameUrl] API error: status is not 0.');
        }

        if (!isset($result['link'])) {
            throw new ApiException('[AFBSW][GetGameUrl] API error: link is not set.');
        }

        $gameUrl = $result['link'];

        return VendorGameUrlResultData::from([
            'pc' => $gameUrl,
            'mobile' => $gameUrl,
        ]);
    }

    private function sendRequest(array $data): array
    {
        $info = $this->infoDetail;

        $action = $this->getName() . ' ' . $data['method'];

        $timestamp = time();
        $host = parse_url($info->API_URL, PHP_URL_HOST);
        $contentHash = hash('sha256', json_encode($data));
        $signature = hash_hmac('sha256', $host . '-' . $info->SITE_ID . '-' . $timestamp . '-' . $contentHash, $info->API_KEY);

        $headers = [
            'Host' => $host,
            'X-Site-ID' => $info->SITE_ID,
            'X-Timestamp' => $timestamp,
            'X-Content-Hash' => $contentHash,
            'X-Signature' => $signature,
        ];

        $result = Http::logAction($action)
            ->asJson()
            ->withHeaders($headers)
            ->post($info->API_URL, $data)
            ->throw()
            ->json();

        return $result;
    }

    private function getGameType(string $gameType): int
    {
        return match ($gameType) {
            'slots' => GameTypeEnum::SLOTS->value,
            'fishing' => GameTypeEnum::FISHING->value,
            // 'blockchain' => GameTypeEnum::ARCADE->value,
            default => GameTypeEnum::EVENT_AND_OTHERS->value,
        };
    }
}

class VendorInfoDetailData extends Data
{
    public function __construct(
        public string $API_URL,
        public string $API_KEY,
        public string $SITE_ID,
        public string $CURRENCY,
    ) {}
}
