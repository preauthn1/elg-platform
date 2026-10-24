<?php

declare(strict_types=1);

namespace App\Vendors\Ag;

use App\Data\Vendor\CreateAccountParamsData;
use App\Data\Vendor\CreateAccountResultData;
use App\Data\VendorGameUrlParamsData;
use App\Data\VendorGameUrlResultData;
use App\Data\VendorTransferData;
use App\Enums\LanguageEnum;
use App\Enums\Transaction\TransactionEnum;
use App\Enums\Vendor\VendorTransferResultEnum;
use App\Exceptions\AccountNotExistsException;
use App\Exceptions\ApiException;
use App\Facades\DES;
use App\Helpers\Common;
use App\Models\Member;
use App\Vendors\AbstractTransferWallet;
use App\Vendors\Contracts\ShouldScheduleAccountCreation;
use App\Vendors\Models\AgAccount;
use App\Vendors\Models\AgBetRecord;
use Exception;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class Driver extends AbstractTransferWallet implements ShouldScheduleAccountCreation
{
    /** 三方錯誤碼：帳號不存在（幣別不符或代理層級錯誤時同碼） */
    private const string ERROR_ACCOUNT_NOT_EXISTS = '60001';

    protected string $accountModel = AgAccount::class;

    protected string $betRecordModel = AgBetRecord::class;

    protected string $businessHost;

    protected string $forwardHost;

    protected string $cagent;

    protected string $md5Key;

    protected string $desKey;

    protected string $currency;

    protected string $gameType;

    protected string $oddType;

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
        $this->businessHost = $info['BUSINESS_HOST'];
        $this->forwardHost = $info['FORWARD_HOST'];
        $this->cagent = $info['AGENT'];
        $this->md5Key = $info['MD5_KEY'];
        $this->desKey = $info['DES_KEY'];
        $this->currency = $info['CURRENCY'];
        $this->gameType = $info['GAME_TYPE'];
        $this->oddType = $info['ODD_TYPE'];
    }

    /**
     * @throws AccountNotExistsException|ApiException|ConnectionException
     */
    public function getBalance(): string
    {
        /** @var AgAccount $account */
        $account = $this->vendorAccount;

        $data = [
            'cagent' => $this->cagent,
            'loginname' => $account->username,
            'method' => 'gb', // 查詢帳號餘額的編,
            'actype' => $this->gameType, // 0:試玩, 1:真錢
            'password' => $this->getDefaultPassword($account->username, $account->created_at->toDateTimeString()),
            'cur' => $this->currency, // 幣別
        ];

        $result = $this->sendRequest('getBalance', $this->businessHost, $data);

        $balance = (string) ($result->attributes()->info ?? '');
        $message = (string) ($result->attributes()->msg ?? '');

        // 查詢成功時 info 直接是餘額數字；失敗時 info="error"、錯誤碼寫在 msg（error:60001,...）。
        // 這裡不驗就原樣往外送，呼叫端會拿 "error" 去做 bcdiv 而丟 ValueError
        // ——ValueError 不是 Exception，catch (Exception) 接不住，整批轉回餘額會當場中斷。
        if (!is_numeric($balance)) {
            // 60001：三方查無此帳號（本地有紀錄、AG 端沒有），屬單一玩家狀態，
            // 與「三方故障」分流，讓呼叫端照 AccountNotExistsException 略過該筆繼續跑
            if (str_contains($message, 'error:' . self::ERROR_ACCOUNT_NOT_EXISTS)) {
                throw new AccountNotExistsException("[AG][getBalance] account not exists: {$message}");
            }

            throw new ApiException('Third party error: ' . $message);
        }

        return $balance;
    }

    public function getGameUrl(VendorGameUrlParamsData $params): VendorGameUrlResultData
    {
        /** @var AgAccount $account */
        $account = $this->vendorAccount;
        $password = [REDACTED], $account->created_at->toDateTimeString());
        $languageId = $params->language->value;
        $exitUrl = $params->exitUrl;
        $exitDomain = (string) parse_url($exitUrl, PHP_URL_HOST);

        $bigAccount = 'GB4MELG00093701';
        $sid = date('U') . rand(1000, 9999);

        $oddType = $this->oddType ?? 'A';
        if ($account->username == $bigAccount) {
            $oddType = 'GB4B';
        }

        $data = [
            'cagent' => $this->cagent,
            'loginname' => $account->username,
            'password' => $password,
            'dm' => $exitDomain,
            'sid' => $this->cagent . $sid, // 13~16位的數字
            'lang' => $this->mapLanguageCode($languageId),
            'actype' => $this->gameType, // 0:試玩，1:真錢
            'gameType' => $params->gameCode, // 遊戲編號
            'oddtype' => $oddType, // 設定可下注的金額A、B、C、D...I
            'cur' => $this->currency,
        ];

        $dataString = http_build_query($data, '', '/\\\\/');
        $desService = $this->getDesService();
        $param = $desService->encrypt($dataString);
        $key = md5($param . $this->md5Key);
        $gameUrl = $this->forwardHost . '?params=' . $param . '&key=' . $key;

        $mobileData = $data;
        $mobileData['mh5'] = 'y';
        $dataString = http_build_query($data, '', '/\\\\/');
        $param = $desService->encrypt($dataString);
        $key = md5($param . $this->md5Key);
        $mobileGameUrl = $this->forwardHost . '?params=' . $param . '&key=' . $key;

        return VendorGameUrlResultData::from([
            'pc' => $gameUrl,
            'mobile' => $mobileGameUrl,
        ]);
    }

    /**
     * @throws ApiException|ConnectionException
     */
    public function transferToLocal(VendorTransferData $params): VendorTransferResultEnum
    {
        return $this->transfer('withdrawal', $params);
    }

    /**
     * @throws ApiException|ConnectionException
     */
    public function transferToVendor(VendorTransferData $params): VendorTransferResultEnum
    {
        return $this->transfer('deposit', $params);
    }

    /**
     * @throws ApiException|ConnectionException
     */
    private function transfer(string $action, VendorTransferData $params): VendorTransferResultEnum
    {
        $username = $this->vendorAccount->username;
        $transactionNo = $params->transactionNo;
        $password = [REDACTED], $this->vendorAccount->created_at->toDateTimeString());
        $type = $action == 'deposit' ? 'IN' : 'OUT';
        $credit = Common::numFloor($params->vendorAmount, 2);
        $result = $this->prepareTransfer(
            $username,
            $password,
            $transactionNo,
            $type,
            $credit,
        );
        if ($result == false) {
            return VendorTransferResultEnum::FAILED;
        }

        if ((string) $result->attributes()->info === '0') {
            // 預備轉帳成功後執行確認轉帳
            $result = $this->confirmTransfer(
                $username,
                $password,
                $transactionNo,
                $type,
                $credit
            );
            if ($result == false) {
                return VendorTransferResultEnum::FAILED;
            }
            if ((string) $result->attributes()->info === '0') {
                return VendorTransferResultEnum::SUCCESS;
            } else {
                throw new ApiException('Third party error: ' . (string) $result->attributes()->msg);
            }
        } else {
            throw new ApiException('Third party error: ' . (string) $result->attributes()->msg);
        }
    }

    /**
     * 封裝請求
     *
     * @throws ConnectionException
     */
    private function sendRequest(string $action, string $url, array $data, $needAgent = true): object
    {
        $dataString = http_build_query($data, '', '/\\\\/');
        $desService = $this->getDesService();
        $param = $desService->encrypt($dataString);
        $key = md5($param . $this->md5Key);

        $fullUrl = $url . '?params=' . $param . '&key=' . $key;

        $headers = [];
        if ($needAgent) {
            $headers['User-Agent'] = 'WEB_LIB_GI_' . $this->cagent;
        }

        $response = Http::logAction($this->getName() . ' ' . $action)
            ->withHeaders($headers)
            ->withoutVerifying()
            ->post($fullUrl)
            ->throw();

        if (!$response) {
            throw new Exception('Third party error: response is empty');
        }

        $result = $response->body();

        return simplexml_load_string($result);
    }

    private function getDesService()
    {
        $config = $this->vendorInfo->info;

        return DES::init(
            cipher: 'DES-ECB',
            key: $config['DES_KEY'],
        );
    }

    // 預備轉帳
    public function prepareTransfer(string $username, string $password, string $billNo, string $type, string $credit): object
    {
        $url = $this->businessHost;
        $params = [
            'cagent' => $this->cagent,
            'loginname' => $username,
            'method' => 'tc', // 預備轉帳的編號
            'billno' => $this->cagent . $billNo, // 轉帳編號8位數字內
            'type' => $type, // IN(網站帳號轉到遊戲帳號) or OUT(遊戲帳號轉到網站帳號)
            'credit' => $credit, // 金額需為整數，保留到小數二位
            'actype' => $this->gameType, // 0:試玩，1:真錢
            'password' => $password,
            'cur' => $this->currency, // 幣種
        ];
        $result = $this->sendRequest('prepareTransfer', $url, $params);

        return $result;
    }

    // 確認轉帳
    public function confirmTransfer($username, $password, $billNo, $type, $credit)
    {
        $url = $this->businessHost;
        $params = [
            'cagent' => $this->cagent,
            'loginname' => $username,
            'method' => 'tcc', // 確認轉帳的編號
            'billno' => $this->cagent . $billNo, // 轉帳編號8位數字內
            'type' => $type, // IN(網站帳號轉到遊戲帳號) or OUT(遊戲帳號轉到網站帳號)
            'credit' => $credit, // 金額需為整數，保留到小數二位
            'actype' => $this->gameType, // 0:試玩，1:真錢
            'flag' => '1', // 0:預備轉帳失敗，1:預備轉帳成功
            'password' => $password,
            'cur' => $this->currency, // 幣種
        ];
        $result = $this->sendRequest('confirmTransfer', $url, $params);

        return $result;
    }

    /**
     * 轉換語系代號, 不要再寫進 languages
     */
    private function mapLanguageCode(int $languageId): string
    {
        // zh-cn (简体中文) 1
        // zh-tw (䌓体中文） 2
        // en-us(英语) 3
        // euc-jp(日语) 4
        // ko(韩语) 5
        // th(泰文) 6
        // vi(越南文) 8
        // id(印尼语) 11
        // khm(柬埔寨语) 9
        // prt(葡萄牙语) 23
        return match ($languageId) {
            LanguageEnum::ZH_CN->value => '1',
            LanguageEnum::ZH_TW->value => '2',
            LanguageEnum::EN->value => '3',
            LanguageEnum::TH_TH->value => '6',
            LanguageEnum::VI_VN->value => '8',
            LanguageEnum::ID_ID->value => '11',
            default => '3',
        };
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
     * 建立我方遊戲商帳號（遷自 RD-Studio create_ag_account）
     */
    public function createAccount(CreateAccountParamsData $params): CreateAccountResultData
    {
        // sendRequest 的 getDesService() 會讀 $this->vendorInfo->info
        $this->setVendorInfo($params->vendorInfo);
        $username = $params->username;

        $account = $this->accountModel::create([
            'vendor_info_id' => $params->vendorInfo->id,
            'username' => $username,
            // 實際登入密碼由 getDefaultPassword(username, created_at) 即時推算，不落庫
            'password' => '',
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
     * 建立三方遊戲商帳號（method=lg：確認或建立帳號）
     *
     * 密碼必須與 getGameUrl／transfer／getBalance 用的 getDefaultPassword 同式，
     * 因此要先 create 取得 created_at 之後才能組請求。
     */
    private function createVendorAccount(AgAccount $account): bool
    {
        $data = [
            'cagent' => $this->cagent,
            'loginname' => $account->username,
            'method' => 'lg',
            'actype' => $this->gameType,
            'password' => $this->getDefaultPassword($account->username, $account->created_at->toDateTimeString()),
            'oddtype' => $this->oddType,
            'cur' => $this->currency,
        ];

        try {
            $result = $this->sendRequest('createAccount', $this->businessHost, $data);
        } catch (Throwable $e) {
            Log::error($this->getName() . ' createAccount failed', [
                'username' => $account->username,
                'error' => $e->getMessage(),
                'trace' => Common::formatTrace($e),
            ]);

            return false;
        }

        if ((string) $result->attributes()->info !== '0') {
            Log::error($this->getName() . ' createAccount failed: info is not 0', [
                'username' => $account->username,
                'response' => (string) $result->asXML(),
            ]);

            return false;
        }

        return true;
    }
}
