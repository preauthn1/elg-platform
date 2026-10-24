<?php

declare(strict_types=1);

namespace App\Vendors\GreatWall;

use App\Data\GameInfoData;
use App\Data\Vendor\CreateAccountParamsData;
use App\Data\Vendor\CreateAccountResultData;
use App\Data\Vendor\GetGameListParamsData;
use App\Data\Vendor\GetGameListResultData;
use App\Data\VendorGameUrlParamsData;
use App\Data\VendorGameUrlResultAppInfoData;
use App\Data\VendorGameUrlResultData;
use App\Data\VendorResetPasswordData;
use App\Data\VendorTransferData;
use App\Enums\Game\GameTypeEnum;
use App\Enums\General\CacheTtl;
use App\Enums\Transaction\TransactionEnum;
use App\Enums\Vendor\VendorTransferResultEnum;
use App\Exceptions\ApiException;
use App\Helpers\Common;
use App\Models\Member;
use App\Vendors\AbstractTransferWallet;
use App\Vendors\Contracts\ShouldScheduleAccountCreation;
use App\Vendors\Contracts\ShouldSyncGameList;
use App\Vendors\Models\GreatWallAccount;
use App\Vendors\Models\GreatWallBetRecord;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Spatie\LaravelData\Data;
use Throwable;

class Driver extends AbstractTransferWallet implements ShouldScheduleAccountCreation, ShouldSyncGameList
{
    protected string $accountModel = GreatWallAccount::class;

    protected string $betRecordModel = GreatWallBetRecord::class;

    private string $apiUrl;

    private VendorInfoDetailData $vendorInfoDetail;

