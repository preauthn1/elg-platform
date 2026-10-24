<?php

declare(strict_types=1);

namespace App\Vendors\target;

use App\Data\GameInfoData;
use App\Data\Vendor\CreateAccountParamsData;
use App\Data\Vendor\CreateAccountResultData;
use App\Data\Vendor\GetGameListParamsData;
use App\Data\Vendor\GetGameListResultData;
use App\Data\VendorGameUrlParamsData;
use App\Data\VendorGameUrlResultData;
use App\Data\VendorTransferData;
use App\Enums\Game\GameTypeEnum;
use App\Enums\General\ContextKeyEnum;
use App\Enums\LanguageEnum;
use App\Enums\Vendor\VendorChannelEnum;
use App\Enums\Vendor\VendorTransferResultEnum;
use App\Exceptions\ApiException;
use App\Helpers\Common;
use App\Models\Member;
use App\Vendors\AbstractTransferWallet;
use App\Vendors\Contracts\HasAggregatorChannel;
use App\Vendors\Contracts\HasGameList;
use App\Vendors\Contracts\ShouldScheduleAccountCreation;
use App\Vendors\Models\PgsAccount;
use App\Vendors\Models\PgsBetRecord;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Spatie\LaravelData\Data;

/**
 * target 聚合遊戲商共用 Driver：JILI / target Live / Funky 共用同一組 API，
 * 各 vendor 以自己的 vendor_infos.info 憑證（merchantID / merchantSecretKey）區分。
 *
 * @knowledge rules/game-list-sync
 */
class Driver extends AbstractTransferWallet implements HasAggregatorChannel, HasGameList, ShouldScheduleAccountCreation
{
    protected string $accountModel = PgsAccount::class;

    protected string $betRecordModel = PgsBetRecord::class;

    private VendorInfoDetailData $vendorInfoDetail;

    private string $apiUrl;

    public function __construct(int $vendorId, ?Member $member)
    {
        parent::__construct($vendorId, $member);

        if (!$member) {
            return;
        }

        $this->setInfo($this->vendorInfo->info);
    }

    /**
     * 接入通道：經由聚合商 target
     */
    public static function aggregatorChannel(): VendorChannelEnum
    {
        return VendorChannelEnum::target;
    }

    public function setInfo(array $info): void
    {
        $this->vendorInfoDetail = VendorInfoDetailData::from($info);
        $this->apiUrl = trim($this->vendorInfoDetail->API_URL, '/');
    }

    /**
     * 建立我方遊戲帳號：先落 DB 佔用流水號（member_id = -999999999 表未成功），
     * 三方建號成功才翻回 0 進入可綁定的帳號池。
     */
    public function createAccount(CreateAccountParamsData $params): CreateAccountResultData
    {
        // setVendorInfo 同時設定 $this->vendorInfo（無 member 建構時未初始化）並載入 info
        $this->setVendorInfo($params->vendorInfo);
        $username = $params->username;

        $account = $this->accountModel::create([
            'vendor_info_id' => $params->vendorInfo->id,
            'username' => $username,
            'member_id' => -999999999,
        ]);

        if (!$this->createVendorAccount($username)) {
            return CreateAccountResultData::from([
                'status' => false,
                'username' => $username,
            ]);
        }

        $account->update(['member_id' => 0]);

        return CreateAccountResultData::from([
            'status' => true,
            'username' => $username,
        ]);
    }

    /**
     * 呼叫三方建立遊戲帳號
     */
    private function createVendorAccount(string $username): bool
    {
        $url = "{$this->apiUrl}/v1/player";
        $action = 'CreateAccount';

        try {
            $response = Http::logAction("PGS_{$action}")
                ->asJson()
                ->post($url, [
                    'playerName' => $username,
                    'merchantID' => $this->vendorInfoDetail->API_APPID,
                    // 本端點實測用大寫 M 的 MerchantSecretKey（與轉帳、開遊戲端點的 merchantSecretKey 不同）
                    'MerchantSecretKey' => $this->vendorInfoDetail->API_KEY,
                ])
                ->json();
        } catch (ConnectionException $e) {
            Log::error("[target][{$action}] Connection exception", [
                'username' => $username,
                'exception' => $e,
            ]);

            return false;
        }

        if (!is_array($response) || !isset($response['code']) || $response['code'] !== 0) {
            Log::error("[target][{$action}] Invalid response: code is not 0", [
                'username' => $username,
                'vendor_info_id' => $this->vendorInfo->id,
                'code' => $response['code'] ?? null,
                'message' => $response['message'] ?? null,
            ]);

            return false;
        }

        return true;
    }

