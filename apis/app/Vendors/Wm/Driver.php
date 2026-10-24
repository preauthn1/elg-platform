<?php

declare(strict_types=1);

namespace App\Vendors\Wm;

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
use App\Enums\Vendor\VendorTransferResultEnum;
use App\Exceptions\ApiException;
use App\Helpers\Common;
use App\Models\Member;
use App\Vendors\AbstractTransferWallet;
use App\Vendors\Contracts\ShouldScheduleAccountCreation;
use App\Vendors\Contracts\ShouldSyncGameList;
use App\Vendors\Models\WmAccount;
use App\Vendors\Models\WmBetRecord;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Spatie\LaravelData\Data;
use Throwable;

class Driver extends AbstractTransferWallet implements ShouldScheduleAccountCreation, ShouldSyncGameList
{
    protected string $accountModel = WmAccount::class;

    protected string $betRecordModel = WmBetRecord::class;

    private VendorInfoDetailData $detailData;

    private string $apiUrl;

    private string $actionPrefix = 'WM_';

    public function __construct(int $vendorId, ?Member $member)
    {
        parent::__construct($vendorId, $member);

        if (!$member) {
            return;
        }

        $this->setInfo($this->vendorInfo->info);
    }

    public function setInfo(array $info): void
    {
        $this->detailData = VendorInfoDetailData::from($info);
        $this->apiUrl = trim($this->detailData->SERVER_NAME, '/');
    }

    public function getGameUrl(VendorGameUrlParamsData $params): VendorGameUrlResultData
    {
        $action = $this->actionPrefix . 'GetGameUrl';
        $data = [
            'id' => $this->vendorAccount->user_id,
        ];
        $param = http_build_query($data);
        $url = "{$this->apiUrl}/Platform/Feed/Wallet/Auth/{$this->detailData->LICENSEE_TOKEN}?$param";

        $response = Http::logAction($action)
            ->withHeaders(['Content-Type' => 'application/json'])
            ->get($url)
            ->throw()
            ->json();

        if (!is_array($response)) {
            throw new ApiException("[WM][{$action}] API call failed: response not array");
        }

        $gameInfo = json_decode($params->info);

        $url = $this->detailData->GAME_URL . 'games/';

        // 已經沒有 GAME_URL_MODE != 2 的情況, 所以直接使用新的方式
        if (isset($gameInfo->Code)) {
            // 新的方式 Licensee_code/mode/game code
            $url .= "{$this->detailData->LICENSEE_CODE}/{$this->detailData->GAME_MODE}/{$gameInfo->Code}/";
        }

        $url .= '?authuser=' . $response['AuthUser'];
        $url .= '&authkey=' . $response['AuthKey'];
        $url .= '&authskin=' . $response['AuthSkin'];
        $url .= '&language=' . $this->mapLanguageCode();
        $url .= '&display=iframe'; // - iframe：用于在浮动框架中开启游戏。启动器的输出是一个完整的页面，游戏界面适合浮动框架的大小。

        return new VendorGameUrlResultData(
            pc: $url,
            mobile: $url
        );
    }

    public function getBalance(): string
    {
        $action = $this->actionPrefix . 'GetBalance';
        $data = [
            'id' => $this->vendorAccount->user_id,
        ];
        $param = http_build_query($data);
        $url = "{$this->apiUrl}/Platform/Feed/Wallet/Balance/{$this->detailData->LICENSEE_TOKEN}?$param";

        $response = Http::logAction($action)
            ->withoutRedirecting()
            ->get($url)
            ->throw()
            ->json();

        if (!is_array($response)) {
            throw new ApiException("[WM][{$action}] API call failed: response not array");
        }

        if (!isset($response['Balance'])) {
            throw new ApiException("[WM][{$action}] API call failed: Balance not found");
        }

        return Common::numFloor($response['Balance'], 4);
    }

