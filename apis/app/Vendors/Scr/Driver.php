<?php

declare(strict_types=1);

namespace App\Vendors\Scr;

use App\Data\Vendor\CreateAccountParamsData;
use App\Data\Vendor\CreateAccountResultData;
use App\Data\VendorGameUrlParamsData;
use App\Data\VendorGameUrlResultAppInfoData;
use App\Data\VendorGameUrlResultData;
use App\Data\VendorResetPasswordData;
use App\Data\VendorTransferData;
use App\Enums\General\HttpCode;
use App\Enums\StatusEnum;
use App\Enums\Vendor\VendorTransferResultEnum;
use App\Exceptions\ApiException;
use App\Helpers\Common;
use App\Models\Member;
use App\Vendors\AbstractTransferWallet;
use App\Vendors\Contracts\ShouldScheduleAccountCreation;
use App\Vendors\Models\ScrAccount;
use App\Vendors\Models\ScrBetRecord;
use Exception;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * SCR(918KISS) 3
 *
 * @property ScrAccount $vendorAccount SCR帳號
 */
class Driver extends AbstractTransferWallet implements ShouldScheduleAccountCreation
{
    protected string $accountModel = ScrAccount::class;

    protected string $betRecordModel = ScrBetRecord::class;

    private string $apiUrl;

    private string $authCode;

    private string $secretKey;

    private string $username;

    private string $actionPrefix = 'SCR_';

    private string $downloadPage = '';

    public function __construct(int $vendorId, ?Member $member)
    {
        parent::__construct($vendorId, $member);

        if (!$member) {
            return;
        }

        // Config
        $this->setInfo($this->vendorInfo->info);

        // Account
        $account = $this->vendorAccount;
        $this->username = $account->player_id ?? '';
    }

    public function setInfo(array $info): void
    {
        $this->apiUrl = rtrim($info['HOST'], '/') . '/';
        $this->authCode = $info['AUTH_CODE'] ?? '';
        $this->secretKey = $info['SECRET_KEY'];
        $this->downloadPage = $info['DOWNLOAD_PAGE'] ?? '';
    }

