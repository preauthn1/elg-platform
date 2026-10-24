<?php

declare(strict_types=1);

namespace App\Vendors\Bola2;

use App\Data\Vendor\CreateAccountParamsData;
use App\Data\Vendor\CreateAccountResultData;
use App\Data\VendorGameUrlParamsData;
use App\Data\VendorGameUrlResultData;
use App\Data\VendorTransferData;
use App\Enums\General\ContextKeyEnum;
use App\Enums\LanguageEnum;
use App\Enums\Vendor\VendorTransferResultEnum;
use App\Exceptions\ApiException;
use App\Helpers\Common;
use App\Models\Member;
use App\Vendors\AbstractTransferWallet;
use App\Vendors\Contracts\ShouldScheduleAccountCreation;
use App\Vendors\Models\Bola2Account;
use App\Vendors\Models\Bola2BetRecord;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Spatie\LaravelData\Data;
use Throwable;

class Driver extends AbstractTransferWallet implements ShouldScheduleAccountCreation
{
    protected string $accountModel = Bola2Account::class;

    protected string $betRecordModel = Bola2BetRecord::class;

    private VendorInfoDetailData $infoDetail;

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
        $this->infoDetail = VendorInfoDetailData::from($info);
    }

    /**
     * 建立我方遊戲帳號：先落 DB 佔用流水號（member_id = -999999999 表未成功），
     * 三方建號成功才留在 member_id = 0 進入可綁定的帳號池（舊系統 RD-Studio
     * `create_bola2_account` 同行為）。`password` 欄位非空，沿用 getDefaultPassword()
     * 同一套推導（username + created_at），跟 getGameUrl() 登入時重算的邏輯一致。
     */
    public function createAccount(CreateAccountParamsData $params): CreateAccountResultData
    {
        $this->setVendorInfo($params->vendorInfo);
        $username = $params->username;
        $password = [REDACTED], now()->toDateTimeString());

        $account = $this->accountModel::create([
            'vendor_info_id' => $this->vendorInfo->id,
            'username' => $username,
            'password' => $password,
        ]);

        if (!$this->createVendorAccount($username, $password)) {
            $account->update(['member_id' => -999999999]);

            return CreateAccountResultData::from([
                'status' => false,
                'username' => $username,
            ]);
        }

        return CreateAccountResultData::from([
            'status' => true,
            'username' => $username,
        ]);
    }

    /**
     * 呼叫三方建立遊戲帳號
     */
    private function createVendorAccount(string $username, string $password): bool
    {
        $action = 'CreateAccount';

        try {
            $response = $this->apiCall(
                action: $action,
                endPoint: 'user/signup',
                postData: [
                    'Username' => $username,
                    'Password' => $password,
                    'DisplayName' => '',
                    'ContactNo' => '',
                    'Email' => '',
                    'Language' => '',
                ],
            );
        } catch (Throwable $e) {
            Log::error("[Bola2][{$action}] Request failed", [
                'username' => $username,
                'error' => $e->getMessage(),
            ]);

            return false;
        }

        if (!is_array($response) || !isset($response['Status']) || (int) $response['Status'] !== 201) {
            Log::error("[Bola2][{$action}] Invalid response: status is not 201", [
                'username' => $username,
                'response' => $response,
            ]);

            return false;
        }

        return true;
    }

    public function getGameUrl(VendorGameUrlParamsData $params): VendorGameUrlResultData
    {
        $action = 'GetGameUrl';
        $response = $this->apiCall(
            action: $action,
            endPoint: 'user/login',
            postData: [
                'Username' => $this->vendorAccount->username,
                'Password' => $this->getDefaultPassword($this->vendorAccount->username, $this->vendorAccount->created_at->toDateTimeString()),
            ]);

        if (!is_array($response) || !isset($response['Status'])) {
            throw new ApiException("[Bola2][{$action}] API error: response data is not correct.");
        }

        if ((int) $response['Status'] !== 200) {
            throw new ApiException("[Bola2][{$action}] API error: login failed, status: {$response['Status']}");
        }

        if (!isset($response['Message'])) {
            throw new ApiException("[Bola2][{$action}] API error: message is empty.");
        }

        $gameUrl = $response['Message'] . '&lang=' . $this->mapLanguageCode();

        return VendorGameUrlResultData::from([
            'pc' => $gameUrl,
            'mobile' => $gameUrl,
        ]);
    }

    public function getBalance(): string
    {
        $action = 'GetBalance';
        $response = $this->apiCall(
            action: $action,
            endPoint: "user/getBalance/{$this->vendorAccount->username}",
        );

        if (!is_array($response) || !isset($response['Status'])) {
            throw new ApiException("[Bola2][{$action}] API error: response data is not correct.");
        }

        if ((int) $response['Status'] !== 200) {
            throw new ApiException("[Bola2][{$action}] API error: get balance failed, status: {$response['Status']}");
        }

        if (!isset($response['Message'])) {
            throw new ApiException("[Bola2][{$action}] API error: message is empty.");
        }

        return Common::numFloor($response['Message'], 4);
    }

    /**
     * 玩家餘額轉入遊戲商 (存款), origin: type = 1
     */
    public function transferToVendor(VendorTransferData $params): VendorTransferResultEnum
    {
        return $this->transfer($params, "/transfer/deposit/{$this->vendorAccount->username}");
    }

    /**
     * 轉出遊戲商餘額回本地 (提款), origin: type != 1
     */
    public function transferToLocal(VendorTransferData $params): VendorTransferResultEnum
    {
        return $this->transfer($params, "/transfer/withdraw/{$this->vendorAccount->username}");
    }

    private function transfer(VendorTransferData $params, string $endPoint): VendorTransferResultEnum
    {
        $response = $this->apiCall(
            action: $endPoint,
            endPoint: $endPoint,
            postData: [
                'Amount' => $params->vendorAmount,
                'Remark' => '',
            ]
        );

        if (!is_array($response)) {
            Log::error("[Bola2][{$endPoint}] API error: response data is not array.", ['response' => $response]);

            return VendorTransferResultEnum::TIMEOUT;
        }

        if (!isset($response['Status'])) {
            Log::error("[Bola2][{$endPoint}] API error: response data is not correct, status is not set.", ['response' => $response]);

            return VendorTransferResultEnum::TIMEOUT;
        }

        if ((int) $response['Status'] === 201) {
            return VendorTransferResultEnum::SUCCESS;
        }

        Log::error("[Bola2][{$endPoint}] API error: get transfer failed", ['response' => $response]);

        return VendorTransferResultEnum::FAILED;
    }

    private function apiCall(string $action, string $endPoint, array $postData = []): mixed
    {
        $url = "{$this->infoDetail->API_URL}/{$endPoint}";

        $random = sprintf('%06d', random_int(0, 999999));
        $postData = array_merge([
            'AgentName' => $this->infoDetail->AGENT_NAME,
            'Random' => $random,
            'HashKey' => md5($this->infoDetail->AGENT_NAME . $random . $this->infoDetail->API_KEY),
        ], $postData);

        $response = Http::logAction("Bola2_{$action}", $postData)
            ->asJson()
            ->post($url, $postData)
            ->throw()
            ->json();

        return $response;
    }

    private function mapLanguageCode(?LanguageEnum $language = null): string
    {
        if (empty($language)) {
            $languageId = Context::getHidden(ContextKeyEnum::LANGUAGE_ID->value);
        } else {
            $languageId = $language->value;
        }

        return match ($languageId) {
            LanguageEnum::ZH_TW->value => 'C',
            LanguageEnum::ZH_CN->value => 'C',
            LanguageEnum::MS_MY->value => 'M',
            default => 'E',
        };
    }
}

class VendorInfoDetailData extends Data
{
    public function __construct(
        public string $API_URL,
        public string $AGENT_NAME,
        public string $API_KEY,
    ) {
        $this->API_URL = trim($API_URL, '/');
    }
}
