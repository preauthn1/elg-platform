<?php

declare(strict_types=1);

namespace App\Vendors\Haolong;

use App\Data\Vendor\CreateAccountParamsData;
use App\Data\Vendor\CreateAccountResultData;
use App\Data\VendorGameUrlParamsData;
use App\Data\VendorGameUrlResultData;
use App\Data\VendorResetPasswordData;
use App\Data\VendorTransferData;
use App\Enums\LanguageEnum;
use App\Enums\Vendor\VendorTransferResultEnum;
use App\Exceptions\ApiException;
use App\Helpers\Common;
use App\Models\Member;
use App\Vendors\AbstractTransferWallet;
use App\Vendors\Contracts\ShouldScheduleAccountCreation;
use App\Vendors\Models\HaolongAccount;
use App\Vendors\Models\HaolongBetRecord;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use SimpleXMLElement;
use Spatie\LaravelData\Data;
use Throwable;

class Driver extends AbstractTransferWallet implements ShouldScheduleAccountCreation
{
    protected string $accountModel = HaolongAccount::class;

    protected string $betRecordModel = HaolongBetRecord::class;

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

    public function setInfo(array $info): void
    {
        $this->vendorInfoDetail = VendorInfoDetailData::from($info);
    }

    public function resetPassword(VendorResetPasswordData $params): VendorTransferResultEnum
    {
        $data = [
            'loginID' => $this->vendorAccount->username,
            'newpass' => $params->newPassword,
        ];
        if ($this->vendorInfoDetail->USE_PREFIX == 1) {
            $data['loginID'] = $this->vendorInfoDetail->HAOLONG_USER . $data['loginID'];
        }

        $result = $this->post('changePasswordStatus.aspx', $data);

        if ($result === false) {
            throw new ApiException('[Haolong][resetPassword] Invalid response: no result');
        }

        if (!isset($result->errorCode)) {
            throw new ApiException('[Haolong][resetPassword] Invalid response: no errorCode');
        }

        if ((int) $result->errorCode !== 0) {
            Log::error("[Haolong][resetPassword] Invalid response: errorCode is not 0, errorCode: {$result->errorCode}, desc: {$result->desc}");

            return VendorTransferResultEnum::FAILED;
        }

        Log::info("[Haolong][resetPassword] Success, balance: {$result->balance}");

        return VendorTransferResultEnum::SUCCESS;
    }

    public function getGameUrl(VendorGameUrlParamsData $params): VendorGameUrlResultData
    {

        if (isset($this->vendorAccount->is_vworld) && $this->vendorAccount->is_vworld == 1) {
            $password = [REDACTED]';
        } else {
            $password = [REDACTED], $this->vendorAccount->created_at->toDateTimeString());
        }

        $data = [
            'user' => $this->vendorAccount->username,
            'pass' => $password,
        ];

        if ($this->vendorInfoDetail->USE_PREFIX == 1) {
            $data['user'] = $this->vendorInfoDetail->HAOLONG_USER . $data['user'];
        }

        $login_info = $this->post('betLogin.aspx', $data);

        if ($login_info === false) {
            throw new ApiException('[Haolong][getGameUrl] Invalid response: no login_info');
        }

        if (!isset($login_info->sessionID)) {
            throw new ApiException('[Haolong][getGameUrl] Invalid response: no sessionID');
        }

        if (!isset($login_info->tokenCode)) {
            throw new ApiException('[Haolong][getGameUrl] Invalid response: no tokenCode');
        }

        $sessionID = (string) $login_info->sessionID;
        $tokenCode = (string) $login_info->tokenCode;
        $lang = $this->mapLanguageCode($params->language);
        $urlParams = [
            'user' => $data['user'],
            'lang' => $lang,
            'sessionID' => $sessionID,
            'tokenCode' => $tokenCode,
        ];
        $gameUrl = $this->vendorInfoDetail->API_URL . 'bet.aspx?' . http_build_query($urlParams);
        $mobileGameUrl = $this->vendorInfoDetail->API_HTML5_URL . '?' . http_build_query($urlParams);