    /**
     * 檢查轉帳狀態
     */
    public function checkTransferStatus(string $transactionNo): VendorTransferResultEnum
    {
        // 注意是交易紀錄的主鍵, 不是 transaction_no, 因為這是遊戲商要求的, 需要跟舊版一致
        $action = $this->actionPrefix . 'CheckTransferStatus';
        $url = "{$this->apiUrl}/Platform/Feed/Wallet/Transaction/{$this->detailData->LICENSEE_TOKEN}?ref={$transactionNo}";

        $response = Http::logAction($action)
            ->withoutRedirecting()
            ->get($url)
            ->json();

        $logData = [
            'request' => ['ref' => $transactionNo],
            'response' => $response,
        ];

        if (!is_array($response)) {
            Log::error('[WM][CheckTransferStatus] API call failed: response not array', $logData);

            return VendorTransferResultEnum::TIMEOUT;
        }

        if (!isset($response['Status'])) {
            Log::error('[WM][CheckTransferStatus] API call failed: Status not found', $logData);

            return VendorTransferResultEnum::TIMEOUT;
        }

        if ($response['Status'] == 'GRANTED') {
            return VendorTransferResultEnum::SUCCESS;
        }

        Log::error('[WM][CheckTransferStatus] API call failed: Status is not GRANTED', $logData);

        return VendorTransferResultEnum::FAILED;
    }

    /**
     * 玩家餘額轉入遊戲商 (存款), origin: type = 1
     */
    public function transferToVendor(VendorTransferData $params): VendorTransferResultEnum
    {
        return $this->transfer(1, $params);
    }

    /**
     * 轉出遊戲商餘額回本地 (提款), origin: type != 1
     */
    public function transferToLocal(VendorTransferData $params): VendorTransferResultEnum
    {
        return $this->transfer(2, $params);
    }

    /**
     * 遊戲清單（token 帶在 path，無簽章；回應頂層即為遊戲陣列）
     */
    public function getGameList(GetGameListParamsData $params): GetGameListResultData
    {
        $this->setVendorInfo($params->vendorInfo);

        $action = $this->actionPrefix . 'GetGames';
        $url = "{$this->apiUrl}/platform/feed/games/{$this->detailData->LICENSEE_TOKEN}";

        $response = Http::logAction($action)
            ->withHeaders(['Content-Type' => 'application/json'])
            ->get($url)
            ->throw()
            ->json();

        if (!is_array($response)) {
            throw new ApiException("[WM][{$action}] API call failed: response not array");
        }

        $typeMap = [
            'Slot Machines' => GameTypeEnum::SLOTS->value,
            'Fishing Games' => GameTypeEnum::FISHING->value,
        ];

        $data = [];
        foreach ($response as $game) {
            $gameCode = (string) $game['Identity'];
            $data[$gameCode] = GameInfoData::from([
                'gameCode' => $gameCode,
                'gameName' => $game['Name'],
                'imageUrl' => $game['ThumbnailUrl'] ?? '',
                'gameType' => $typeMap[$game['GameType'] ?? ''] ?? GameTypeEnum::ARCADE->value,
                'status' => 1,
                'info' => json_encode($game),
            ]);
        }

        return GetGameListResultData::from(['gameList' => $data]);
    }

    private function transfer(int $type, VendorTransferData $params): VendorTransferResultEnum
    {
        if ($type == 1) {
            $action = $this->actionPrefix . 'deposit';
            $endpoint = 'Credit';
        } else {
            $action = $this->actionPrefix . 'withdraw';
            $endpoint = 'Debit';
        }

        $data = [
            'Reference' => $params->transactionLogId, // 注意是交易紀錄的主鍵, 不是 transaction_no, 因為這是遊戲商要求的, 需要跟舊版一致
            'UserID' => $this->vendorAccount->user_id,
            'Amount' => Common::numFloor($params->vendorAmount, 2),
            'Currency' => $this->detailData->CURRENCY,
        ];

        $url = "{$this->apiUrl}/Platform/Feed/Wallet/{$endpoint}/{$this->detailData->LICENSEE_TOKEN}";

        $response = Http::logAction($action)
            ->withoutRedirecting()
            ->asJson()
            ->post($url, $data)
            ->json();

        $logData = [
            'request' => $data,
            'response' => $response,
        ];

        if (!is_array($response)) {
            Log::error("[WM][{$action}] API call failed: response not array", $logData);

            return VendorTransferResultEnum::TIMEOUT;
        }

        if (isset($response['Error'])) { // 有錯誤
            $errMsg = $response['Error'] ? $response['Description'] : 'Unknown error';
            Log::error("[WM][{$action}] API call failed: {$errMsg}", $logData);

            return VendorTransferResultEnum::FAILED;
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
            LanguageEnum::ZH_TW->value => 'ZH',
            LanguageEnum::ZH_CN->value => 'ZH-HANT',
            LanguageEnum::ID_ID->value => 'ID',
            default => 'EN',
        };
    }

