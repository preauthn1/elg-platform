<?php

declare(strict_types=1);

namespace App\Vendors\Baison;

use App\Data\GameInfoData;
use App\Data\Vendor\CreateAccountParamsData;
use App\Data\Vendor\CreateAccountResultData;
use App\Data\Vendor\GetGameListParamsData;
use App\Data\Vendor\GetGameListResultData;
use App\Data\VendorGameUrlParamsData;
use App\Data\VendorGameUrlResultData;
use App\Data\VendorTransferData;
use App\Enums\Game\GameTypeEnum;
use App\Enums\Vendor\VendorTransferResultEnum;
use App\Exceptions\ApiException;
use App\Helpers\Common;
use App\Models\Member;
use App\Vendors\AbstractTransferWallet;
use App\Vendors\Contracts\ShouldScheduleAccountCreation;
use App\Vendors\Contracts\ShouldSyncGameList;
use App\Vendors\Models\BaisonAccount;
use App\Vendors\Models\BaisonBetRecord;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Spatie\LaravelData\Data;
use Throwable;

class Driver extends AbstractTransferWallet implements ShouldScheduleAccountCreation, ShouldSyncGameList
{
    protected string $accountModel = BaisonAccount::class;

    protected string $betRecordModel = BaisonBetRecord::class;

    private VendorInfoDetailData $infoDetail;

    private array $apiParamActionToApi = [
        1 => 'Login', // 3.2.1
        4 => 'Get_Available_Balance', // 3.2.2
        2 => 'Transfer_Deposit_To_Game', // 3.2.3
        20 => 'Transfer_Withdraw_From_Game', // 3.2.4
        5 => 'Get_User_Orders', // 3.2.5
        7 => 'Get_User_Online_Status', // 3.2.6
        9 => 'Get_Bet_Records', // 3.2.7
        6 => 'Get_User_Balance', // 3.2.8
        8 => 'Kick_User_From_Game', // 3.2.9
        12 => 'Check_User_Exists', // 3.2.12
        17 => 'Get_Game_List', // 3.2.14
    ];

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
        $this->infoDetail = VendorInfoDetailData::from($info);

