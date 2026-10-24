<?php

declare(strict_types=1);

namespace App\Vendors\Dct;

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
use App\Vendors\Contracts\ShouldScheduleAccountCreation;
use App\Vendors\Contracts\ShouldSyncGameList;
use App\Vendors\Models\DctAccount;
use App\Vendors\Models\DctBetRecord;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Spatie\LaravelData\Data;
use Throwable;

/**
 * @knowledge rules/game-list-sync
 */
class Driver extends AbstractTransferWallet implements HasAggregatorChannel, ShouldScheduleAccountCreation, ShouldSyncGameList
{
    protected string $accountModel = DctAccount::class;

    protected string $betRecordModel = DctBetRecord::class;

    private VendorInfoDetailData $infoDetail;

    public function __construct(int $vendorId, ?Member $member)
    {
        parent::__construct($vendorId, $member);

        if (!$member) {
            return;
        }

        $this->setInfo($this->vendorInfo->info);
    }

    /**
     * 接入通道：經由聚合商 DCT
     */
    public static function aggregatorChannel(): VendorChannelEnum
    {
        return VendorChannelEnum::DCT;
    }

    public function setInfo(array $info): void
    {
        $this->infoDetail = VendorInfoDetailData::from($info);
    }

    public function getGameUrl(VendorGameUrlParamsData $params): VendorGameUrlResultData
    {
        $action = 'GetGameUrl';
        $postData = [
            'brand_id' => $this->infoDetail->BRAND_ID,
            'sign' => $this->sign($this->vendorAccount->username),
            'brand_uid' => $this->vendorAccount->username,
            'game_id' => $params->gameCode,
            'currency' => $this->infoDetail->CURRENCY,
            'language' => $this->mapLanguageCode(),
            'channel' => 'pc',
            'country_code' => $this->infoDetail->COUNTRY_CODE,
        ];

        $response = Http::logAction("Dct_{$action}", $postData)
            ->asJson()
            ->post("{$this->infoDetail->API_URL}/dct/loginGame", $postData)
            ->throw()
            ->json();

        if (!is_array($response) || !isset($response['code'])) {
            throw new ApiException("[Dct][{$action}] API error: response data is not correct.");
        }

        if ((int) $response['code'] !== 1000) {
            throw new ApiException("[Dct][{$action}] API error: code is not 1000.");
        }

        if (!isset($response['data']['game_url'])) {
            throw new ApiException("[Dct][{$action}] API error: game_url is not set.");
        }

        return new VendorGameUrlResultData(
            pc: $response['data']['game_url'],
            mobile: $response['data']['game_url']
        );
    }

    public function getBalance(): string
    {
        $action = 'GetBalance';
        $postData = [
            'brand_id' => $this->infoDetail->BRAND_ID,
            'sign' => $this->sign($this->vendorAccount->username),
            'brand_uid' => $this->vendorAccount->username,
            'currency' => $this->infoDetail->CURRENCY,
        ];

        $response = Http::logAction("Dct_{$action}", $postData)
            ->asJson()
            ->post("{$this->infoDetail->API_URL}/dct/getBalance", $postData)
            ->throw()
            ->json();

        if (!is_array($response) || !isset($response['code'])) {
            throw new ApiException("[Dct][{$action}] API error: response data is not correct.");
        }

        if ((int) $response['code'] !== 1000) {
            throw new ApiException("[Dct][{$action}] API error: code is not 1000.");
        }

        if (!isset($response['data']['balance'])) {
            throw new ApiException("[Dct][{$action}] API error: balance is not set.");
        }

        return Common::numFloor($response['data']['balance'], 4);
    }

    public function checkTransferStatus(string $transactionNo): VendorTransferResultEnum
    {
        $action = 'CheckTransferStatus';
        $postData = [
            'brand_id' => $this->infoDetail->BRAND_ID,
            'sign' => $this->sign($transactionNo),
            'brand_uid' => $this->vendorAccount->username,
            'currency' => $this->infoDetail->CURRENCY,
            'bill_no' => $transactionNo,
        ];

        $response = Http::logAction("Dct_{$action}", $postData)
            ->asJson()
            ->post("{$this->infoDetail->API_URL}/dct/checkTransferStatus", $postData)
            ->throw()
            ->json();

        if (!is_array($response)) {
            Log::error("[Dct][{$action}] API error: response data is not array.", ['response' => $response]);

            return VendorTransferResultEnum::TIMEOUT;
        }

        if (!isset($response['code'])) {
            Log::error("[Dct][{$action}] API error: response data is not correct, code is not set.", ['response' => $response]);

            return VendorTransferResultEnum::TIMEOUT;
        }

        if ((int) $response['code'] === 1001 || (int) $response['code'] === 1002) {
            Log::error("[Dct][{$action}] API error: code is not 1001 or 1002, can't verify result.", ['response' => $response]);

            return VendorTransferResultEnum::TIMEOUT;
        }

        if ((int) $response['code'] !== 1000) {
            Log::error("[Dct][{$action}] API error: code is not 1000, failed.", ['response' => $response]);

            return VendorTransferResultEnum::FAILED;
        }

        return VendorTransferResultEnum::SUCCESS;
    }