    /**
     * 建立我方遊戲商帳號（遷自 RD-Studio create_wm_account）
     *
     * WM 以我方自產的 UserID 為去重鍵；user_id 同時是 getGameUrl／轉帳／注單對帳的鍵，
     * 建號成功後把三方回傳的 UserToken 寫回 player_id。
     */
    public function createAccount(CreateAccountParamsData $params): CreateAccountResultData
    {
        $this->setVendorInfo($params->vendorInfo);
        $username = $params->username;
        $userId = date('U') . random_int(100, 999);

        $account = $this->accountModel::create([
            'vendor_info_id' => $params->vendorInfo->id,
            'username' => $username,
            'user_id' => $userId,
            // player_id 為 NOT NULL 無 default，建號成功後補上 UserToken
            'player_id' => '',
            'admin' => 1,
            'member_id' => -999999999,
        ]);

        if (!$account) {
            return CreateAccountResultData::from(['status' => false, 'username' => $username]);
        }

        $playerId = $this->createVendorAccount($username, $userId);

        if ($playerId === null) {
            return CreateAccountResultData::from(['status' => false, 'username' => $username]);
        }

        $account->update(['player_id' => $playerId, 'member_id' => 0]);

        return CreateAccountResultData::from(['status' => true, 'username' => $username]);
    }

    /**
     * 建立三方遊戲商帳號
     *
     * @return string|null 成功回 UserToken，失敗回 null
     */
    private function createVendorAccount(string $username, string $userId): ?string
    {
        $action = $this->actionPrefix . 'CreateAccount';
        $url = "{$this->apiUrl}/platform/feed/wallet/user/{$this->detailData->LICENSEE_TOKEN}";

        $data = [
            'UserID' => $userId,
            'UserName' => $username,
            'Skin' => $this->detailData->SKIN,
            'Currency' => $this->detailData->CURRENCY,
            'IPv4' => '0.0.0.0',
            'TestUser' => 'FALSE',
        ];

        try {
            $response = Http::logAction($action)
                ->withoutRedirecting()
                ->asJson()
                ->post($url, $data)
                ->throw()
                ->json();
        } catch (Throwable $e) {
            Log::error("[WM][{$action}] request failed", [
                'username' => $username,
                'error' => $e->getMessage(),
                'trace' => Common::formatTrace($e),
            ]);

            return null;
        }

        if (!is_array($response) || ($response['Status'] ?? '') !== 'ENABLED') {
            Log::error("[WM][{$action}] failed: status is not ENABLED", [
                'username' => $username,
                'response' => $response,
            ]);

            return null;
        }

        return (string) ($response['UserToken'] ?? '');
    }
}

class VendorInfoDetailData extends Data
{
    public function __construct(
        public string $SERVER_NAME,
        public string $LICENSEE_TOKEN,
        public string $CURRENCY,

        public ?string $GAME_URL = null,
        public ?string $LICENSEE_CODE = 'XSG',
        public ?string $GAME_MODE = 'real',

        /**
         * 開啟遊戲連結方式
         * 1 (旧有方式): mode/Licensee_id/game_id/configuration_id
         * 2 (新的方式): Licensee_code/mode/game code
         */
        public ?int $GAME_URL_MODE = 1,

        /**
         * 建號用的 Skin 代碼（createAccount 專用，遊戲網址／轉帳不需要）
         */
        public ?string $SKIN = null,
    ) {
        $this->GAME_URL = $this->GAME_URL ?? $this->SERVER_NAME;
    }
}
