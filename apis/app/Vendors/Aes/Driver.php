<?php

declare(strict_types=1);

namespace App\Vendors\Aes;

use App\Data\Vendor\CreateAccountParamsData;
use App\Data\Vendor\CreateAccountResultData;
use App\Data\VendorGameUrlParamsData;
use App\Data\VendorGameUrlResultData;
use App\Data\VendorTransferData;
use App\Enums\General\ContextKeyEnum;
use App\Enums\General\DeviceTypeEnum;
use App\Enums\LanguageEnum;
use App\Enums\Vendor\VendorTransferResultEnum;
use App\Exceptions\ApiException;
use App\Helpers\Common;
use App\Models\Member;
use App\Vendors\AbstractTransferWallet;
use App\Vendors\Contracts\ShouldScheduleAccountCreation;
use App\Vendors\Models\AesAccount;
use App\Vendors\Models\AesBetRecord;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Spatie\LaravelData\Data;
use Throwable;

/**
 * @see https://example.invalid/api-doc/multi-wallet/PRD
 */
class Driver extends AbstractTransferWallet implements ShouldScheduleAccountCreation
{
    protected string $accountModel = AesAccount::class;

    protected string $betRecordModel = AesBetRecord::class;

    private VendorInfoDetail $infoDetail;

    private int $errorTimes = 0;

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
        $url = $this->infoDetail->API_URL . '/wallet/doLoginAndLaunchGame';

        $data = [
            'cert' => $this->infoDetail->CERT,
            'agentId' => $this->infoDetail->AGENT,
            'userId' => $this->vendorAccount->username,
            'gameCode' => $params->gameCode,
            'gameType' => 'LIVE',
            'platform' => 'SEXYBCRT',
            'language' => $this->mapLanguageCode(),
            'betLimit' => json_encode([
                'SEXYBCRT' => [
                    'LIVE' => [
                        'limitId' => [$this->infoDetail->BETLIMIT],
                    ],
                ],
            ]),
            'autoBetMode' => $this->infoDetail->AUTO_BET_MODE,
        ];
        // 如果是平板，要多帶這個參數
        if ($params->deviceType === DeviceTypeEnum::PAD) {
            $data['isMobileLogin'] = 1;
        }

        $result = Http::logAction('AES_GetGameUrl', $data)
            ->asForm()
            ->post($url, $data)
            ->throw()
            ->json();

        if (!is_array($result)) {
            throw new ApiException('[AES][GetGameUrl] API error: response is not array.');
        }

        if (!isset($result['status']) || !isset($result['url'])) {
            throw new ApiException('[AES][GetGameUrl] API error: missing response data.');
        }

        if ($result['status'] !== '0000') {
            throw new ApiException('[AES][GetGameUrl] API error: status is not 0000.');
        }

