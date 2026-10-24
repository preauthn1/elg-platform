<?php

declare(strict_types=1);

namespace App\Vendors\Obet33;

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
use App\Vendors\Models\Obet33Account;
use App\Vendors\Models\Obet33BetRecord;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class Driver extends AbstractTransferWallet implements ShouldScheduleAccountCreation
{
    protected string $accountModel = Obet33Account::class;

    protected string $betRecordModel = Obet33BetRecord::class;

    protected string $actionTitle = 'OBET33';

    protected string $apiUrl;

    protected string $agentId;

    protected string $apiKey;

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
        $this->agentId = $info['AGENT_ID'];
        $this->apiKey = [REDACTED]'API_KEY'];
    }

    public function getGameUrl(VendorGameUrlParamsData $params): VendorGameUrlResultData
    {
        $action = $this->actionTitle . '_api_getGameUrl';
        $language = $this->getLanguage($params->language->value);

        $url = $this->apiUrl . '/api/sb/open';
        $username = $this->agentId . $this->vendorAccount->username;

        $data = [
            'hash' => $this->sign($username),
            'agentId' => $this->agentId,
            'userId' => $username,
            'lang' => $language,
            'mobile' => $params->deviceType === DeviceTypeEnum::MOBILE ? 1 : 0,
        ];

        $response = Http::logAction($action)
            ->withoutVerifying()
            ->get($url, $data)
            ->throwIf($this->shouldThrowHttpError(...));

        $json = $response->json();

        if (!$json) {
            throw new ApiException('Third party error: not json response');
        }

        if (!isset($json['Status'])) {
            throw new ApiException('Third party error: format error');
        }

        if ((string) $json['Status'] != 'success') {
            throw new ApiException('Third party error: error code ' . $json['Status'] . ', message: ' . ($json['Message'] ?? ''));
        }

        $url = $json['Data']['LoginUrl'];

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
        $action = $this->actionTitle . '_api_getBalance';
        $url = $this->apiUrl . '/api/sb/bal';
        $username = $this->agentId . $this->vendorAccount->username;
        $data = [
            'hash' => $this->sign($username),
            'agentId' => $this->agentId,
            'userId' => $username,
        ];

        $response = Http::logAction($action)->get($url, $data)->throwIf($this->shouldThrowHttpError(...));
        $json = $response->json();

        if (!$json) {
            throw new ApiException('Third party error: not json response');
        }

        if (!isset($json['Status'])) {
            throw new ApiException('Third party error: format error');
        }

        if ((string) $json['Status'] != 'success') {
            throw new ApiException('Third party error: error code ' . $json['Status'] . ', message: ' . ($json['Message'] ?? ''));
        }

        $balance = $json['Data']['Balance'];

        return (string) $balance;
    }

    /**
     * 轉帳
     */
    protected function transfer(string $username, int $type, string $credit, string $billNo): VendorTransferResultEnum
    {
        if ($type == 1) {
            // 存款
            $url = $this->apiUrl . '/api/sb/fund';
            $action = $this->actionTitle . '_Deposit';
            $amount = (float) Common::numAdd('0', $credit);
        } else {
            // 提款
            $url = $this->apiUrl . '/api/sb/fund';
            $action = $this->actionTitle . '_Withdrawal';
            $amount = (float) Common::numSub('0', $credit);
        }

        $username = $this->agentId . $username;

        $data = [
            'hash' => $this->sign($username),
            'agentId' => $this->agentId,
            'userId' => $username,
            'amount' => $amount,
            'serialNo' => $billNo,
        ];

        $response = Http::logAction($action)->post($url, $data);
        $json = $response->json();

        if (!$json) {
            return VendorTransferResultEnum::TIMEOUT;
        }

        if (!isset($json['Status'])) {
            throw new ApiException('Third party error: format error');
        }

        $status = (string) $json['Status'];
        if ($status == 'success') {
            return VendorTransferResultEnum::SUCCESS;
        }
        if ($status == 'fail') {
            return VendorTransferResultEnum::FAILED;
        }

        return VendorTransferResultEnum::TIMEOUT;
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
     * 轉帳確認
     */
    public function checkTransferStatus(string $transactionNo): VendorTransferResultEnum
    {
        $action = $this->actionTitle . '_CheckTransferStatus';
        $url = $this->apiUrl . '/api/sb/fund/check';

        $username = $this->agentId . $this->vendorAccount->username;

        $data = [
            'hash' => $this->sign($username),
            'agentId' => $this->agentId,
            'userId' => $username,
            'serialNo' => $transactionNo,
        ];

        // 失敗的話，三方會回傳 400 錯誤，但有json，所以這邊不使用 throw
        $response = Http::logAction($action)->post($url, $data);
        $json = $response->json();

        if (!$json) {
            return VendorTransferResultEnum::TIMEOUT;
        }

        if (!isset($json['Status'])) {
            return VendorTransferResultEnum::TIMEOUT;
        }

        $status = (string) $json['Status'];
        if ($status == 'success') {
            return VendorTransferResultEnum::SUCCESS;
        }
        if ($status == 'fail') {
            return VendorTransferResultEnum::FAILED;
        }

        return VendorTransferResultEnum::TIMEOUT;
    }

    /**
     * 三方業務錯誤會以 4xx ＋ 完整 JSON envelope 回（見 checkTransferStatus 的註解），
     * 交由各方法的 Status 判斷拋 ApiException（AlertGate 靜音）；
     * 只有連 envelope 都沒有的傳輸／伺服器錯誤才拋 RequestException 發 TG 告警。
     */
    protected function shouldThrowHttpError(Response $response): bool
    {
        return !isset($response->json()['Status']);
    }

    protected function getLanguage(int $languageId): string
    {
        return match ($languageId) {
            LanguageEnum::EN->value => 'en',
            LanguageEnum::ZH_TW->value => 'zh-cn',
            LanguageEnum::ZH_CN->value => 'zh-cn',
            LanguageEnum::TH_TH->value => 'th-th',
            LanguageEnum::VI_VN->value => 'vi-vn',
            default => 'en',
        };
    }

    private function sign($username): string
    {
        $str = "k=$this->apiKey&a=$this->agentId&u=$username";
        // 加密要回傳大寫

        return strtoupper(md5($str));
    }

    /**
     * 建立我方遊戲商帳號（遷自 RD-Studio create_obet33_account）
     *
     * Obet33 無密碼機制；注單回傳一律大寫，username_modified 存大寫版本供注單 JOIN。
     */
    public function createAccount(CreateAccountParamsData $params): CreateAccountResultData
    {
        $this->setVendorInfo($params->vendorInfo);
        $username = $params->username;
        // 三方建號一定要加 AgentId 當前綴
        $vendorUserId = $this->agentId . $username;

        $account = $this->accountModel::create([
            'vendor_info_id' => $params->vendorInfo->id,
            'username' => $username,
            'username_modified' => strtoupper($vendorUserId),
            'admin' => 1,
            'member_id' => -999999999,
        ]);

        if (!$account) {
            return CreateAccountResultData::from(['status' => false, 'username' => $username]);
        }

        $betLimit = $params->vendorInfo->info['BET_LIMIT'] ?? [];
        $created = $this->createVendorAccount($vendorUserId, is_array($betLimit) ? $betLimit : []);

        // 三方限制 1 秒只能呼叫一次（比照舊系統逐筆間隔）
        sleep(1);

        if (!$created) {
            return CreateAccountResultData::from(['status' => false, 'username' => $username]);
        }

        $account->update(['member_id' => 0]);

        return CreateAccountResultData::from(['status' => true, 'username' => $username]);
    }

    /**
     * 建立三方遊戲商帳號
     *
     * @param  array<string, mixed>  $betLimit  商戶設定的限紅欄位，直接攤平併入 payload
     */
    private function createVendorAccount(string $vendorUserId, array $betLimit): bool
    {
        $data = [
            'hash' => $this->sign($vendorUserId),
            'agentId' => $this->agentId,
            'userId' => $vendorUserId,
            ...$betLimit,
        ];

        try {
            $json = Http::logAction($this->actionTitle . '_api_createAccount')
                ->post($this->apiUrl . '/api/sb/register', $data)
                ->throwIf($this->shouldThrowHttpError(...))
                ->json();
        } catch (Throwable $e) {
            Log::error('[Obet33][createAccount] request failed', [
                'userId' => $vendorUserId,
                'error' => $e->getMessage(),
                'trace' => Common::formatTrace($e),
            ]);

            return false;
        }

        if (!is_array($json) || (string) ($json['Status'] ?? '') !== 'success') {
            Log::error('[Obet33][createAccount] Third party error', [
                'userId' => $vendorUserId,
                'response' => $json,
            ]);

            return false;
        }

        return true;
    }
}