    /**
     * 玩家餘額轉入遊戲商 (存款), origin: type = 1
     */
    public function transferToVendor(VendorTransferData $params): VendorTransferResultEnum
    {
        return $this->transfer($params, 'Deposit', 'dct/credit');
    }

    /**
     * 轉出遊戲商餘額回本地 (提款), origin: type != 1
     */
    public function transferToLocal(VendorTransferData $params): VendorTransferResultEnum
    {
        return $this->transfer($params, 'Withdrawal', 'dct/withdraw');
    }

    private function transfer(VendorTransferData $params, string $action, string $endPoint): VendorTransferResultEnum
    {
        $postData = [
            'brand_id' => $this->infoDetail->BRAND_ID,
            'sign' => $this->sign($this->vendorAccount->username),
            'brand_uid' => $this->vendorAccount->username,
            'amount' => $params->vendorAmount,
            'bill_no' => $params->transactionNo,
            'currency' => $this->infoDetail->CURRENCY,
            'country_code' => $this->infoDetail->COUNTRY_CODE,
        ];

        $response = Http::logAction("Dct_{$action}", $postData)
            ->asJson()
            ->post("{$this->infoDetail->API_URL}/{$endPoint}", $postData)
            ->throw()
            ->json();

        if (!is_array($response)) {
            Log::error("[Dct][{$endPoint}] API error: response data is not array.", ['response' => $response]);

            return VendorTransferResultEnum::TIMEOUT;
        }

        if (!isset($response['code'])) {
            Log::error("[Dct][{$endPoint}] API error: response data is not correct, code is not set.", ['response' => $response]);

            return VendorTransferResultEnum::TIMEOUT;
        }

        if ((int) $response['code'] === 1001 || (int) $response['code'] === 1002) {
            Log::error("[Dct][{$endPoint}] API error: code is not 1001 or 1002, can't verify result.", ['response' => $response]);

            return VendorTransferResultEnum::TIMEOUT;
        }

        if ((int) $response['code'] !== 1000) {
            Log::error("[Dct][{$endPoint}] API error: code is not 1000, failed.", ['response' => $response]);

            return VendorTransferResultEnum::FAILED;
        }

        return VendorTransferResultEnum::SUCCESS;
    }

    /**
     * 遊戲清單（/dct/getGameList，遷自 RD-Studio get_dct_games）
     *
     * 聚合商：DCT／PNG／RELAX／HS 共用本 Driver，以商戶 `info.PROVIDER` 指定底層遊戲商取清單，
     * 標 ShouldSyncGameList 後四個 vendor id 一併進排程。
     * 寫入交給 GameListSyncService（純新增；DB 有、API 無僅偵測提示不停用）；
     * 舊指令 `--game_update` 覆寫既有簡中名稱的行為不沿用，既有欄位由後台人工維護。
     */
    public function getGameList(GetGameListParamsData $params): GetGameListResultData
    {
        $this->setVendorInfo($params->vendorInfo);

        $action = 'GetGameList';
        $postData = [
            'brand_id' => $this->infoDetail->BRAND_ID,
            'sign' => $this->signGameList(),
            'provider' => $this->infoDetail->PROVIDER,
        ];

        $response = Http::logAction("Dct_{$action}", $postData)
            ->asJson()
            ->post("{$this->infoDetail->API_URL}/dct/getGameList", $postData)
            ->throw()
            ->json();

        // HTTP 層錯誤由上方 throw() 拋出；HTTP 200 但 code 非 1000 回空清單並記 log，
        // 呼叫端對空清單會提前 return，不會讓「三方未提供」偵測把整批遊戲誤報
        if (!is_array($response) || (int) ($response['code'] ?? 0) !== 1000) {
            Log::error('[Dct][GetGameList] Invalid response: code is not 1000', [
                'vendor_id' => $this->vendor->id,
                'vendor_info_id' => $params->vendorInfo->id,
                'code' => $response['code'] ?? null,
                'msg' => $response['msg'] ?? null,
            ]);

            return GetGameListResultData::from(['gameList' => []]);
        }

        $gameList = [];
        $unknownTypes = [];
        foreach ($response['data'] ?? [] as $game) {
            // 缺 game_id 的列直接略過：空字串當唯一鍵會互相覆蓋，也會讓「三方未提供」偵測誤判
            if (!isset($game['game_id']) || $game['game_id'] === '') {
                continue;
            }

            $gameCode = (string) $game['game_id'];
            $rawType = (string) ($game['game_type'] ?? '');
            $gameType = $this->convertGameType($rawType);
            if ($gameType === GameTypeEnum::EVENT_AND_OTHERS) {
                $unknownTypes[$rawType] = $gameCode;
            }

            $gameInfo = GameInfoData::from([
                'gameCode' => $gameCode,
                'gameName' => (string) ($game['game_name'] ?? ''),
                'imageUrl' => (string) ($game['game_icon'] ?? ''),
                'gameType' => $gameType->value,
                'status' => 0,
                // 三方整列（rtp、content_type、release_date 等）落 example.invalid 供營運比對
                'info' => json_encode($game, JSON_UNESCAPED_UNICODE),
            ]);

            $chinese = (string) ($game['game_name_cn'] ?? '');
            if ($chinese !== '') {
                $gameInfo->setGameNames(LanguageEnum::ZH_CN->value, $chinese);
            }

            $gameList[$gameCode] = $gameInfo;
        }

        if (!empty($unknownTypes)) {
            Log::warning('[Dct][GetGameList] 遊戲清單出現對應表外的遊戲類型', [
                'vendor_info_id' => $params->vendorInfo->id,
                'unknown_types' => $unknownTypes,
            ]);
        }

        return GetGameListResultData::from(['gameList' => $gameList]);
    }