        return new VendorGameUrlResultData(
            pc: $result['url'],
            mobile: $result['url'],
        );
    }

    public function getBalance(): string
    {
        $url = $this->infoDetail->API_URL . '/wallet/getBalance';

        $data = [
            'cert' => $this->infoDetail->CERT,
            'agentId' => $this->infoDetail->AGENT,
            'userIds' => $this->vendorAccount->username,
        ];

        $result = Http::logAction('AES_GetBalance', $data)
            ->asForm()
            ->post($url, $data)
            ->throw()
            ->json();

        if (!is_array($result)) {
            throw new ApiException('[AES][GetBalance] API error: response is not array.');
        }

        if (!isset($result['status']) || $result['status'] !== '0000') {
            throw new ApiException('[AES][GetBalance] API error: status is not 0000.');
        }

        // 遊戲商說如果太久沒登入遊戲 results 回傳就會是空的，所以會再去打一次 getGameUrl
        $balance = 0;
        if (isset($result['results'][0]['balance'])) {
            $balance = $result['results'][0]['balance'];
        } elseif ($this->errorTimes < 1) {
            $this->errorTimes += 1;
            $params = VendorGameUrlParamsData::from([
                'gameCode' => 'MX-LIVE-014',
                'deviceType' => DeviceTypeEnum::PC,
            ]);
            $this->getGameUrl($params);

            return $this->getBalance();
        }

        return Common::numFloor($balance, 4);
    }

    /**
     * Will retry 5 times if the transaction is not exist.
     */
    public function checkTransferStatus(string $transactionNo): VendorTransferResultEnum
    {
        $url = "{$this->infoDetail->API_URL}/wallet/checkTransferOperation";

        $data = [
            'cert' => $this->infoDetail->CERT,
            'agentId' => $this->infoDetail->AGENT,
            'txCode' => $transactionNo,
        ];

        $result = Http::logAction('AES_CheckTransferStatus', $data)
            ->asForm()
            ->post($url, $data)
            ->throw()
            ->json();

        if (!is_array($result)) {
            return VendorTransferResultEnum::TIMEOUT;
        }

        if (!isset($result['status']) || !isset($result['txStatus'])) {
            return VendorTransferResultEnum::TIMEOUT;
        }

        $status = (string) $result['status'];
        $txStatus = (string) $result['txStatus'];

        if ($status === '1017') {
            return VendorTransferResultEnum::FAILED;
        }

        if ($status !== '0000') {
            return VendorTransferResultEnum::TIMEOUT;
        }

        if ($txStatus === '0') {
            return VendorTransferResultEnum::FAILED;
        } elseif ($txStatus === '1') {
            return VendorTransferResultEnum::SUCCESS;
        } elseif ($txStatus === '2') {
            return VendorTransferResultEnum::TIMEOUT;
        }

        return VendorTransferResultEnum::TIMEOUT;
    }

    /**
     * 玩家餘額轉入遊戲商 (存款), origin: type = 1
     */
    public function transferToVendor(VendorTransferData $params): VendorTransferResultEnum
    {
        return $this->transfer($params, 'deposit');
    }

    /**
     * 轉出遊戲商餘額回本地 (提款), origin: type != 1
     */
    public function transferToLocal(VendorTransferData $params): VendorTransferResultEnum
    {
        return $this->transfer($params, 'withdraw');
    }

    private function transfer(VendorTransferData $params, string $action): VendorTransferResultEnum
    {
        $url = "{$this->infoDetail->API_URL}/wallet/{$action}";

        $data = [
            'cert' => $this->infoDetail->CERT,
            'userId' => $this->vendorAccount->username,
            'agentId' => $this->infoDetail->AGENT,
            'txCode' => $params->transactionNo,
            'transferAmount' => round((float) $params->vendorAmount, 3),
        ];
        if ($action === 'withdraw') {
            $data['withdrawType'] = 0;
        }

        $result = Http::logAction("AES_Transfer_{$action}", $data)
            ->asForm()
            ->post($url, $data)
            ->throw()
            ->json();

        $logData = [
            'request' => $data,
            'response' => $result,
        ];

        if (!is_array($result)) {
            Log::error("[AES][Transfer_{$action}] API error: response is not array.", $logData);

            return VendorTransferResultEnum::TIMEOUT;
        }

        if (!isset($result['status'])) {
            Log::error("[AES][Transfer_{$action}] API error: missing response data.", $logData);

            return VendorTransferResultEnum::TIMEOUT;
        }

        $status = (string) $result['status'];
        // 只要 key 的陣列方便後續用 in_array() 判斷
        $statusCode = $this->getStatusCode();
        $validStatusCodes = array_keys($statusCode);

        if (!in_array($status, $validStatusCodes, true)) {
            Log::error("[AES][Transfer_{$action}] API error: exceptional status.", $logData);

            return VendorTransferResultEnum::TIMEOUT;
        }
        // 根據文檔, 非 0000 代表存款失敗但需要請求 checkTransferOperation 確認結果, 所以這裡回傳 timeout.

        if ((string) $result['status'] === '0000') {
            return VendorTransferResultEnum::SUCCESS;
        }

        // 根據文檔, 非 0000 代表存款失敗但需要確認結果, 所以這裡回傳 timeout.
        // 排程會去請求 checkTransferStatus 確認結果
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
            LanguageEnum::ZH_TW->value, LanguageEnum::ZH_CN->value => 'cn',
            LanguageEnum::VI_VN->value => 'vn',
            LanguageEnum::TH_TH->value => 'th',
            default => 'en',
        };
    }

    private function getStatusCode(): array
    {
        return [
            '9998' => 'System Busy', // 系统繁忙
            '9999' => 'Fail', // 失败
            '0000' => 'Success', // 成功
            '11' => 'Do not have this platform under your agent.', // 您的代理底下没有此游戏商
            '1000' => 'Invalid user Id', // 无效的使用者账号
            '1001' => 'Account existed', // 帐号已存在
            '1002' => 'Account is not exists', // 帐号不存在,
            '1004' => 'Invalid Currency', // 无效的货币
            '1005' => 'language is not exists', // 语言不存在
            '1006' => 'PT Setting is empty!', // PT 设定为空,
            '1007' => 'Invalid PT setting with parent!', // PT 设定与上线冲突
            '1008' => 'Invalid token!', // 无效的 token
            '1009' => 'Invalid timeZone', // 无效时区
            '1010' => 'Invalid amount', // 无效的数量
            '1011' => 'Invalid txCode', // 无效的交易代码
            '1012' => 'Has Pending Transfer', // 有待处理的转帐
            '1013' => 'Account is Lock', // 帐号已锁
            '1014' => 'Account is Suspend',    // 帐号暂停
            '1016' => 'TxCode already operation!', // 交易代码已被执行过
            '1017' => 'TxCode is not exist', // 交易代码不存在
            '1018' => 'Not Enough Balance', // 余额不足
            '1019' => 'No Data', // 没有资料
            '1024' => 'Invalid date time format', // 无效的日期(时间) 格式
            '1025' => 'Invalid transaction status', // 无效的交易状态
            '1026' => 'Invalid bet limit setting', // 无效的投注限制设定
            '1027' => 'Invalid Certificate', // 无效的认证码
            '1028' => 'Unable to proceed . please try again later.',  // 无法执行指定的行为，请稍后再试
            '1029' => 'invalid IP address.', // 无效的 IP，通常发生于您的 IP 尚未加白，或是 AWC 辨识不到您的 AgentID
            '1030' => 'invalid Device to call API.', // 使用无效的装置呼叫
            '1031' => 'System is under maintenance.', // 系统维护中
            '1032' => 'Duplicate login.',  // 重复登入
            '1033' => 'Invalid Game',  // 无效的游戏，该platform的Gametype不支援replay:(Gametype)
            '1034' => 'Time does not meet.', // 您使用的时间参数不符合此 API 规定格式
            '1035' => 'Invalid Agent Id.', // 无效的 Agent Id
            '1036' => 'Invalid parameters.', // 无效的参数
            '1038' => 'Duplicate transaction.', // 重复的交易
            '1039' => 'Transaction not found.', // 无此交易
            '1040' => 'Request timeout.',  // 请求逾时
            '1041' => 'HTTP Status error.', // HTTP 状态错误
            '1042' => 'HTTP Response is empty.',    // HTTP 请求空白
            '1043' => 'Bet has canceled.', // 下注已被取消
            '1044' => 'Invalid bet.', // 无效的下注
            '1045' => 'Add account statement failed.', // 帐变记录新增失败
            '1046' => 'Transfer Failed!', // 转帐失败！
            '1047' => 'Game is under maintenance.',  // 游戏维护中
            '1050' => 'Invalid user name', // 无效的user name
            '1054' => 'Invalid Platform', // 无效的游戏平台
            '1056' => '[any parameter] not found', // [任一参数]找不到
        ];
    }

    /**
     * 建立我方遊戲商帳號（遷自 RD-Studio create_aes_account）
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
     * 建立三方遊戲商帳號（AES 無密碼機制，cert 即憑證）
     */
    private function createVendorAccount(string $username): bool
    {
        $data = [
            'cert' => $this->infoDetail->CERT,
            'agentId' => $this->infoDetail->AGENT,
            'userId' => $username,
            // VendorInfoDetail 未宣告 CURRENCY，直接取商戶原始設定
            'currency' => $this->vendorInfo->info['CURRENCY'] ?? $this->vendorInfo->currency,
            'betLimit' => json_encode([
                'SEXYBCRT' => [
                    'LIVE' => [
                        'limitId' => [$this->infoDetail->BETLIMIT],
                    ],
                ],
            ]),
        ];

        try {
            $result = Http::logAction('AES_CreateMember', $data)
                ->asForm()
                ->post($this->infoDetail->API_URL . '/wallet/createMember', $data)
                ->throw()
                ->json();
        } catch (Throwable $e) {
            Log::error('[AES][CreateMember] API error', [
                'username' => $username,
                'error' => $e->getMessage(),
                'trace' => Common::formatTrace($e),
            ]);

            return false;
        }

        if (!is_array($result) || (string) ($result['status'] ?? '') !== '0000') {
            Log::error('[AES][CreateMember] API error: status is not 0000', [
                'username' => $username,
                'response' => $result,
            ]);

            return false;
        }

        return true;
    }
}

class VendorInfoDetail extends Data
{
    public function __construct(
        public string $API_URL,
        public string $CERT,
        public string $AGENT,
        public string $BETLIMIT,
        public float $AUTO_BET_MODE,
    ) {
        $this->API_URL = trim($this->API_URL, '/');
    }
}