        if (is_null($this->infoDetail->CURRENCY)) {
            $this->infoDetail->CURRENCY = $this->vendorInfo->currency;
        }
    }

    /**
     * @throws ApiException
     * @throws ConnectionException
     */
    public function getBalance(): string
    {
        $result = $this->send([
            'action' => BaisonApiActionsEnum::GET_AVAILABLE_BALANCE->value,
            'account' => $this->vendorAccount->username,
            'money_type' => $this->infoDetail->CURRENCY,
        ]);

        if (!is_array($result)) {
            throw new ApiException('[Baison][GetBalance] API call failed: response not array');
        }

        if (!isset($result['code']) || $result['code'] !== 0) {
            throw new ApiException('[Baison][GetBalance] API call failed: result code is not 0 and msg is empty');
        }

        if (!isset($result['result']['freeMoney'])) {
            throw new ApiException('[Baison][GetBalance] API call failed: result freeMoney not found');
        }

        $money = $result['result']['freeMoney'] ?? 0.00;

        return (string) $money;
    }

    public function getGameUrl(VendorGameUrlParamsData $params): VendorGameUrlResultData
    {
        $result = $this->send([
            'action' => BaisonApiActionsEnum::LOGIN,
            'account' => $this->vendorAccount->username,
            'money' => 0,
            'money_type' => $this->infoDetail->CURRENCY,
            'order_id' => $this->infoDetail->ID . date('ymdhis') . $this->vendorAccount->username,
            'sub_channel_id' => $this->vendorAccount->sub_channel_id ?? '',
            'server_type' => $params->gameCode,
            'return_type' => 1,
        ]);

        $gameUrl = $result['result']['url'] ?? '';

        if (empty($gameUrl)) {
            throw new ApiException($result['msg'] ?? 'Game url is empty');
        }

        return VendorGameUrlResultData::from([
            'pc' => $gameUrl,
            'mobile' => $gameUrl,
        ]);
    }

    public function transferToVendor(VendorTransferData $params): VendorTransferResultEnum
    {
        return $this->transfer(BaisonApiActionsEnum::TRANSFER_DEPOSIT_TO_GAME, $params);
    }

    public function transferToLocal(VendorTransferData $params): VendorTransferResultEnum
    {
        return $this->transfer(BaisonApiActionsEnum::TRANSFER_WITHDRAW_FROM_GAME, $params);
    }

    /**
     * @throws ApiException
     */
    private function transfer(BaisonApiActionsEnum $action, VendorTransferData $params): VendorTransferResultEnum
    {
        $result = $this->send([
            'action' => $action,
            'account' => $this->vendorAccount->username,
            'money' => $params->vendorAmount,
            'money_type' => $this->infoDetail->CURRENCY,
            'order_id' => $this->infoDetail->ID . date('ymdhis') . $this->vendorAccount->username,
        ], false);

        $logData = [
            'request' => $params,
            'response' => $result,
        ];

        if (!is_array($result)) {
            Log::error('[Baison][Transfer] API call failed: response not array', $logData);

            return VendorTransferResultEnum::TIMEOUT;
        }

        if (!isset($result['code'])) {
            Log::error('[Baison][Transfer] API call failed: result code not found', $logData);

            return VendorTransferResultEnum::TIMEOUT;
        }

        if ($result['code'] !== 0) {
            $msg = $result['msg'] ?? 'Result code is not 0 and msg is empty';
            Log::error("[Baison][Transfer] API call failed: {$msg}", $logData);

            return VendorTransferResultEnum::FAILED;
        }

        return VendorTransferResultEnum::SUCCESS;
    }

    /**
     * Baison Only support GET method
     *
     * @return mixed
     *
     * @throws ApiException
     * @throws ConnectionException
     */
    /**
     * 遊戲清單（三方為棋牌平台，比照舊系統一律 POKER；無圖片與多語名）
     */
    public function getGameList(GetGameListParamsData $params): GetGameListResultData
    {
        $this->setVendorInfo($params->vendorInfo);

        $response = $this->send(['action' => BaisonApiActionsEnum::GET_GAME_LIST]);

        $data = [];
        foreach ($response['result']['records'] ?? [] as $game) {
            $gameCode = (string) $game['game_id'];
            $data[$gameCode] = GameInfoData::from([
                'gameCode' => $gameCode,
                'gameName' => $game['en_name'],
                'imageUrl' => '',
                'gameType' => GameTypeEnum::POKER->value,
                'status' => 1,
                'info' => json_encode($game),
            ]);
        }

        return GetGameListResultData::from(['gameList' => $data]);
    }

    private function send(array $params = [], bool $checkResponseCode = true)
    {
        if (empty($params['action'])) {
            throw new ApiException('Action is required');
        }

        $params['action'] = ($params['action'] instanceof BaisonApiActionsEnum) ? $params['action']->value : (int) $params['action'];
        $api = $this->apiParamActionToApi[$params['action']] ?? $params['action'];

        $this->check();

        $timestamp = (int) (microtime(true) * 1000);

        $getParams = [
            'channel_id' => $this->infoDetail->ID,
            'timestamp' => $timestamp,
            'param' => $this->paramsEncode($this->infoDetail->AES_KEY, $params),
            'key' => md5($this->infoDetail->ID . $timestamp . $this->infoDetail->MD5_KEY),
        ];

        $url = rtrim($this->infoDetail->API_URL, '/');
        $response = Http::logAction("Baison_api_call_{$api}")
            ->asJson()
            ->get($url, $getParams)
            ->throw()
            ->json();

        if (empty($response)) {
            throw new ApiException('Http Response is empty');
        }

        if ($checkResponseCode && $response['code'] !== 0) {
            $msg = $response['msg'] ?? 'Result code is not 0 and msg is empty';
            throw new ApiException("Http Response result code is not 0, code: {$response['code']}, msg: {$msg}");
        }

        return $response;
    }

    private function check()
    {
        if (empty($this->infoDetail)) {
            throw new ApiException('Vendor info detail is empty, use setvendorInfoDetail() to set the value');
        }

        if (empty($this->infoDetail->API_URL)) {
            throw new ApiException("VendorInfo detail's API_URL is empty, please check the value");
        }

        if (empty($this->infoDetail->ID)) {
            throw new ApiException("VendorInfo detail's ID is empty, please check the value");
        }

        if (empty($this->infoDetail->AES_KEY)) {
            throw new ApiException("VendorInfo detail's AES_KEY is empty, please check the value");
        }

        if (empty($this->infoDetail->MD5_KEY)) {
            throw new ApiException("VendorInfo detail's MD5_KEY is empty, please check the value");
        }
    }

    private function paramsEncode(string $aesKey, array $params): string
    {
        $paramStr = http_build_query($params);

        $encryptStr = openssl_encrypt($paramStr, 'AES-128-ECB', $aesKey, OPENSSL_RAW_DATA);

        return base64_encode($encryptStr);
    }

    /**
     * 建立我方遊戲商帳號（遷自 RD-Studio create_baison_account）
     */
    public function createAccount(CreateAccountParamsData $params): CreateAccountResultData
    {
        // setInfo 內的 CURRENCY fallback 會讀 $this->vendorInfo->currency
        $this->setVendorInfo($params->vendorInfo);
        $username = $params->username;

        $account = $this->accountModel::create([
            'vendor_info_id' => $params->vendorInfo->id,
            'username' => $username,
            // sub_channel_id 為 NOT NULL 無 default，且 getGameUrl 會帶給三方
            'sub_channel_id' => $this->vendorInfo->name,
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
     * 建立三方遊戲商帳號（三方無獨立建號 API，以 action=LOGIN 落帳）
     */
    private function createVendorAccount(BaisonAccount $account): bool
    {
        try {
            // 先確認三方不存在同名帳號（比照舊系統）
            $exists = $this->send([
                'action' => BaisonApiActionsEnum::CHECK_USER_EXISTS,
                'account' => $account->username,
            ], false);

            if ((bool) ($exists['result']['exist'] ?? false)) {
                Log::error('[Baison][CreateAccount] account already exists', ['username' => $account->username]);

                return false;
            }

            $this->send([
                'action' => BaisonApiActionsEnum::LOGIN,
                'account' => $account->username,
                'money' => 0,
                'money_type' => $this->infoDetail->CURRENCY,
                'order_id' => $this->infoDetail->ID . date('ymdhis') . $account->username,
                'sub_channel_id' => $account->sub_channel_id,
            ]);
        } catch (Throwable $e) {
            Log::error('[Baison][CreateAccount] API call failed', [
                'username' => $account->username,
                'error' => $e->getMessage(),
                'trace' => Common::formatTrace($e),
            ]);

            return false;
        }

        return true;
    }
}

enum BaisonApiActionsEnum: int
{
    case LOGIN = 1;  // 3.2.1
    case GET_AVAILABLE_BALANCE = 4;  // 3.2.2
    case TRANSFER_DEPOSIT_TO_GAME = 2;  // 3.2.3
    case TRANSFER_WITHDRAW_FROM_GAME = 20; // 3.2.4
    case GET_USER_ORDERS = 5;  // 3.2.5
    case GET_USER_ONLINE_STATUS = 7;  // 3.2.6
    case GET_BET_RECORDS = 9;  // 3.2.7
    case GET_USER_BALANCE = 6;  // 3.2.8
    case KICK_USER_FROM_GAME = 8;  // 3.2.9
    case CHECK_USER_EXISTS = 12; // 3.2.12
    case GET_GAME_LIST = 17; // 3.2.14
}

class VendorInfoDetailData extends Data
{
    public function __construct(
        public $API_URL,
        public $BET_RECORD_URL,
        public $ID,
        public $AES_KEY,
        public $MD5_KEY,
        public $CURRENCY,
    ) {}
}