    /**
     * 三方 `game_type` 為自由文字（Slot、Video Slot、InstantWin、Scratchcard…），沿用舊指令以關鍵字比對；
     * 表外的值落「活動與其他」
     */
    private function convertGameType(string $rawType): GameTypeEnum
    {
        return match (true) {
            (bool) preg_match('/slot/i', $rawType) => GameTypeEnum::SLOTS,
            (bool) preg_match('/instantwin|scratchcard/i', $rawType) => GameTypeEnum::ARCADE,
            default => GameTypeEnum::EVENT_AND_OTHERS,
        };
    }

    private function sign(string $username): string
    {
        $str = $this->infoDetail->BRAND_ID . $username . $this->infoDetail->API_KEY;

        return strtoupper(md5($str));
    }

    /**
     * getGameList 專用簽名：拼接只有 `brand_id + api_key` 兩段，與其他端點不同，不可共用 sign()
     */
    private function signGameList(): string
    {
        return strtoupper(md5($this->infoDetail->BRAND_ID . $this->infoDetail->API_KEY));
    }

    private function mapLanguageCode(?LanguageEnum $language = null): string
    {
        if (empty($language)) {
            $languageId = Context::getHidden(ContextKeyEnum::LANGUAGE_ID->value);
        } else {
            $languageId = $language->value;
        }

        return match ($languageId) {
            LanguageEnum::ZH_TW->value => 'zh_hant',
            LanguageEnum::ZH_CN->value => 'zh_hans',
            LanguageEnum::ID_ID->value => 'id',
            LanguageEnum::VI_VN->value => 'vi',
            LanguageEnum::TH_TH->value => 'th',
            default => 'en',
        };
    }

    /**
     * 建立我方遊戲商帳號（遷自 RD-Studio create_dct_account）
     *
     * 三方無註冊 API：以該商戶任一 GAME_ID 呼叫 /dct/loginGame，由三方在首次登入時
     * 自動建號。子商（DCT／PNG／RELAX／HS）差異由商戶設定的 GAME_ID 隱含表達。
     */
    public function createAccount(CreateAccountParamsData $params): CreateAccountResultData
    {
        $this->setVendorInfo($params->vendorInfo);
        $username = $params->username;

        $account = $this->accountModel::create([
            'vendor_info_id' => $params->vendorInfo->id,
            'username' => $username,
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
        $action = 'CreateAccount';
        $gameId = $this->infoDetail->GAME_ID;

        if (empty($gameId)) {
            Log::error("[Dct][{$action}] 商戶未設定 GAME_ID，無法建號", [
                'vendor_info_id' => $this->vendorInfo->id,
                'username' => $username,
            ]);

            return false;
        }

        $postData = [
            'brand_id' => $this->infoDetail->BRAND_ID,
            'sign' => $this->sign($username),
            'brand_uid' => $username,
            'game_id' => $gameId,
            'currency' => $this->infoDetail->CURRENCY,
            'language' => 'en',
            'channel' => 'pc',
            'country_code' => $this->infoDetail->COUNTRY_CODE,
        ];

        try {
            $response = Http::logAction("Dct_{$action}", $postData)
                ->asJson()
                ->post("{$this->infoDetail->API_URL}/dct/loginGame", $postData)
                ->throw()
                ->json();
        } catch (Throwable $e) {
            Log::error("[Dct][{$action}] API error", [
                'username' => $username,
                'error' => $e->getMessage(),
                'trace' => Common::formatTrace($e),
            ]);

            return false;
        }

        if (!is_array($response) || (int) ($response['code'] ?? 0) !== 1000) {
            Log::error("[Dct][{$action}] API error: code is not 1000", [
                'username' => $username,
                'response' => $response,
            ]);

            return false;
        }

        return true;
    }
}

class VendorInfoDetailData extends Data
{
    public function __construct(
        public string $API_URL,
        public string $API_KEY,
        public string $BRAND_ID,
        public string $CURRENCY,
        public string $PROVIDER,
        public string $COUNTRY_CODE,

        // 三方無註冊 API，建號時借「開啟遊戲」隱式建號用的任一 game_id
        public ?string $GAME_ID = null,
    ) {}
}