    private string $actionPrefix = 'GreatWall_';

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
        $this->vendorInfoDetail = VendorInfoDetailData::from($info);
        $this->apiUrl = rtrim($this->vendorInfoDetail->API_URL, '/');
    }

    public function resetPassword(VendorResetPasswordData $params): VendorTransferResultEnum
    {
        $action = $this->actionPrefix . 'resetPassword';
        $url = '/Api/IGame/Player/Resetpassword';
        $data = [
            'PlayerAccount' => $this->vendorInfoDetail->API_AGENT . $this->vendorAccount->username,
            'Password' => $params->newPassword,
        ];

        $response = $this->sendRequest($action, $url, $data);

        if (is_array($response) && $response['result']['code'] == '0') {
            return VendorTransferResultEnum::SUCCESS;
        } else {
            Log::error('[GreatWall][ResetPassword] Invalid response: code is not 0', ['response' => $response]);

            return VendorTransferResultEnum::FAILED;
        }
    }

    public function getGameUrl(VendorGameUrlParamsData $params): VendorGameUrlResultData
    {
        $action = $this->actionPrefix . 'getGameUrl';
        $url = '/Api/IGame/Appurl';
        $data = [];

        $cacheKey = 'GreatWall_getGameUrl_' . $this->vendorInfoDetail->API_MERCHANT_NAME;

        // 這 app 下載地址應該是不常變化  可以加入緩存
        $response = Cache::remember($cacheKey, CacheTtl::MOMENT->value, function () use ($action, $url, $data) {
            $response = $this->sendRequest($action, $url, $data);
            if (!is_array($response)) {
                throw new ApiException('[GreatWall][GetGameUrl] Invalid response: response is not an array');
            }

            if (!isset($response['result']['code'])) {
                throw new ApiException('[GreatWall][GetGameUrl] Invalid response: no code');
            }

            if ($response['result']['code'] != '0') {
                throw new ApiException('[GreatWall][GetGameUrl] Invalid response: code is not 0');
            }

            if (!isset($response['data']['iOS']) || !isset($response['data']['Android'])) {
                throw new ApiException('[GreatWall][GetGameUrl] Invalid response: no iOS or Android');
            }

            return $response;
        });

        // TODO: 將密碼修改為預設的, 舊版有的額外操作，不確定要不要保留
        // $this->resetPassword(VendorResetPasswordData::from([
        //     'newPassword' => $this->getDefaultPassword($this->vendorAccount->username, $this->vendorAccount->created_at->toDateTimeString()),
        // ]));

        $username = $this->vendorInfoDetail->API_AGENT . $this->vendorAccount->username;
        $password = [REDACTED], $this->vendorAccount->created_at->toDateTimeString());

        $appInfo = VendorGameUrlResultAppInfoData::from([
            'username' => $username,
            'password' => $password,
            'ios_downloads' => [$response['data']['iOS']],
            'ios_open' => "lobbygw99://lobbygw99?account={$username}&password=[REDACTED]",
            'android_downloads' => [$response['data']['Android']],
            'android_open' => "lobbygw99://lobbygw99?account={$username}&password=[REDACTED]",
            'app_icon' => $this->vendor->app_icon,
        ]);

        return VendorGameUrlResultData::from([
            'pc' => '',
            'mobile' => '',
            'app_infos' => $appInfo,
        ]);
    }

    public function getBalance(): string
    {
        $action = $this->actionPrefix . 'getBalance';
        $url = '/Api/IGame/Player/Info';
        $data = [
            'PlayerAccount' => $this->vendorInfoDetail->API_AGENT . $this->vendorAccount->username,
        ];

        // 每五分鐘只能呼叫10次, 超過會鎖帳號
        // http://example.invalid:1688/target/tickets/-/work_items/3758
        $apiCallCount = Common::getApiCallCount($action, 300, $this->vendorAccount->id);
        if ($apiCallCount > 10) {
            throw new ApiException(__('error_message.99992', ['count' => $apiCallCount]));
        }

        $response = $this->sendRequest($action, $url, $data);

        if (is_array($response) && $response['result']['code'] == '0') {
            return Common::numFloor($response['data']['balance'], 4);
        } else {
            throw new ApiException('[GreatWall][GetBalance] Invalid response: code is not 0, response: ' . json_encode($response));
        }
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

    private function transfer(int $type, VendorTransferData $params): VendorTransferResultEnum
    {
        $url = '/Api/IGame/Transaction/Create';
        $data = [
            'PlayerAccount' => $this->vendorInfoDetail->API_AGENT . $this->vendorAccount->username,
            'Amount' => $params->vendorAmount,
            'ExternalTransactionId' => $params->transactionNo,
        ];
        if ($type == 1) {
            $data['Amount'] = (float) Common::numAdd('0', $params->vendorAmount);
            $action = $this->actionPrefix . 'deposit';
        } else {
            $data['Amount'] = (float) Common::numSub('0', $params->vendorAmount);
            $action = $this->actionPrefix . 'withdraw';
        }

        $response = $this->sendRequest($action, $url, $data);

        if (!isset($response['result']['code'])) {
            return VendorTransferResultEnum::TIMEOUT; // 無法判斷的錯誤，先不還錢
        }

        if ($response['result']['code'] != '0') {
            return VendorTransferResultEnum::FAILED; // 交易失敗
        }

        return VendorTransferResultEnum::SUCCESS;
    }

    public function checkTransferStatus(string $transactionNo): VendorTransferResultEnum
    {
        $action = $this->actionPrefix . 'checkTransferStatus';
        $url = '/Api/IGame/Transaction/Check';
        $data = [
            'PlayerAccount' => $this->vendorInfoDetail->API_AGENT . $this->vendorAccount->username,
            'ExternalTransactionId' => $transactionNo,
        ];

        $response = $this->sendRequest($action, $url, $data);

        if (!is_array($response)) {
            return VendorTransferResultEnum::TIMEOUT;
        }

        if (!isset($response['result']['code'])) {
            return VendorTransferResultEnum::TIMEOUT;
        }

        if ($response['result']['code'] == '0') {
            return VendorTransferResultEnum::SUCCESS;
        }

        return VendorTransferResultEnum::FAILED;
    }

    /**
     * 遊戲清單（三方無類型欄位，一律 SLOTS；gameTypeName 存 remark 比照既有資料）
     */
    public function getGameList(GetGameListParamsData $params): GetGameListResultData
    {
        $this->setVendorInfo($params->vendorInfo);

        $response = $this->sendRequest($this->actionPrefix . 'GetGameList', '/Api/IGame/GameList', []);

        if (($response['result']['code'] ?? '') != '0') {
            $code = $response['result']['code'] ?? '';
            $error = $response['result']['message'] ?? '';
            throw new ApiException('Third party error: (' . $code . ') ' . $error);
        }

        $data = [];
        foreach ($response['data'] ?? [] as $game) {
            $gameCode = (string) $game['gameID'];
            $data[$gameCode] = GameInfoData::from([
                'gameCode' => $gameCode,
                'gameName' => $game['gameName'],
                'imageUrl' => '',
                'gameType' => GameTypeEnum::SLOTS->value,
                'status' => 1,
                'remark' => $game['gameTypeName'] ?? null,
                'info' => json_encode($game),
            ]);
        }

        return GetGameListResultData::from(['gameList' => $data]);
    }

    private function sendRequest(string $action, string $endpoint, array $data): array
    {
        $url = $this->apiUrl . $endpoint;
        $sign = $this->getSign($data);

        $result = Http::logAction($action)
            ->asJson()
            ->withHeaders([
                'merchantName' => $this->vendorInfoDetail->API_MERCHANT_NAME,
                'signature' => $sign,
            ])
            ->post($url, $data);

        // 這個遊戲商有頻率限制 會報錯 http status code 429, 這時直接拋出 ApiException 代表轉帳失敗
        if ($result->status() == 429) {
            throw new ApiException('Third party error: rate limit exceeded');
        } else {
            $result->throw();
        }

        $result = $result->json();

        if (!$result) {
            throw new ApiException('Third party error: not json response');
        }

        return $result;
    }

    private function getSign(array $data): string
    {
        ksort($data);
        if (count($data) > 0) {
            $string = http_build_query($data) . '&PrivateKey=' . $this->vendorInfoDetail->API_KEY;
        } else {
            $string = 'PrivateKey=' . $this->vendorInfoDetail->API_KEY;
        }
        $hash_hmac = hash('sha256', $string, true);

        return base64_encode($hash_hmac);
    }

    /**
     * Auto generate transaction no, 遊戲商如果有指定格式, 請 override 這個方法
     */
    public function getTransactionNo(?TransactionEnum $transactionEnum = null): string
    {
        // 長度最大20
        $timeStr = Carbon::now()->format('ymdHisv');
        $rand = str_pad((string) rand(0, 9999), 4, '0', STR_PAD_LEFT);
        $transactionNo = $timeStr . $rand;

        return $transactionNo;
    }

    /**
     * GW99 帳號總長上限（含 prefix）
     */
    private const int USERNAME_MAX_LENGTH = 9;

    /**
     * 建立我方遊戲商帳號（遷自 RD-Studio create_great_wall_account）
     *
     * 帳號規則覆寫通用 8 碼：GW99 限制總長 9 碼，補零位數 = 9 - strlen(prefix)，
     * 不能用 $params->username，改以 serialNumber 自算。
     * 密碼不落庫：getGameUrl 以 getDefaultPassword(username, created_at) 即時重算，
     * 建號送三方的密碼必須同式。
     */
    public function createAccount(CreateAccountParamsData $params): CreateAccountResultData
    {
        $this->setVendorInfo($params->vendorInfo);

        $prefix = (string) $params->vendorInfo->account_prefix;
        $padLength = self::USERNAME_MAX_LENGTH - strlen($prefix);
        $serial = (string) ($params->serialNumber + 1);

        if ($padLength <= 0 || strlen($serial) > $padLength) {
            Log::error('[GreatWall][createAccount] 帳號流水號已滿，無法再建號', [
                'vendor_info_id' => $params->vendorInfo->id,
                'account_prefix' => $prefix,
                'serial_number' => $params->serialNumber,
            ]);

            return CreateAccountResultData::from(['status' => false, 'username' => '']);
        }

        $username = $prefix . str_pad($serial, $padLength, '0', STR_PAD_LEFT);

        $account = $this->accountModel::create([
            'vendor_info_id' => $params->vendorInfo->id,
            'username' => $username,
            // username_modified 是注單 JOIN 鍵且 NOT NULL 無 default
            'username_modified' => strtolower($this->vendorInfoDetail->API_AGENT . $username),
            'admin' => 1,
            'member_id' => -999999999,
        ]);

        if (!$account) {
            return CreateAccountResultData::from(['status' => false, 'username' => $username]);
        }

        $password = [REDACTED], $account->created_at->toDateTimeString());

        // 三方限制 1 秒只能呼叫一次（超頻會回 Invalid signature）
        sleep(1);

        if (!$this->createVendorAccount($username, $password)) {
            return CreateAccountResultData::from(['status' => false, 'username' => $username]);
        }

        $account->update(['member_id' => 0]);

        return CreateAccountResultData::from(['status' => true, 'username' => $username]);
    }

    /**
     * 建立三方遊戲商帳號
     *
     * 建號送「裸 username + Agent」；其餘 API（餘額／轉帳／登入）才送 API_AGENT . username。
     */
    private function createVendorAccount(string $username, string $password): bool
    {
        $action = $this->actionPrefix . 'createAccount';
        $data = [
            'PlayerAccount' => $username,
            'Password' => $password,
            'Agent' => $this->vendorInfoDetail->API_AGENT,
        ];

        try {
            $response = $this->sendRequest($action, '/Api/IGame/Player/Create', $data);
        } catch (Throwable $e) {
            Log::error('[GreatWall][createAccount] API error', [
                'username' => $username,
                'error' => $e->getMessage(),
                'trace' => Common::formatTrace($e),
            ]);

            return false;
        }

        if ((string) ($response['result']['code'] ?? '') !== '0') {
            Log::error('[GreatWall][createAccount] API error: code is not 0', [
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
        public string $API_MERCHANT_NAME,
        public string $API_AGENT,
        public string $API_KEY,
    ) {}
}
