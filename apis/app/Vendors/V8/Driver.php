<?php

declare(strict_types=1);

namespace App\Vendors\V8;

use App\Data\Vendor\CreateAccountParamsData;
use App\Data\Vendor\CreateAccountResultData;
use App\Data\VendorGameUrlParamsData;
use App\Data\VendorGameUrlResultData;
use App\Data\VendorTransferData;
use App\Enums\LanguageEnum;
use App\Enums\Transaction\TransactionEnum;
use App\Enums\Vendor\VendorTransferResultEnum;
use App\Exceptions\ApiException;
use App\Helpers\Common;
use App\Models\Member;
use App\Vendors\AbstractTransferWallet;
use App\Vendors\Contracts\ShouldScheduleAccountCreation;
use App\Vendors\Models\V8Account;
use App\Vendors\Models\V8BetRecord;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class Driver extends AbstractTransferWallet implements ShouldScheduleAccountCreation
{
    protected string $accountModel = V8Account::class;

    protected string $betRecordModel = V8BetRecord::class;

    protected string $actionTitle = 'V8';

    protected string $apiUrl;

    protected int $agentId;

    protected string $desKey;

    protected string $md5Key;

    protected string $lineCode;

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
        $this->apiUrl = $info['API_URL'];
        $this->agentId = (int) $info['AGENT'];
        $this->desKey = $info['DESKEY'];
        $this->md5Key = $info['MD5KEY'];
        $this->lineCode = $info['LINECODE'];
    }

    public function getGameUrl(VendorGameUrlParamsData $params): VendorGameUrlResultData
    {
        $action = $this->actionTitle . '_api_getGameUrl';
        $username = $this->vendorAccount->username;
        $language = $this->getLanguage($params->language->value);
        $gameCode = $params->gameCode;
        $exitUrl = $params->exitUrl;

        $url = $this->apiUrl;
        $time = Carbon::now()->getTimestampMs();

        $data = [
            's' => 0,
            'account' => $username,
            'money' => 0,
            'orderid' => $this->agentId . $time . $username,
            'ip' => '0.0.0.0',
            'lineCode' => $this->lineCode,
            'KindID' => $gameCode,
        ];

        $param = $this->encrypt($data);

        $params = [
            'agent' => $this->agentId,
            'timestamp' => $time,
            'param' => $param,
            'key' => $this->sign($time),
        ];

        // 不使用http_build_query()是因為param內有些字會被換掉
        $getParam = $this->params($params);
        $url .= '?' . $getParam;

        $response = Http::logAction($action)->get($url)->throw();
        $json = $response->json();

        if (!$json) {
            throw new ApiException('Third party error: not json response');
        }

        if (!isset($json['d']['code']) || !is_numeric($json['d']['code'])) {
            throw new ApiException('Third party error: format error');
        }

        if ((string) $json['d']['code'] != '0') {
            $code = $json['d']['code'];
            throw new ApiException('Third party error: error code ' . $code);
        }

        $url = $json['d']['url'];
        $url = str_replace('lang=vi_vn', 'lang=' . $language, $url);
        $url .= '&backUrl=' . $exitUrl;

        return VendorGameUrlResultData::from([
            'pc' => $url,
            'mobile' => $url,
        ]);
    }

    /**
     * 取得餘額
     */
    public function getBalance(): string
    {
        $action = $this->actionTitle . '_getBalance';
        $username = $this->vendorAccount->username;

        $url = $this->apiUrl;
        $time = Carbon::now()->getTimestampMs();

        $data = [
            's' => 7,
            'account' => $username,
        ];

        $param = $this->encrypt($data);
        $params = [
            'agent' => $this->agentId,
            'timestamp' => $time,
            'param' => $param,
            'key' => $this->sign($time),
        ];

        $getParam = $this->params($params);
        $url .= '?' . $getParam;

        $response = Http::logAction($action)->get($url)->throw();
        $json = $response->json();

        if (!$json) {
            throw new ApiException('Third party error: not json response');
        }

        if (!isset($json['d']['code']) || !is_numeric($json['d']['code'])) {
            throw new ApiException('Third party error: format error');
        }

        if ((string) $json['d']['code'] != '0') {
            $code = $json['d']['code'];
            throw new ApiException('Third party error: error code ' . $code);
        }

        return (string) $json['d']['freeMoney'];
    }

    /**
     * 轉帳
     */
    protected function transfer(string $username, int $type, string $credit, string $billNo): VendorTransferResultEnum
    {
        if ($type == 1) {
            // 存款
            $action = $this->actionTitle . '_Deposit';
            $s = 2;
        } else {
            // 提款
            $action = $this->actionTitle . '_Withdrawal';
            $s = 3;
        }
        $url = $this->apiUrl;
        $time = Carbon::now()->getTimestampMs();

        $data = [
            's' => $s,
            'account' => $username,
            'money' => $credit,
            'orderid' => $billNo,
        ];
        $param = $this->encrypt($data);
        $params = [
            'agent' => $this->agentId,
            'timestamp' => $time,
            'param' => $param,
            'key' => $this->sign($time),
        ];

        $getParam = $this->params($params);
        $url .= '?' . $getParam;

        $response = Http::logAction($action)->get($url)->throw();
        $json = $response->json();

        if (!$json) {
            return VendorTransferResultEnum::TIMEOUT;
        }

        if (!isset($json['d']['code']) || !is_numeric($json['d']['code'])) {
            return VendorTransferResultEnum::TIMEOUT;
        }
        $code = (string) $json['d']['code'];

        if ($code != '0') {
            return VendorTransferResultEnum::FAILED;
        }

        return VendorTransferResultEnum::SUCCESS;
    }

    /**
     * 玩家餘額轉入遊戲商 (存款)
     */
    public function transferToVendor(VendorTransferData $params): VendorTransferResultEnum
    {
        return $this->transfer(
            username: $this->vendorAccount->username,
            type: 1,
            credit: $params->vendorAmount,
            billNo: $params->transactionNo,
        );
    }

    /**
     * 轉出遊戲商餘額回本地 (提款)
     */
    public function transferToLocal(VendorTransferData $params): VendorTransferResultEnum
    {
        return $this->transfer(
            username: $this->vendorAccount->username,
            type: 2,
            credit: $params->vendorAmount,
            billNo: $params->transactionNo,
        );
    }

    /**
     * 檢查轉帳狀態
     */
    public function checkTransferStatus(string $transactionNo): VendorTransferResultEnum
    {
        $action = $this->actionTitle . '_CheckTransferStatus';
        $url = $this->apiUrl;
        $time = Carbon::now()->getTimestampMs();

        $data = [
            's' => 4,
            'orderid' => $transactionNo,
        ];
        $param = $this->encrypt($data);
        $params = [
            'agent' => $this->agentId,
            'timestamp' => $time,
            'param' => $param,
            'key' => $this->sign($time),
        ];

        $getParam = $this->params($params);
        $url .= '?' . $getParam;

        $response = Http::logAction($action)->get($url)->throw();
        $json = $response->json();

        if (!$json) {
            return VendorTransferResultEnum::TIMEOUT;
        }

        if (!isset($json['d']['code']) || !is_numeric($json['d']['code'])) {
            return VendorTransferResultEnum::TIMEOUT;
        }
        $code = (string) $json['d']['code'];

        if ($code != '0') {
            return VendorTransferResultEnum::TIMEOUT;
        }

        // 状态码 Status code（-1:不存在 Inexistence、0:成功 Success、2:失败 Failure、3:正在处理中 Processing）
        $status = (string) $json['d']['status'];

        if ($status == '0') {
            return VendorTransferResultEnum::SUCCESS;
        } elseif ($status == '-1' || $status == '2') {
            return VendorTransferResultEnum::FAILED;
        } else {
            return VendorTransferResultEnum::TIMEOUT;
        }
    }

    private function encrypt(array $data): string
    {
        $message = http_build_query($data);

        return urlencode(openssl_encrypt($message, 'aes-128-ecb', $this->desKey, 0));
    }

    private function sign($time)
    {
        return md5($this->agentId . $time . $this->md5Key);
    }

    private function params($data)
    {
        $str = '';
        foreach ($data as $key => $val) {
            $str .= $key . '=' . $val;
            if (end($data) !== $val) {
                $str .= '&';
            }
        }

        return $str;
    }

    public function getLanguage(int $languageId): string
    {
        return match ($languageId) {
            LanguageEnum::VI_VN->value => 'vi_vn',
            default => 'en_us',
        };
    }

    /**
     * Auto generate transaction no, 遊戲商如果有指定格式, 請 override 這個方法
     */
    public function getTransactionNo(?TransactionEnum $transactionEnum = null): string
    {
        $time = Carbon::now()->getTimestampMs();
        $username = $this->vendorAccount->username;

        return $this->agentId . $time . $username;
    }

    /**
     * 建立我方遊戲商帳號（遷自 RD-Studio create_v8_account）
     *
     * V8 沒有獨立建號 API：以 s=0（登入遊戲，不存在則建號）+ KindID=0（大廳）觸發開號。
     */
    public function createAccount(CreateAccountParamsData $params): CreateAccountResultData
    {
        $this->setVendorInfo($params->vendorInfo);
        $username = $params->username;

        $account = $this->accountModel::create([
            'vendor_info_id' => $params->vendorInfo->id,
            'username' => $username,
            // username_modified 為 NOT NULL 無 default，且是注單 JOIN 鍵；
            // 前綴取 info 原始字串（setInfo 會把 AGENT 轉 int，帶前導零時會失真）
            'username_modified' => (string) $params->vendorInfo->info['AGENT'] . '_' . $username,
            'admin' => 1,
            'member_id' => -999999999,
        ]);

        if (!$account) {
            return CreateAccountResultData::from(['status' => false, 'username' => $username]);
        }

        // 三方限制 1 秒只能呼叫一次（比照舊系統逐筆間隔）
        sleep(1);

        if (!$this->createVendorAccount($username)) {
            return CreateAccountResultData::from(['status' => false, 'username' => $username]);
        }

        $account->update(['member_id' => 0]);

        return CreateAccountResultData::from(['status' => true, 'username' => $username]);
    }

    /**
     * 建立三方遊戲商帳號
     *
     * query string 必須用 params() 自訂拼接，不可用 http_build_query
     * （param 內的 + / = 會被二次編碼）。
     */
    private function createVendorAccount(string $username): bool
    {
        $time = Carbon::now()->getTimestampMs();

        $data = [
            's' => 0,
            'account' => $username,
            'money' => 0,
            'orderid' => $this->agentId . $time . $username,
            'ip' => '0.0.0.0',
            'lineCode' => $this->lineCode,
            'KindID' => 0,
        ];

        $query = $this->params([
            'agent' => $this->agentId,
            'timestamp' => $time,
            'param' => $this->encrypt($data),
            'key' => $this->sign($time),
        ]);

        try {
            $json = Http::logAction($this->actionTitle . '_createAccount')
                ->get($this->apiUrl . '?' . $query)
                ->throw()
                ->json();
        } catch (Throwable $e) {
            Log::error('[V8][createAccount] request failed', [
                'username' => $username,
                'error' => $e->getMessage(),
                'trace' => Common::formatTrace($e),
            ]);

            return false;
        }

        if (!is_array($json) || (string) ($json['d']['code'] ?? '') !== '0') {
            Log::error('[V8][createAccount] failed', ['username' => $username, 'response' => $json]);

            return false;
        }

        return true;
    }
}
