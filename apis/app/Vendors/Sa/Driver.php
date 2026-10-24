<?php

declare(strict_types=1);

namespace App\Vendors\Sa;

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
use App\Vendors\Models\SaAccount;
use App\Vendors\Models\SaBetRecord;
use Exception;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use phpseclib3\Crypt\DES;
use Throwable;

/**
 * SA 37
 */
class Driver extends AbstractTransferWallet implements ShouldScheduleAccountCreation
{
    protected string $accountModel = SaAccount::class;

    protected string $betRecordModel = SaBetRecord::class;

    protected string $actionTitle = 'SA';

    protected string $apiUrl;

    protected string $gameUrl;

    protected string $md5Key;

    protected string $secretKey;

    protected string $encryptKey;

    protected string $currency;

    protected string $lobby;

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
        $this->gameUrl = $info['GAME_URL'];
        $this->md5Key = $info['MD5KEY'];
        $this->secretKey = $info['SECRETKEY'];
        $this->encryptKey = $info['ENCRYPTKEY'];
        $this->currency = $info['CURRENCY'];
        $this->lobby = $info['LOBBY'];
    }

    public function getGameUrl(VendorGameUrlParamsData $params): VendorGameUrlResultData
    {
        $username = $this->vendorAccount->username;
        $language = $this->getLanguage($params->language->value);
        $time = date('YmdHis', time());

        $data = [
            'method' => 'LoginRequest',
            'Key' => $this->secretKey,
            'Time' => $time,
            'Username' => $username,
            'CurrencyType' => $this->currency,
        ];

        $response = $this->sendRequest($data);

        $xml = $this->xmlToObject($response->body());

        if (!$xml) {
            throw new ApiException('Third party error: not xml response');
        }

        if ($xml->ErrorMsgId != '0') {
            $code = $xml->ErrorMsgId;
            $msg = $xml->ErrorMsg;
            $error = 'Third party error: (' . $code . ') ' . $msg;
            throw new ApiException($error);
        }

        $gameData = [
            'username' => $username,
            'token' => $xml->Token,
            'lobby' => $this->lobby,
            'lang' => $language,
            'mobile' => true,
        ];
        $url = $this->gameUrl . '?' . http_build_query($gameData);

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
        $data = [
            'method' => 'GetUserStatusDV',
            'Key' => $this->secretKey,
            'Time' => date('YmdHis', time()),
            'Username' => $this->vendorAccount->username,
        ];

        $response = $this->sendRequest($data);

        $xml = $this->xmlToObject($response->body());

        if (!$xml) {
            throw new ApiException('Third party error: not xml response');
        }

        if (!isset($xml->ErrorMsgId) || !is_numeric($xml->ErrorMsgId)) {
            throw new ApiException('Third party error: format error');
        }

        if ((string) $xml->ErrorMsgId !== '0') {
            $code = $xml->ErrorMsgId;
            $msg = $xml->ErrorMsg;
            $error = 'Third party error: (' . $code . ') ' . $msg;
            throw new ApiException($error);
        }

        return (string) $xml->Balance;
    }

    /**
     * 轉帳
     */
    protected function transfer(array $data): VendorTransferResultEnum
    {
        $response = $this->sendRequest($data);

        $xml = $this->xmlToObject($response->body());

        if (!$xml) {
            return VendorTransferResultEnum::TIMEOUT;
        }

        if (!isset($xml->ErrorMsgId) || !is_numeric($xml->ErrorMsgId)) {
            // throw new ApiException('Third party error: format error');
            return VendorTransferResultEnum::TIMEOUT;
        }

        if ((string) $xml->ErrorMsgId === '0') {
            return VendorTransferResultEnum::SUCCESS;
        }

        return VendorTransferResultEnum::FAILED;
    }

    /**
     * 玩家餘額轉入遊戲商 (存款)
     */
    public function transferToVendor(VendorTransferData $params): VendorTransferResultEnum
    {
        $data = [
            'method' => 'CreditBalanceDV',
            'Key' => $this->secretKey,
            'Time' => date('YmdHis', time()),
            'Username' => $this->vendorAccount->username,
            'OrderId' => $params->transactionNo,
            'CreditAmount' => $params->vendorAmount,
        ];

        return $this->transfer($data);
    }

    /**
     * 轉出遊戲商餘額回本地 (提款)
     */
    public function transferToLocal(VendorTransferData $params): VendorTransferResultEnum
    {
        $data = [
            'method' => 'DebitBalanceDV',
            'Key' => $this->secretKey,
            'Time' => date('YmdHis', time()),
            'Username' => $this->vendorAccount->username,
            'OrderId' => $params->transactionNo,
            'DebitAmount' => $params->vendorAmount,
        ];

        return $this->transfer($data);
    }

    /**
     * 檢查轉帳狀態
     */
    public function checkTransferStatus(string $transactionNo): VendorTransferResultEnum
    {
        $data = [
            'method' => 'CheckOrderId',
            'Key' => $this->secretKey,
            'Time' => date('YmdHis', time()),
            'OrderId' => $transactionNo,
        ];

        $response = $this->sendRequest($data);

        $xml = $this->xmlToObject($response->body());

        if (!$xml) {
            return VendorTransferResultEnum::TIMEOUT;
        }

        if (!isset($xml->ErrorMsgId) || !is_numeric($xml->ErrorMsgId)) {
            // throw new ApiException('Third party error: format error');
            return VendorTransferResultEnum::TIMEOUT;
        }

        if ((string) $xml->ErrorMsgId === '0') {
            if ($xml->isExist == 'true') {
                return VendorTransferResultEnum::SUCCESS;
            } else {
                return VendorTransferResultEnum::FAILED;
            }
        }

        return VendorTransferResultEnum::TIMEOUT;
    }

    private function sign(string $str): string
    {
        $des = new DES('cbc');
        $des->setKey($this->encryptKey);
        $des->setIV($this->encryptKey);
        $encrypted = $des->encrypt($str);

        return base64_encode($encrypted);
    }

    private function xmlToObject($result)
    {
        $xml = simplexml_load_string($result);
        $json = json_encode($xml);

        return json_decode($json);
    }

    public function getLanguage(int $languageId): string
    {
        return match ($languageId) {
            LanguageEnum::EN->value => 'en-us',
            LanguageEnum::ZH_TW->value => 'zh-hant',
            LanguageEnum::ZH_CN->value => 'zh-hans',
            LanguageEnum::ID_ID->value => 'id',
            LanguageEnum::VI_VN->value => 'vi',
            LanguageEnum::TH_TH->value => 'th',
            LanguageEnum::MY_MM->value => 'my',
            LanguageEnum::MS_MY->value => 'ms',
            LanguageEnum::HI_IN->value => 'hi',
            default => 'en-us',
        };
    }

    /**
     * 遊戲商有指定訂單編號格式
     */
    public function getTransactionNo(?TransactionEnum $transactionEnum = null): string
    {
        if ($transactionEnum == TransactionEnum::TO_VENDOR) {
            $orderPrefix = 'IN';
        } elseif ($transactionEnum == TransactionEnum::FROM_VENDOR) {
            $orderPrefix = 'OUT';
        } else {
            throw new Exception('TransactionEnum not supported');
        }

        $time = date('YmdHis', time());

        return $orderPrefix . $time . $this->vendorAccount->username;
    }

    private function sendRequest(array $data, string $previousFunction = ''): mixed
    {
        if (!$previousFunction) {
            $backtrace = debug_backtrace();
            $previousFunction = $backtrace[1]['function'];
        }
        $action = $this->actionTitle . '_' . $previousFunction;

        $url = rtrim($this->apiUrl, '/');

        $time = date('YmdHis', time());
        $qs = http_build_query($data);
        $s = md5($qs . $this->md5Key . $time . $this->secretKey);
        $q = $this->sign($qs);

        $postData = [
            'q' => $q,
            's' => $s,
        ];

        $result = Http::logAction($action)
            ->asForm()
            ->post($url, $postData)
            ->throw();

        return $result;
    }

    /**
     * 建立我方遊戲商帳號（遷自 RD-Studio create_sa_account）
     *
     * SA 登入以 LoginRequest 換 Token，不用密碼。
     */
    public function createAccount(CreateAccountParamsData $params): CreateAccountResultData
    {
        $this->setVendorInfo($params->vendorInfo);
        $username = $params->username;

        $account = $this->accountModel::create([
            'vendor_info_id' => $params->vendorInfo->id,
            'username' => $username,
            'admin' => 1,
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
     * 建立三方遊戲商帳號（RegUserInfo；sendRequest 已封裝 q/s 打包，回應為 XML）
     */
    private function createVendorAccount(string $username): bool
    {
        $data = [
            'method' => 'RegUserInfo',
            'Key' => $this->secretKey,
            'Time' => date('YmdHis', time()),
            'Username' => $username,
            'CurrencyType' => $this->currency,
        ];

        try {
            $response = $this->sendRequest($data, 'createAccount');
            $xml = $this->xmlToObject($response->body());
        } catch (Throwable $e) {
            Log::error('[Sa][createAccount] request failed', [
                'username' => $username,
                'error' => $e->getMessage(),
                'trace' => Common::formatTrace($e),
            ]);

            return false;
        }

        if (!$xml || !isset($xml->ErrorMsgId)) {
            Log::error('[Sa][createAccount] failed: bad xml response', ['username' => $username]);

            return false;
        }

        if ((string) $xml->ErrorMsgId === '0') {
            return true;
        }

        Log::error('[Sa][createAccount] failed', [
            'username' => $username,
            'code' => (string) $xml->ErrorMsgId,
            'message' => (string) ($xml->ErrorMsg ?? ''),
        ]);

        return false;
    }
}