    /**
     * 取得遊戲網址
     */
    public function getGameUrl(VendorGameUrlParamsData $params): VendorGameUrlResultData
    {
        $username = $this->vendorAccount->player_id;
        $password = [REDACTED];
        if (empty($password)) {
            $password = [REDACTED]' . $this->getDefaultPassword($username, $this->vendorAccount->created_at->toDateTimeString());
        }
        $appInfo = VendorGameUrlResultAppInfoData::from([
            'username' => $username,
            'password' => $password,
            'ios_downloads' => [$this->downloadPage],
            'ios_open' => "LobbyKiss64://account={$username}&password=[REDACTED]",
            'android_downloads' => [$this->downloadPage],
            'android_open' => "lobbykiss://lobbykiss?account={$username}&password=[REDACTED]",
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
        $data = [
            'action' => 'getUserInfo',
            'userName' => $this->username,
            'time' => date('U') . '000',
            'authcode' => $this->authCode,
        ];
        $action = $this->actionPrefix . 'getBalance';

        $result = $this->sendRequest($action, 'account/account.ashx', $data);

        if (!$result['success']) {
            throw new ApiException($result['msg'] ?? 'Result code is not 0 and msg is empty');
        }

        $balance = $result['MoneyNum'] ?? '0.00';

        return (string) $balance;
    }

    /**
     * 玩家餘額轉入遊戲商 (存款)
     */
    public function transferToVendor(VendorTransferData $params): VendorTransferResultEnum
    {
        return $this->handleTransfer($params, 1);
    }

    /**
     * 轉出遊戲商餘額回本地 (提款)
     */
    public function transferToLocal(VendorTransferData $params): VendorTransferResultEnum
    {
        return $this->handleTransfer($params, 2);
    }

    private function handleTransfer(VendorTransferData $params, $type): VendorTransferResultEnum
    {
        if ($type == 1) {
            $scoreNum = (float) Common::numAdd('0', $params->vendorAmount);
            $action = $this->actionPrefix . 'deposit';
        } else {
            $scoreNum = (float) Common::numSub('0', $params->vendorAmount);
            $action = $this->actionPrefix . 'withdraw';
        }
        $data = [
            'action' => 'setServerScore',
            'orderid' => $params->transactionNo,
            'scoreNum' => $scoreNum,
            'userName' => $this->username,
            'ActionUser' => $this->username,
            'ActionIp' => Common::getIp(),
            'time' => date('U') . '000',
            'authcode' => $this->authCode,
        ];

        try {
            $result = $this->sendRequest($action, 'account/setScore.ashx', $data);
        } catch (Exception $e) {
            return VendorTransferResultEnum::TIMEOUT;
        }

        $logData = [
            'request' => $data,
            'response' => $result,
        ];

        if (!is_array($result)) {
            Log::error($this->getName() . ' transfer failed: response is not array', $logData);

            return VendorTransferResultEnum::TIMEOUT;
        }

        if (!isset($result['success']) || !isset($result['code'])) {
            Log::error($this->getName() . ' transfer failed: parameter "success" is not found', $logData);

            return VendorTransferResultEnum::TIMEOUT;
        }

        if ((string) $result['code'] === '0') {
            return VendorTransferResultEnum::SUCCESS;
        }

        return VendorTransferResultEnum::FAILED;
    }

    public function resetPassword(VendorResetPasswordData $params): VendorTransferResultEnum
    {
        /** @var ScrAccount $account */
        $account = $this->vendorAccount;

        $data = [
            'action' => 'editUser',
            'userName' => $account->player_id,
            'OldPassWd' => $account->password,  // 玩家新密码（长度<=17）如果不修改则为空
            'PassWd' => $params->newPassword,  // 玩家新密码（长度<=17）如果不修改则为空
            'pwdtype' => 1,  // 密码格式。默认 0=加密, 1=密码明文
            'Name' => $account->username,
            'Tel' => '000000',
            'Memo' => $account->username,
            'time' => date('U') . '000',  // 时间戳 13位 System.currentTimeMillis()
            'authcode' => $this->authCode,  // 授权码
        ];
        $action = $this->actionPrefix . 'resetPassword';

        $result = $this->sendRequest($action, 'account/account.ashx', $data);

        if (!$result['success'] || (string) $result['code'] !== '0') {
            return VendorTransferResultEnum::FAILED;
        }

        $account->update([
            'password' => $params->newPassword,
        ]);

        return VendorTransferResultEnum::SUCCESS;
    }

    private function getSignature(array $data): string
    {
        $string = $data['authcode'] . $data['userName'] . $data['time'] . $this->secretKey;

        return strtoupper(md5(strtolower($string)));
    }

    private function sendRequest(string $action, string $endpoint, array $data): mixed
    {
        $url = $this->apiUrl . $endpoint;

        // data 加入簽名
        $data['sign'] = $this->getSignature($data);

        try {
            $result = Http::logAction($action)
                ->withoutVerifying()
                ->asForm()
                ->post($url, $data)
                ->throw()
                ->json();
        } catch (Exception $e) {
            $this->throwError('API request failed', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);
        }

        return $result;
    }

    private function throwError(string $message, array $context = []): void
    {
        $vendorName = $this->getName();

        $context = array_merge([
            'vendor_id' => $this->vendor->id,
            'username' => $this->username,
        ], $context);

        Log::error("$vendorName $message", $context);

        throw new ApiException($message, HttpCode::HTTP_INTERNAL_SERVER_ERROR->value);
    }

    /**
     * 建立我方遊戲商帳號（遷自 RD-Studio create_new_scr_account）
     *
     * 兩步建號：先向 918 索取玩家帳號（RandomUserName），再以該 id 建號（addUser）。
     * getGameUrl 主路徑直讀 password 欄明碼；其 fallback 的密碼種子是 player_id、
     * 與建號用的 username 不同，故密碼必須落庫，不能依賴 fallback。
     */
    public function createAccount(CreateAccountParamsData $params): CreateAccountResultData
    {
        $this->setVendorInfo($params->vendorInfo);
        $username = $params->username;
        // sendRequest 錯誤路徑的 throwError() 會讀 $this->username，排程情境無會員先注入
        $this->username = $username;

        // 918 端帳號必須由 RandomUserName 產生，拿不到就不建本地列（同舊系統）
        sleep(1); // 三方限制 1 秒只能呼叫一次
        $playerId = $this->fetchPlayerId();

        if ($playerId === '') {
            return CreateAccountResultData::from(['status' => false, 'username' => $username]);
        }

        $account = $this->accountModel::create([
            'vendor_info_id' => $params->vendorInfo->id,
            'username' => $username,
            'player_id' => $playerId,
            // scr_accounts.status 的 DB default 是 0（舊搬遷語意欄位），不寫 1 的話
            // 綁定查詢與帳號池水位都撈不到這筆
            'status' => StatusEnum::ENABLE->value,
            'admin' => 1,
            'member_id' => -999999999,
        ]);

        if (!$account) {
            return CreateAccountResultData::from(['status' => false, 'username' => $username]);
        }

        // 與舊系統相同的密碼公式：'Aa1' + getDefaultPassword(username, created_at)；
        // 種子是我方 username，與 getGameUrl fallback（用 player_id）不同，必須落庫
        $password = [REDACTED]' . $this->getDefaultPassword($username, $account->created_at->toDateTimeString());

        if (!$this->createVendorAccount($playerId, $username, $password)) {
            return CreateAccountResultData::from(['status' => false, 'username' => $username]);
        }

        $account->update(['password' => $password, 'member_id' => 0]);

        return CreateAccountResultData::from(['status' => true, 'username' => $username]);
    }

    /**
     * RandomUserName：由 918 依區域產生玩家帳號（回空字串代表失敗）
     */
    private function fetchPlayerId(): string
    {
        $info = $this->vendorInfo->info;

        $data = [
            'action' => 'RandomUserName',
            'userName' => (string) ($info['AGENT_NAME'] ?? ''),
            'UserAreaId' => (string) ($info['USER_AREA_ID'] ?? ''),
            'time' => date('U') . '000',
            'authcode' => $this->authCode,
        ];

        try {
            $result = $this->sendRequest($this->actionPrefix . 'getPlayerId', 'account/account.ashx', $data);
        } catch (Throwable $e) {
            Log::error('[Scr][RandomUserName] request failed', [
                'error' => $e->getMessage(),
                'trace' => Common::formatTrace($e),
            ]);

            return '';
        }

        if (!is_array($result) || (string) ($result['code'] ?? '') !== '0' || empty($result['account'])) {
            Log::error('[Scr][RandomUserName] failed', ['response' => $result]);

            return '';
        }

        return (string) $result['account'];
    }

    /**
     * addUser：userName 帶 918 產生的 player_id，Name／Memo 帶我方帳號便於對照
     */
    private function createVendorAccount(string $playerId, string $username, string $password): bool
    {
        $info = $this->vendorInfo->info;

        $data = [
            'action' => 'addUser',
            'agent' => (string) ($info['AGENT_NAME'] ?? ''),
            'PassWd' => $password,
            'pwdtype' => '1', // 1 = 明文
            'userName' => $playerId,
            'Name' => $username,
            'Tel' => '000000',
            'Memo' => $username,
            'UserAreaId' => (string) ($info['USER_AREA_ID'] ?? ''),
            'UserType' => '1', // 1 = 正式玩家
            'time' => date('U') . '000',
            'authcode' => $this->authCode,
        ];

        try {
            $result = $this->sendRequest($this->actionPrefix . 'createAccount', 'account/account.ashx', $data);
        } catch (Throwable $e) {
            Log::error('[Scr][addUser] request failed', [
                'player_id' => $playerId,
                'error' => $e->getMessage(),
                'trace' => Common::formatTrace($e),
            ]);

            return false;
        }

        if (!is_array($result) || ($result['success'] ?? false) !== true) {
            Log::error('[Scr][addUser] failed', ['player_id' => $playerId, 'response' => $result]);

            return false;
        }

        return true;
    }
}