        return VendorGameUrlResultData::from([
            'pc' => $gameUrl,
            'mobile' => $mobileGameUrl,
        ]);
    }

    public function getBalance(): string
    {
        $data = [
            'loginID' => $this->vendorAccount->username,
        ];
        if ($this->vendorInfoDetail->USE_PREFIX == 1) {
            $data['loginID'] = $this->vendorInfoDetail->HAOLONG_USER . $data['loginID'];
        }

        $result = $this->post('getProfile.aspx', $data);

        if ($result === false) {
            throw new ApiException('[Haolong][getBalance] Invalid response: no result');
        }

        if (!isset($result->errorCode)) {
            throw new ApiException('[Haolong][getBalance] Invalid response: no errorCode');
        }

        if ((int) $result->errorCode !== 0) {
            throw new ApiException("[Haolong][getBalance] Invalid response: errorCode is not 0, errorCode: {$result->errorCode}, desc: {$result->desc}");
        }

        if (!isset($result->balance)) {
            throw new ApiException('[Haolong][getBalance] Invalid response: no balance');
        }

        // 移除逗號
        $balance = str_replace(',', '', (string) $result->balance);

        return Common::numFloor($balance, 4);
    }

    /**
     * 玩家餘額轉入遊戲商 (存款), origin: type = 1
     */
    public function transferToVendor(VendorTransferData $params): VendorTransferResultEnum
    {
        $url = 'deposit.aspx';

        return $this->transfer($params, $url);
    }

    /**
     * 轉出遊戲商餘額回本地 (提款), origin: type != 1
     */
    public function transferToLocal(VendorTransferData $params): VendorTransferResultEnum
    {
        $url = 'withdraw.aspx';

        return $this->transfer($params, $url);
    }

    private function transfer(VendorTransferData $params, string $url): VendorTransferResultEnum
    {
        if ($this->isInMaintenanceWindow()) {
            return VendorTransferResultEnum::FAILED;
        }

        $postData = [
            'loginID' => $this->vendorAccount->username,
            'amount' => $params->vendorAmount,
        ];
        if ($this->vendorInfoDetail->USE_PREFIX == 1) {
            $postData['loginID'] = $this->vendorInfoDetail->HAOLONG_USER . $postData['loginID'];
        }

        $result = $this->post($url, $postData);

        if ($result === false) {
            Log::error("[Haolong][{$url}] Invalid response: no result");

            return VendorTransferResultEnum::TIMEOUT; // 無法判斷的錯誤，先不還錢
        }

        if (!isset($result->errorCode)) {
            Log::error("[Haolong][{$url}] Invalid response: no errorCode");

            return VendorTransferResultEnum::TIMEOUT; // 無法判斷的錯誤，先不還錢
        }

        if ((int) $result->errorCode !== 0) {
            Log::error("[Haolong][{$url}] Invalid response: errorCode is not 0, errorCode: {$result->errorCode}, desc: {$result->desc}");

            return VendorTransferResultEnum::FAILED;
        }

        return VendorTransferResultEnum::SUCCESS;
    }

    private function post(string $endpoint, array $data): SimpleXMLElement|false
    {
        $data['apiUser'] = $this->vendorInfoDetail->API_USER;
        $data['apiPass'] = $this->vendorInfoDetail->API_PASSWORD;

        if (!isset($data['user'])) {
            if (is_object($this->vendorAccount) && $this->vendorAccount->is_vworld == 1) {
                // vworld 舊帳號有代理階層
                $agent = $this->member->agent;
                if (is_object($agent)) {
                    $data['user'] = $this->vendorInfoDetail->HAOLONG_USER . $agent->username;
                    $data['pass'] = $agent->im_certify;
                }
            } else {
                $data['user'] = $this->vendorInfoDetail->HAOLONG_USER;
                $data['pass'] = $this->vendorInfoDetail->HAOLONG_PASSWORD;
            }
        }

        $response = Http::logAction('Haolong_' . $endpoint, $data)
            ->asForm()
            ->throw()
            ->post($this->vendorInfoDetail->API_URL . $endpoint, $data);

        if ($response->failed()) {
            throw new ApiException(__('error_message.9995'));
        }

        return simplexml_load_string($response->body());
    }

    private function mapLanguageCode(?LanguageEnum $language = null): string
    {
        // 只有簡體中文跟英文
        return match ($language) {
            LanguageEnum::ZH_TW->value => 'zh-cn',
            LanguageEnum::ZH_CN->value => 'zh-cn',
            default => 'en-us',
        };
    }

    /**
     * 建立我方遊戲商帳號（遷自 RD-Studio create_haolong_account）
     *
     * 密碼不入庫：getGameUrl 以 getDefaultPassword(username, created_at) 即時重算，
     * 建號送三方的 loginPass 必須同式。
     */
    public function createAccount(CreateAccountParamsData $params): CreateAccountResultData
    {
        $this->setVendorInfo($params->vendorInfo);
        $username = $params->username;

        // 建號時窗（遷自 RD-Studio Kernel 排程的 ->unlessBetween('06:00', '14:00')，UTC）：
        // 該時段三方不受理建號，時窗外先行返回、不落任何帳號列，缺口由時窗內的下輪排程補上
        if (now()->between(now()->setTime(6, 0), now()->setTime(14, 0))) {
            Log::info('Haolong 建號時窗限制，略過本輪補建', ['username' => $username]);

            return CreateAccountResultData::from(['status' => false, 'username' => $username]);
        }

        // 注單以 login_id = username_modified 對帳；前綴條件與本 driver 登入路徑一致（USE_PREFIX == 1 才加）
        $usernameModified = $this->vendorInfoDetail->USE_PREFIX == 1
            ? $this->vendorInfoDetail->HAOLONG_USER . $username
            : $username;

        $account = $this->accountModel::create([
            'vendor_info_id' => $params->vendorInfo->id,
            'username' => $username,
            'username_modified' => $usernameModified,
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
     * 建立三方遊戲商帳號（errorCode 998 = 系統忙碌，等 3 秒重試，最多 3 次）
     */
    private function createVendorAccount(HaolongAccount $account): bool
    {
        $username = $account->username;

        $data = [
            'loginID' => $username, // raw，不帶 HAOLONG_USER 前綴
            'loginPass' => $this->getDefaultPassword($username, $account->created_at->toDateTimeString()),
            'fullName' => $username,
            // 明確帶入代理帳密：避開 post() 內 !isset($data['user']) 分支
            // （該分支會存取 member=null 時尚未初始化的 vendorAccount／member）
            'user' => $this->vendorInfoDetail->HAOLONG_USER,
            'pass' => $this->vendorInfoDetail->HAOLONG_PASSWORD,
        ];

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            try {
                $result = $this->post('createPlayer.aspx', $data);
            } catch (Throwable $e) {
                Log::error('[Haolong][createAccount] API error', [
                    'username' => $username,
                    'error' => $e->getMessage(),
                    'trace' => Common::formatTrace($e),
                ]);

                return false;
            }

            if ($result === false || !isset($result->errorCode)) {
                Log::error('[Haolong][createAccount] Invalid response: no errorCode', ['username' => $username]);

                return false;
            }

            $errorCode = (string) $result->errorCode;

            if ($errorCode === '0') {
                return true;
            }

            if ($errorCode !== '998') {
                Log::error("[Haolong][createAccount] failed, errorCode: {$errorCode}", ['username' => $username]);

                return false;
            }

            Log::warning("[Haolong][createAccount] system busy (998), retry {$attempt}/3", ['username' => $username]);
            sleep(3);
        }

        return false;
    }
}

class VendorInfoDetailData extends Data
{
    public function __construct(
        public string $API_URL,
        public string $API_HTML5_URL,
        public string $API_USER,
        public string $API_PASSWORD,
        public string $HAOLONG_USER,
        public string $HAOLONG_PASSWORD,
        public ?int $USE_PREFIX = 1,
    ) {}
}
