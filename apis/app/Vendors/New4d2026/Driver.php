<?php

declare(strict_types=1);

namespace App\Vendors\New4d2026;

use App\Data\Vendor\CreateAccountParamsData;
use App\Data\Vendor\CreateAccountResultData;
use App\Data\VendorGameUrlParamsData;
use App\Data\VendorGameUrlResultData;
use App\Data\VendorTransferData;
use App\Enums\LanguageEnum;
use App\Enums\Vendor\VendorTransferResultEnum;
use App\Exceptions\ApiException;
use App\Helpers\Common;
use App\Models\Member;
use App\Vendors\AbstractTransferWallet;
use App\Vendors\Contracts\ShouldScheduleAccountCreation;
use App\Vendors\Models\New4d2026Account;
use App\Vendors\Models\New4d2026BetRecord;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Spatie\LaravelData\Data;
use Throwable;

/**
 * @property New4d2026Account $vendorAccount
 */
class Driver extends AbstractTransferWallet implements ShouldScheduleAccountCreation
{
    protected string $accountModel = New4d2026Account::class;

    protected string $betRecordModel = New4d2026BetRecord::class;

    private string $actionPrefix = 'New4d2026_';

    protected string $maintenanceStart = '';

    protected string $maintenanceEnd = '';

    private VendorInfoDetailData $vendorInfoDetail;

    public function __construct(int $vendorId, ?Member $member)
    {
        parent::__construct($vendorId, $member);

        $this->loadMaintenanceWindow();

        if (!$member) {
            return;
        }

        $this->setInfo($this->vendorInfo->info);
    }

    public function getMaintenanceWindow(): array
    {
        return [
            'start' => $this->maintenanceStart,
            'end' => $this->maintenanceEnd,
        ];
    }

    private function loadMaintenanceWindow(): void
    {
        $info = json_decode($this->vendor->info ?? '{}', true) ?? [];
        $this->maintenanceStart = $info['maintenance_start'] ?? '';
        $this->maintenanceEnd = $info['maintenance_end'] ?? '';
    }

    public function setInfo(array $info): void
    {
        $this->vendorInfoDetail = VendorInfoDetailData::from($info);
    }

    public function getGameUrl(VendorGameUrlParamsData $params): VendorGameUrlResultData
    {
        $action = $this->actionPrefix . 'getGameUrl';
        $url = $this->vendorInfoDetail->API_URL . 'gettoken.php';
        $data = [
            'user' => $this->vendorAccount->username,
        ];

        $json = $this->post($action, $url, $data);

        $tokenCode = (string) $json['data'];
        $lang = $this->mapLanguageCode($params->language);
        $gameUrl = $this->vendorInfoDetail->API_DESKTOP_URL . '?token=' . $tokenCode . '&lang=' . $lang;
        $mobileGameUrl = $this->vendorInfoDetail->API_HTML5_URL . '?token=' . $tokenCode . '&lang=' . $lang;

        return VendorGameUrlResultData::from([
            'pc' => $gameUrl,
            'mobile' => $mobileGameUrl,
        ]);
    }

