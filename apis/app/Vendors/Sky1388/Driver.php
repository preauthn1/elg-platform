<?php

declare(strict_types=1);

namespace App\Vendors\Sky1388;

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
use App\Enums\Transaction\TransactionEnum;
use App\Enums\Vendor\VendorTransferResultEnum;
use App\Exceptions\ApiException;
use App\Helpers\Common;
use App\Models\Member;
use App\Vendors\AbstractTransferWallet;
use App\Vendors\Contracts\ShouldScheduleAccountCreation;
use App\Vendors\Contracts\ShouldSyncGameList;
use App\Vendors\Models\Sky1388Account;
use App\Vendors\Models\Sky1388BetRecord;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Spatie\LaravelData\Data;
use Throwable;

class Driver extends AbstractTransferWallet implements ShouldScheduleAccountCreation, ShouldSyncGameList
{
    protected string $accountModel = Sky1388Account::class;

    protected string $betRecordModel = Sky1388BetRecord::class;

    private VendorInfoDetail $infoDetail;

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
        $this->infoDetail = VendorInfoDetail::from($info);
    }

    public function getGameUrl(VendorGameUrlParamsData $params): VendorGameUrlResultData
    {
        $url = "{$this->infoDetail->API_URL}/user_play_game";
        $action = 'GetGameUrl';

        $data = [
            'agid' => $this->infoDetail->ID,
            'username' => $this->vendorAccount->username,
            'game_code' => $params->gameCode,
            'game_support' => 'H5',
            'lang' => $this->mapLanguageCode($params->language),
            'game_back_url' => $params->exitUrl,
        ];

        $sign = $this->sign($data);
        $data['signature'] = $sign;

        $response = Http::logAction("Sky1388_$action", $data)
            ->get($url, $data)
            ->throw()
            ->json();

        if (!is_array($response)) {
            throw new ApiException("[Sky1388][{$action}] API error: response is not array.");
        }

        if (!isset($response['status']) || !isset($response['url'])) {
            throw new ApiException("[Sky1388][{$action}] API error: response data not currect");
        }

        if (strtolower($response['status']) !== 'ok') {
            throw new ApiException("[Sky1388][{$action}] API error: status wrong.");
        }

        return VendorGameUrlResultData::from([
            'pc' => $response['url'],
            'mobile' => $response['url'],
        ]);
    }

    public function getBalance(): string
    {
        $url = "{$this->infoDetail->API_URL}/user_detail";
        $action = 'GetBalance';

        $data = [
            'agid' => $this->infoDetail->ID,
            'username' => $this->vendorAccount->username,
        ];

        $sign = $this->sign($data);
        $data['signature'] = $sign;

        $response = Http::logAction("Sky1388_$action", $data)
            ->get($url, $data)
            ->throw()
            ->json();

        if (!is_array($response)) {
            throw new ApiException("[Sky1388][{$action}] API error: response is not array.");
        }

        if (!isset($response['status']) || !isset($response['balance'])) {
            throw new ApiException("[Sky1388][{$action}] API error: response data not currect");
        }

        if (strtolower($response['status']) !== 'ok') {
            throw new ApiException("[Sky1388][{$action}] API error: status wrong.");
        }

        return Common::numFloor($response['balance'], 4);
    }

    public function checkTransferStatus(string $transactionNo): VendorTransferResultEnum
    {
        $url = "{$this->infoDetail->API_URL}/user_transfer_detail";
        $action = 'CheckTransferStatus';

        $data = [
            'agid' => $this->infoDetail->ID,
            'username' => $this->vendorAccount->username,
            'orderid' => $transactionNo,
        ];

        $sign = $this->sign($data);
        $data['signature'] = $sign;

        $response = Http::logAction("Sky1388_$action", $data)
            ->get($url, $data)
            ->throw()
            ->json();

        $logData = [
            'request' => $data,
            'response' => $response,
        ];

        if (!is_array($response) || !isset($response['status'])) {
            Log::error("[Sky1388][{$action}] API error: bad response", $logData);

            return VendorTransferResultEnum::TIMEOUT; // 無法驗證結果，不還錢
        }

        if (strtolower((string) $response['status']) === 'ok') {
            return VendorTransferResultEnum::SUCCESS;
        }

        if (strtolower((string) $response['status']) === 'error') {
            Log::error("[Sky1388][{$action}] API error: status is error.", $logData);

            return VendorTransferResultEnum::FAILED;
        }

        return VendorTransferResultEnum::TIMEOUT;
    }

    /**
     * 玩家餘額轉入遊戲商 (存款), origin: type = 1
     */
    public function transferToVendor(VendorTransferData $params): VendorTransferResultEnum
    {
        return $this->transfer($params);
    }

    /**
     * 轉出遊戲商餘額回本地 (提款), origin: type != 1
     */
    public function transferToLocal(VendorTransferData $params): VendorTransferResultEnum
    {
        $params->vendorAmount = Common::numMul($params->vendorAmount, -1);

        return $this->transfer($params);
    }

    private function transfer(VendorTransferData $params): VendorTransferResultEnum
    {
        $url = "{$this->infoDetail->API_URL}/user_transfer";
        $action = ($params->vendorAmount > 0) ? 'Deposit' : 'Withdraw';

        $data = [
            'agid' => $this->infoDetail->ID,
            'amount' => $params->vendorAmount,
            'username' => $this->vendorAccount->username,
            'orderid' => $params->transactionNo,
            'rolling' => '',
            'wallet_type' => '',
        ];

        $sign = $this->sign($data);
        $data['signature'] = $sign;

        $response = Http::logAction("Sky1388_$action")
            ->get($url, $data)
            ->throw()
            ->json();

        $logData = [
            'request' => $data,
            'response' => $response,
        ];

        if (!is_array($response) || !isset($response['status'])) {
            Log::error("[Sky1388][{$action}] API error: bad response", $logData);

            return VendorTransferResultEnum::TIMEOUT; // 無法驗證結果，不還錢
        }

        if (strtolower((string) $response['status']) === 'ok') {
            return VendorTransferResultEnum::SUCCESS;
        }

        if (strtolower((string) $response['status']) === 'error') {
            Log::error("[Sky1388][{$action}] API error: status is error.", $logData);

            return VendorTransferResultEnum::FAILED;
        }

        return VendorTransferResultEnum::TIMEOUT;
    }

    private function mapLanguageCode(?LanguageEnum $language = null): string
    {
        if (empty($language)) {
            $languageId = Context::getHidden(ContextKeyEnum::LANGUAGE_ID->value);
        } else {
            $languageId = $language->value;
        }

        return match ($languageId) {
            LanguageEnum::ZH_TW->value => 'chs',
            LanguageEnum::ZH_CN->value => 'chs',
            LanguageEnum::ID_ID->value => 'ind',
            LanguageEnum::TH_TH->value => 'thi',
            default => 'eng',
        };
    }

    /**
     * 遊戲清單（比照舊系統：type 為 MULTIPLAYER、新遊戲以停用狀態建立，
     * 待人工開通時再歸類；語系僅 english/chinese 兩組）
     */
    public function getGameList(GetGameListParamsData $params): GetGameListResultData
    {
        $this->setVendorInfo($params->vendorInfo);

        $action = 'GetGameList';
        $data = ['agid' => $this->infoDetail->ID];
        $data['signature'] = $this->sign(['agid' => $this->infoDetail->ID]);

        $response = Http::logAction("Sky1388_$action", $data)
            ->get("{$this->infoDetail->API_URL}/user_game_list", $data)
            ->throw()
            ->json();

        if (!is_array($response) || strtolower($response['status'] ?? '') !== 'ok') {
            throw new ApiException("[Sky1388][{$action}] API error: " . ($response['status'] ?? 'no status'));
        }

        $gameList = [];
        foreach ($response['list'] ?? [] as $game) {
            $gameCode = (string) $game['game_code'];
            $gameInfo = GameInfoData::from([
                'gameCode' => $gameCode,
                'gameName' => $game['game_name'],
                'imageUrl' => $game['game_image_url'] ?? '',
                'gameType' => GameTypeEnum::MULTIPLAYER->value,
                'status' => 0,
                'info' => json_encode($game),
            ]);

            $displayNames = $game['game_display_name'] ?? [];
            if (!empty($displayNames['english'])) {
                $gameInfo->setGameNames(LanguageEnum::EN->value, $displayNames['english']);
            }
            if (!empty($displayNames['chinese'])) {
                $gameInfo->setGameNames(LanguageEnum::ZH_CN->value, $displayNames['chinese']);
            }

            $gameList[$gameCode] = $gameInfo;
        }

        return GetGameListResultData::from(['gameList' => $gameList]);
    }

    private function sign($data)
    {
        ksort($data);
        $str = implode('', $data) . $this->infoDetail->PRIVATE_KEY;
        $sign = sha1($str);

        return $sign;
    }

    /**
     * 遊戲商只接受正整數
     */
    public function getTransactionNo(?TransactionEnum $transactionEnum = null): string
    {
        // yymmddhhmmss + rand(1000, 9999)
        $transactionNo = date('ymdHis') . rand(1000, 9999);

        return $transactionNo;
    }

    /**
     * 建立我方遊戲商帳號（遷自 RD-Studio create_sky1388_account）
     *
     * sky1388_accounts 無 password 欄；建號密碼公式與 getDefaultPassword 相同，
     * 需要時可用 (username, created_at) 重算。
     */
    public function createAccount(CreateAccountParamsData $params): CreateAccountResultData
    {
        $this->setVendorInfo($params->vendorInfo);
        $username = $params->username;

        $account = $this->accountModel::create([
            'vendor_info_id' => $params->vendorInfo->id,
            'username' => $username,
            'admin' => 0, // 舊系統此家用 0（其餘遊戲商多為 1）
            'member_id' => -999999999,
        ]);

        if (!$account) {
            return CreateAccountResultData::from(['status' => false, 'username' => $username]);
        }

        $password = [REDACTED], $account->created_at->toDateTimeString());

        if (!$this->createVendorAccount($username, $password)) {
            return CreateAccountResultData::from(['status' => false, 'username' => $username]);
        }

        $account->update(['member_id' => 0]);

        return CreateAccountResultData::from(['status' => true, 'username' => $username]);
    }

    /**
     * 建立三方遊戲商帳號（/user_register，GET + signature）
     */
    private function createVendorAccount(string $username, string $password): bool
    {
        $action = 'CreateAccount';

        $data = [
            'agid' => $this->infoDetail->ID,
            'username' => $username,
            'password' => $password,
            'lang' => 'eng',
        ];
        $data['signature'] = $this->sign($data);

        try {
            $response = Http::logAction("Sky1388_$action")
                ->get("{$this->infoDetail->API_URL}/user_register", $data)
                ->throw()
                ->json();
        } catch (Throwable $e) {
            Log::error("[Sky1388][{$action}] request failed", [
                'username' => $username,
                'error' => $e->getMessage(),
                'trace' => Common::formatTrace($e),
            ]);

            return false;
        }

        if (!is_array($response) || strtolower((string) ($response['status'] ?? '')) !== 'ok') {
            Log::error("[Sky1388][{$action}] API error: status is not ok", [
                'username' => $username,
                'response' => $response,
            ]);

            return false;
        }

        return true;
    }
}

class VendorInfoDetail extends Data
{
    public function __construct(
        public string $ID,
        public string $PASS,
        public string $AGENCY_ID,
        public string $API_KEY,
        public string $PRIVATE_KEY,
        public string $API_URL,
        public string $GAME_URL,
        public string $URL,
    ) {
        $this->API_URL = trim($this->API_URL, '/');
        $this->GAME_URL = trim($this->GAME_URL, '/');
        $this->URL = trim($this->URL, '/');
    }
}