    public function getGameUrl(VendorGameUrlParamsData $params): VendorGameUrlResultData
    {
        $url = "{$this->apiUrl}/v1/game/link";
        $action = 'GetGameUrl';

        $params = [
            'playerName' => $this->vendorAccount->username,
            'merchantSecretKey' => $this->vendorInfoDetail->API_KEY,
            'merchantID' => $this->vendorInfoDetail->API_APPID,
            'GameID' => $params->gameCode,
            'lang' => $this->mapLanguageCode($params->language),
        ];
        if ($this->vendorInfoDetail->LIVE_BET_LIMIT !== null) {
            $params['liveCasinoLevelID'] = (string) $this->vendorInfoDetail->LIVE_BET_LIMIT;
        }

        $response = Http::logAction("PGS_{$action}")
            ->withHeaders([
                'Content-Type' => 'application/json',
            ])
            ->get($url, $params)
            ->throw()
            ->json();

        if (!is_array($response)) {
            throw new ApiException("[target][{$action}] Invalid response: not json array");
        }

        if (!isset($response['code'])) {
            throw new ApiException("[target][{$action}] Invalid response: no code");
        }

        if ($response['code'] !== 0) {
            throw new ApiException("[target][{$action}] Invalid response: code is not 0, code: {$response['code']}, msg: {$response['message']}");
        }

        if (!isset($response['data']['link'])) {
            throw new ApiException("[target][{$action}] Invalid response: no link in data");
        }

        return VendorGameUrlResultData::from([
            'pc' => $response['data']['link'],
            'mobile' => $response['data']['link'],
        ]);
    }

    public function getBalance(): string
    {
        $url = "{$this->apiUrl}/v1/wallet/amount";
        $action = 'GetBalance';

        $params = [
            'playerName' => $this->vendorAccount->username,
            'merchantSecretKey' => $this->vendorInfoDetail->API_KEY,
            'merchantID' => $this->vendorInfoDetail->API_APPID,
        ];

        $response = Http::logAction("PGS_{$action}")
            ->withHeaders([
                'Content-Type' => 'application/json',
            ])
            ->get($url, $params)
            ->throw()
            ->json();

        if (!is_array($response)) {
            throw new ApiException("[target][{$action}] Invalid response: not json array");
        }

        if (!isset($response['code'])) {
            throw new ApiException("[target][{$action}] Invalid response: no code");
        }

        if ($response['code'] !== 0) {
            throw new ApiException("[target][{$action}] Invalid response: code is not 0, code: {$response['code']}, msg: {$response['message']}");
        }

        if (!isset($response['data']['amount'])) {
            throw new ApiException("[target][{$action}] Invalid response: no amount in data");
        }

        return Common::numFloor($response['data']['amount'], 4);
    }

    /**
     * 取得遊戲清單
     */
    public function getGameList(GetGameListParamsData $params): GetGameListResultData
    {
        $this->setVendorInfo($params->vendorInfo);

        $url = "{$this->apiUrl}/v1/games";
        $action = 'GetGameList';

        $response = Http::logAction("PGS_{$action}")
            ->withHeaders([
                'locale' => 'en-us',
            ])
            ->get($url, [
                'merchantID' => $this->vendorInfoDetail->API_APPID,
                // 本端點實測用大寫 M 的 MerchantSecretKey（與其他端點的 merchantSecretKey 不同）
                'MerchantSecretKey' => $this->vendorInfoDetail->API_KEY,
            ])
            ->throw()
            ->json();

        if (!is_array($response) || !isset($response['code']) || $response['code'] !== 0) {
            Log::error("[target][{$action}] Invalid response: code is not 0", [
                'vendor_id' => $this->vendor->id,
                'vendor_info_id' => $params->vendorInfo->id,
                'code' => $response['code'] ?? null,
                'message' => $response['message'] ?? null,
            ]);

            return GetGameListResultData::from(['gameList' => []]);
        }

        return $this->formatGameList($response['data'] ?? []);
    }

    /**
     * @param  array<int, array<string, mixed>>  $gameList
     */
    private function formatGameList(array $gameList): GetGameListResultData
    {
        $data = [];

        foreach ($gameList as $game) {
            $gameCode = (string) ($game['gameID'] ?? '');
            if ($gameCode === '') {
                continue;
            }

            $data[$gameCode] = GameInfoData::from([
                'gameCode' => $gameCode,
                'gameName' => (string) ($game['gameName'] ?? $gameCode),
                'imageUrl' => (string) ($game['imageUrl'] ?? ''),
                'gameType' => $this->convertGameType((int) ($game['gameType'] ?? 0)),
                // 開遊戲以 gameID 為準；gameCode 為廠商別名代碼，存 remark 供對照
                'remark' => isset($game['gameCode']) ? (string) $game['gameCode'] : null,
                'info' => json_encode($game),
                'status' => 1,
            ]);
        }

        return GetGameListResultData::from(['gameList' => $data]);
    }