    public function getBalance(): string
    {
        $data = [
            'user' => $this->vendorAccount->username,
        ];

        $url = $this->vendorInfoDetail->API_URL . 'getbalance.php';
        $action = $this->actionPrefix . 'getBalance';
        $result = $this->post($action, $url, $data);

        if (!isset($result['status'])) {
            throw new ApiException('third party error: status is not set');
        }

        if ((string) $result['status'] != 'success') {
            throw new ApiException('third party error: status is not success');
        }

        if (!isset($result['balance'])) {
            throw new ApiException('third party error: balance is not set');
        }

        return Common::numFloor($result['balance'], 4);
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

    private function isInMaintenanceWindow(): bool
    {
        if ($this->maintenanceStart === '' || $this->maintenanceEnd === '') {
            return false;
        }

        $now = Carbon::now('Asia/Taipei');
        $start = $now->copy()->setTimeFromTimeString($this->maintenanceStart);
        $end = $now->copy()->setTimeFromTimeString($this->maintenanceEnd);

        return $now->between($start, $end);
    }

    private function transfer(int $type, VendorTransferData $params): VendorTransferResultEnum
    {
        if ($this->isInMaintenanceWindow()) {
            return VendorTransferResultEnum::FAILED;
        }

        if ($type == 1) {
            $action = $this->actionPrefix . 'deposit';
            $url = $this->vendorInfoDetail->API_URL . 'topup.php';
        } else {
            $action = $this->actionPrefix . 'withdraw';
            $url = $this->vendorInfoDetail->API_URL . 'withdraw.php';
        }

        $postData = [
            'user' => $this->vendorAccount->username,
            'amount' => Common::numFloor($params->vendorAmount, 2),
        ];

        $result = $this->post($action, $url, $postData);

        if (!isset($result['status'])) {
            throw new ApiException('third party error: status is not set');
        }

        if ((string) $result['status'] != 'success') {
            throw new ApiException('third party error: status is not success');
        }

        return VendorTransferResultEnum::SUCCESS;
    }

    private function post(string $action, string $url, array $data): array
    {
        $data['apiUser'] = $this->vendorInfoDetail->API_USER;
        $data['apiPass'] = $this->vendorInfoDetail->API_PASSWORD;

        $response = Http::logAction($action, $data)
            ->asForm()
            ->throw()
            ->post($url, $data);

        $json = $response->json();

        if (empty($json)) {
            throw new ApiException('third party error: Invalid response: invalid json');
        }

        if (!empty($json['errorCode'])) {
            $errorCode = $json['errorCode'];
            $errorMessage = $json['errorMessage'] ?? 'Unknown error';
            throw new ApiException("third party error: Invalid response: errorCode: {$errorCode}, desc: {$errorMessage}");
        }

        return $json;
    }

    private function mapLanguageCode(LanguageEnum $language): string
    {
        return match ($language) {
            LanguageEnum::EN => '0',
            LanguageEnum::ZH_TW => '1',
            LanguageEnum::ZH_CN => '1',
            LanguageEnum::MS_MY => '2',
            default => '0',
        };
    }

    /**
     * 建立我方遊戲商帳號（遷自 RD-Studio create_new4d2026_account）
     *
     * new4d2026_accounts.password 為 NOT NULL 無 default，且三方建號必送 loginPass，
     * 先自產密碼落庫再送三方（同舊系統 Str::random(16)）；
     * 登入路徑（gettoken.php）只送 username，不使用本密碼。
     */
    public function createAccount(CreateAccountParamsData $params): CreateAccountResultData
    {
        $this->setVendorInfo($params->vendorInfo);
        $username = $params->username;
        $password = [REDACTED];

        $account = $this->accountModel::create([
            'vendor_info_id' => $params->vendorInfo->id,
            'username' => $username,
            'password' => $password,
            'admin' => 1,
            'member_id' => -999999999,
        ]);

        if (!$account) {
            return CreateAccountResultData::from(['status' => false, 'username' => $username]);
        }

        if (!$this->createVendorAccount($username, $password)) {
            return CreateAccountResultData::from(['status' => false, 'username' => $username]);
        }

        $account->update(['member_id' => 0]);

        return CreateAccountResultData::from(['status' => true, 'username' => $username]);
    }

    /**
     * 建立三方遊戲商帳號（createuser.php；post() 會自動補 apiUser／apiPass 並檢查 errorCode）
     */
    private function createVendorAccount(string $username, string $password): bool
    {
        $action = $this->actionPrefix . 'register';
        $url = $this->vendorInfoDetail->API_URL . 'createuser.php';
        $agent = $this->vendorInfoDetail->API_AGENT;

        if ($agent === '') {
            Log::error("[New4d2026][{$action}] vendor_info.info.API_AGENT is empty", ['username' => $username]);

            return false;
        }

        $data = [
            'user' => $agent,
            'loginID' => $username,
            'loginPass' => $password,
            'fullName' => $username,
            'commZero' => 0,
            'agent' => 0,
        ];

        try {
            $json = $this->post($action, $url, $data);
        } catch (Throwable $e) {
            Log::error("[New4d2026][{$action}] request failed", [
                'username' => $username,
                'error' => $e->getMessage(),
                'trace' => Common::formatTrace($e),
            ]);

            return false;
        }

        if (($json['status'] ?? '') !== 'success') {
            Log::error("[New4d2026][{$action}] API error: status is not success", [
                'username' => $username,
                'status' => $json['status'] ?? null,
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
        public string $API_DESKTOP_URL,
        public string $API_HTML5_URL,
        public string $API_USER,
        public string $API_PASSWORD,

        /** 建號專用（createuser.php 的 user 參數）；僅建號路徑需要，故給預設值不強制 */
        public string $API_AGENT = '',
    ) {}
}
