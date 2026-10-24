<?php

declare(strict_types=1);

namespace App\Vendors\E88Sport;

use App\Data\Vendor\CreateAccountParamsData;
use App\Data\Vendor\CreateAccountResultData;
use App\Data\VendorGameUrlParamsData;
use App\Data\VendorGameUrlResultData;
use App\Data\VendorTransferData;
use App\Enums\General\DeviceTypeEnum;
use App\Enums\LanguageEnum;
use App\Enums\Vendor\VendorTransferResultEnum;
use App\Exceptions\ApiException;
use App\Helpers\Common;
use App\Models\Member;
use App\Vendors\AbstractTransferWallet;
use App\Vendors\Contracts\ShouldScheduleAccountCreation;
use App\Vendors\Models\E88SportAccount;
use App\Vendors\Models\E88SportBetRecord;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use SimpleXMLElement;

class Driver extends AbstractTransferWallet implements ShouldScheduleAccountCreation
{
    protected string $accountModel = E88SportAccount::class;

    protected string $betRecordModel = E88SportBetRecord::class;

    protected string $vendorLabel = 'E88Sport';

    protected string $apiUrl;

    protected string $desktopUrl;

    protected string $mobileUrl;

    protected string $apiPassword;

    protected string $agentAccount;

    protected string $currency;

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
        $this->apiUrl = rtrim($info['API_URL'], '/');
        $this->desktopUrl = rtrim($info['DESKTOP_URL'] ?? '', '/');
        $this->mobileUrl = rtrim($info['MOBILE_URL'] ?? '', '/');
        $this->apiPassword = [REDACTED]'API_PASSWORD'];
        $this->agentAccount = $info['AGENT_ACCOUNT'];
        $this->currency = $info['CURRENCY'];
    }

    public function createAccount(CreateAccountParamsData $params): CreateAccountResultData
    {
        $this->setVendorInfo($params->vendorInfo);
        $this->setInfo($params->vendorInfo->info);

        $account = $this->accountModel::create([
            'vendor_info_id' => $params->vendorInfo->id,
            'username' => $params->username,
        ]);

        if (!$account) {
            return CreateAccountResultData::from(['status' => false, 'username' => $params->username]);
        }

        $xml = $this->sendRequest('Register', [
            'APIPassword' => $this->apiPassword,
            'AgentAccount' => $this->agentAccount,
            'MemberAccount' => $params->username,
            'NickName' => $params->username,
            'Currency' => $this->currency,
        ]);

        $errcode = (string) $xml->errcode;

        // 100002 = 帳號已存在，視為成功（冪等）
        if ($errcode !== '000000' && $errcode !== '100002') {
            Log::error("{$this->vendorLabel} createAccount failed", [
                'username' => $params->username,
                'errcode' => $errcode,
                'errtext' => (string) $xml->errtext,
            ]);
            $account->update(['member_id' => -999999999]);

            return CreateAccountResultData::from(['status' => false, 'username' => $params->username]);
        }

        return CreateAccountResultData::from(['status' => true, 'username' => $params->username]);
    }

    public function getBalance(): string
    {
        /** @var E88SportAccount $account */
        $account = $this->vendorAccount;

        $xml = $this->sendRequest('GetBalance', [
            'APIPassword' => $this->apiPassword,
            'MemberAccount' => $account->username,
        ]);

        $errcode = (string) $xml->errcode;
        if ($errcode !== '000000') {
            throw new ApiException("[{$this->vendorLabel}][GetBalance] errcode={$errcode}");
        }

        return Common::numAdd('0', (string) $xml->result->Balance, 4);
    }

    public function getGameUrl(VendorGameUrlParamsData $params): VendorGameUrlResultData
    {
        /** @var E88SportAccount $account */
        $account = $this->vendorAccount;

        $xml = $this->sendRequest('Login', [
            'APIPassword' => $this->apiPassword,
            'MemberAccount' => $account->username,
        ]);

        $errcode = (string) $xml->errcode;
        if ($errcode !== '000000') {
            throw new ApiException("[{$this->vendorLabel}][Login] errcode={$errcode}");
        }

        $loginCode = (string) $xml->result;
        $lang = $this->mapLanguageCode($params->language);
        $suffix = '/Memberlogin.aspx?LoginCode=' . $loginCode . '&Language=' . $lang . '&PageStyle=1&OddsStyle=1';

        $isMobile = $params->deviceType === DeviceTypeEnum::MOBILE || $params->deviceType === DeviceTypeEnum::PAD;
        $gameUrl = ($isMobile ? $this->mobileUrl : $this->desktopUrl) . $suffix;

        return VendorGameUrlResultData::from([
            'pc' => $gameUrl,
            'mobile' => $gameUrl,
        ]);
    }

    public function transferToVendor(VendorTransferData $params): VendorTransferResultEnum
    {
        return $this->doTransfer(transferType: 0, params: $params);
    }

    public function transferToLocal(VendorTransferData $params): VendorTransferResultEnum
    {
        return $this->doTransfer(transferType: 1, params: $params);
    }

    public function checkTransferStatus(string $transactionNo): VendorTransferResultEnum
    {
        try {
            $xml = $this->sendRequest('CheckTransfer2', [
                'APIPassword' => $this->apiPassword,
                'SerialNumber' => $transactionNo,
            ]);
        } catch (\Throwable $e) {
            Log::error("{$this->vendorLabel} checkTransferStatus exception", [
                'transaction_no' => $transactionNo,
                'error' => $e->getMessage(),
                'trace' => Common::formatTrace($e),
            ]);

            return VendorTransferResultEnum::TIMEOUT;
        }

        $errcode = (string) $xml->errcode;

        if ($errcode === '000000') {
            return VendorTransferResultEnum::SUCCESS;
        }

        // 400001 = 流水號不存在（7 天內查無記錄，視為失敗）
        if ($errcode === '400001') {
            return VendorTransferResultEnum::FAILED;
        }

        return VendorTransferResultEnum::TIMEOUT;
    }

    private function doTransfer(int $transferType, VendorTransferData $params): VendorTransferResultEnum
    {
        /** @var E88SportAccount $account */
        $account = $this->vendorAccount;
        $memberAccount = $account->username;
        // E88 要求 4 位小數格式
        $amount = number_format((float) $params->vendorAmount, 4, '.', '');
        // 文件標示「前6位」但範例驗算（9eebc4）實為後 6 碼
        $key = substr(md5(strtolower($this->apiPassword . $memberAccount . $amount)), -6);

        try {
            $xml = $this->sendRequest('Transfer', [
                'APIPassword' => $this->apiPassword,
                'MemberAccount' => $memberAccount,
                'SerialNumber' => $params->transactionNo,
                'Amount' => $amount,
                'TransferType' => $transferType,
                'Key' => $key,
            ]);
        } catch (\Throwable $e) {
            Log::error("{$this->vendorLabel} transfer exception", [
                'member_account' => $memberAccount,
                'transaction_no' => $params->transactionNo,
                'transfer_type' => $transferType,
                'amount' => $amount,
                'error' => $e->getMessage(),
                'trace' => Common::formatTrace($e),
            ]);

            return VendorTransferResultEnum::TIMEOUT;
        }

        $errcode = (string) $xml->errcode;

        return match ($errcode) {
            '000000' => VendorTransferResultEnum::SUCCESS,
            // 300003 = 流水號已存在，表示先前已轉帳成功（冪等）
            '300003' => VendorTransferResultEnum::SUCCESS,
            // 300004 = 玩家餘額不足；300005 = Key 驗證失敗；100001 = 幣別不可用
            '300004', '300005', '100001' => VendorTransferResultEnum::FAILED,
            default => VendorTransferResultEnum::TIMEOUT,
        };
    }

    // -------------------------------------------------------------------------
    // SupportsBetRecordFetch
    // -------------------------------------------------------------------------

    public function betRecordApiWindowMinutes(): int
    {
        return 30;
    }

    public function betRecordCredentialKey(array $info): string
    {
        // 拉單以代理帳號維度查詢，同 AGENT_ACCOUNT 回同一批注單
        return (string) ($info['AGENT_ACCOUNT'] ?? '');
    }

    /**     * @return array<int, array<string, mixed>>
     */
    public function fetchBetRecords(string $fromDate, string $toDate): array
    {
        return $this->getBetSheet($fromDate, $toDate);
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>
     */
    public function formatBetRecord(array $raw, string $rate): array
    {
        $betId = (string) ($raw['BetID'] ?? '');

        // BetAmount = 投注額；Win = 輸贏淨額（負值=輸）
        $betAmount = Common::numDiv(
            Common::numAdd('0', (string) ($raw['BetAmount'] ?? '0'), 4),
            $rate,
            4
        );
        $winLose = Common::numDiv(
            Common::numAdd('0', (string) ($raw['Win'] ?? '0'), 4),
            $rate,
            4
        );
        $goalJackpot = Common::numDiv(
            Common::numAdd('0', (string) ($raw['GoalJackpot'] ?? '0'), 4),
            $rate,
            4
        );

        // 實際派彩 = 投注額 + 輸贏淨額（輸時 win=0）
        $win = Common::numAdd($betAmount, $winLose, 4);

        $status = match (strtolower((string) ($raw['Status'] ?? ''))) {
            'settled' => 1,
            'rejected' => 2,
            default => 0,
        };

        $raw['fetched_at'] = Carbon::now()->toDateTimeString();

        return [
            'bet_id' => $betId,
            'reference_no' => $betId,
            'username' => (string) ($raw['Account'] ?? ''),
            'game_code' => 'e88sport',
            'bet' => $betAmount,
            'valid_bet' => $betAmount,
            'win' => $win,
            'win_lose' => $winLose,
            'jackpot' => $goalJackpot,
            'bonus' => '0.0000',
            'rolling_chip' => '0.0000',
            'bet_time' => (string) ($raw['BetDate'] ?? ''),
            'payout_time' => (string) ($raw['ReportDate'] ?? ''),
            'status' => $status,
            'info' => [$raw],
        ];
    }

    public function getBetRecordModelClass(): string
    {
        return $this->betRecordModel;
    }

    public function getAccountModelClass(): string
    {
        return $this->accountModel;
    }

    // -------------------------------------------------------------------------

    /**
     * 拉取已結算注單（Status=2 Settled），FromDate~ToDate 不可超過 30 分鐘
     *
     * @return array<int, array<string, mixed>>
     *
     * @throws ApiException
     */
    private function getBetSheet(string $fromDate, string $toDate): array
    {
        $xml = $this->sendRequest('GetBetSheet', [
            'APIPassword' => $this->apiPassword,
            'MemberAccount' => '',
            'BetID' => 0,
            'Status' => 2,
            'FromDate' => $fromDate,
            'ToDate' => $toDate,
        ]);

        $errcode = (string) $xml->errcode;
        if ($errcode !== '000000') {
            throw new ApiException("[{$this->vendorLabel}][GetBetSheet] errcode={$errcode} from={$fromDate} to={$toDate}");
        }

        if (!isset($xml->result->bet)) {
            return [];
        }

        $records = [];
        foreach ($xml->result->bet as $bet) {
            $records[] = json_decode((string) json_encode($bet), true);
        }

        return $records;
    }

    /**
     * 發送 GET 請求至 E88 SportAPI，回傳解析後的 SimpleXMLElement
     */
    private function sendRequest(string $action, array $params): SimpleXMLElement
    {
        $url = $this->apiUrl . '/sportapi.asmx/' . $action;

        $response = Http::logAction("{$this->vendorLabel}_{$action}")
            ->withoutVerifying()
            ->get($url, $params)
            ->throw();

        $xml = simplexml_load_string($response->body());
        if ($xml === false) {
            throw new ApiException("[{$this->vendorLabel}][{$action}] Invalid XML response");
        }

        return $xml;
    }

    private function mapLanguageCode(LanguageEnum $language): string
    {
        return match ($language->value) {
            LanguageEnum::ZH_CN->value => 'CH',
            LanguageEnum::ZH_TW->value => 'TW',
            LanguageEnum::TH_TH->value => 'TH',
            LanguageEnum::VI_VN->value => 'VN',
            LanguageEnum::EN->value => 'EN',
            default => 'EN',
        };
    }
}