    /**
     * target 遊戲分類碼映射：
     * - 1000 老虎機 -> SLOTS、2000 棋牌 -> TABLE、2500 視訊 -> LIVE_CASINO
     * - 3000 機台 / 4000 角鬥場 / 6000 柏青斯洛 -> ARCADE
     * - 5000 彩票樂透 -> LOTTERY、7000 捕魚 -> FISHING，未列出回 0（未分類）
     */
    private function convertGameType(int $type): int
    {
        return match ($type) {
            1000 => GameTypeEnum::SLOTS->value,
            2000 => GameTypeEnum::TABLE->value,
            2500 => GameTypeEnum::LIVE_CASINO->value,
            3000, 4000, 6000 => GameTypeEnum::ARCADE->value,
            5000 => GameTypeEnum::LOTTERY->value,
            7000 => GameTypeEnum::FISHING->value,
            default => 0,
        };
    }

    public function checkTransferStatus(string $transactionNo): VendorTransferResultEnum
    {
        $url = "{$this->apiUrl}/v1/transfer";
        $action = 'CheckTransferStatus';

        $params = [
            'merchantTransactionNo' => $transactionNo,
            'merchantSecretKey' => $this->vendorInfoDetail->API_KEY,
            'merchantID' => $this->vendorInfoDetail->API_APPID,
        ];
        $params = http_build_query($params);

        try {
            $response = Http::logAction("PGS_{$action}")
                ->get($url . '?' . $params)
                ->json();
        } catch (ConnectionException $e) {
            Log::error("[target][{$action}] Connection exception", ['exception' => $e]);

            return VendorTransferResultEnum::TIMEOUT;
        }

        if (!is_array($response)) {
            Log::error("[target][{$action}] Invalid response: not json array");

            return VendorTransferResultEnum::TIMEOUT; // 無法判斷的錯誤，先不還錢
        }

        if (!isset($response['code'])) {
            Log::error("[target][{$action}] Invalid response: no code");

            return VendorTransferResultEnum::TIMEOUT; // 無法判斷的錯誤，先不還錢
        }

        if ($response['code'] != 0 || empty($response['data'])) {
            return VendorTransferResultEnum::FAILED;
        }

        return VendorTransferResultEnum::SUCCESS;
    }

    /**
     * 玩家餘額轉入遊戲商 (存款), origin: type = 1
     */
    public function transferToVendor(VendorTransferData $params): VendorTransferResultEnum
    {
        $url = "{$this->apiUrl}/v1/wallet/transfer-in";

        return $this->transfer($params, $url, 'TransferIn');
    }

    /**
     * 轉出遊戲商餘額回本地 (提款), origin: type != 1
     */
    public function transferToLocal(VendorTransferData $params): VendorTransferResultEnum
    {
        $url = "{$this->apiUrl}/v1/wallet/transfer-out";

        return $this->transfer($params, $url, 'TransferOut');
    }

    private function transfer(VendorTransferData $params, string $url, string $action): VendorTransferResultEnum
    {
        $params = [
            'playerName' => $this->vendorAccount->username,
            'merchantSecretKey' => $this->vendorInfoDetail->API_KEY,
            'merchantID' => $this->vendorInfoDetail->API_APPID,
            'amount' => $params->vendorAmount,
            'merchantOrderNo' => $params->transactionNo,
        ];

        try {
            $response = Http::logAction("PGS_{$action}")
                ->asJson()
                ->post($url, $params)
                ->json();
        } catch (ConnectionException $e) {
            Log::error("[target][{$action}] Connection exception", ['exception' => $e]);

            return VendorTransferResultEnum::TIMEOUT;
        }

        if (!is_array($response)) {
            Log::error("[target][{$action}] Invalid response: not json array");

            return VendorTransferResultEnum::TIMEOUT; // 無法判斷的錯誤，先不還錢
        }

        if (!isset($response['code'])) {
            Log::error("[target][{$action}] Invalid response: no code");

            return VendorTransferResultEnum::TIMEOUT; // 無法判斷的錯誤，先不還錢
        }

        if ($response['code'] !== 0) {
            Log::error("[target][{$action}] Invalid response: code is not 0, code: {$response['code']}, msg: {$response['message']}");

            return VendorTransferResultEnum::FAILED; // FAILED
        }

        return VendorTransferResultEnum::SUCCESS;
    }

    private function mapLanguageCode(?LanguageEnum $language = null): string
    {
        if (empty($language)) {
            $languageId = Context::getHidden(ContextKeyEnum::LANGUAGE_ID->value);
        } else {
            $languageId = $language->value;
        }

        return match ($languageId) {
            LanguageEnum::ZH_TW->value => 'zh-tw',
            LanguageEnum::ZH_CN->value => 'zh-cn',
            default => 'en',
        };
    }
}

class VendorInfoDetailData extends Data
{
    public function __construct(
        public string $API_URL,
        public string $API_APPID,
        public string $API_KEY,
        public null|int|string $LIVE_BET_LIMIT = null,
    ) {}
}
