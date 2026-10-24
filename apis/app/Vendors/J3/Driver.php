<?php

declare(strict_types=1);

namespace App\Vendors\J3;

use App\Data\VendorGameUrlParamsData;
use App\Data\VendorGameUrlResultData;
use App\Data\VendorTransferData;
use App\Enums\LanguageEnum;
use App\Enums\Vendor\VendorTransferResultEnum;
use App\Exceptions\ApiException;
use App\Models\Member;
use App\Vendors\AbstractTransferWallet;
use App\Vendors\Models\J3Account;
use App\Vendors\Models\J3BetRecord;
use Illuminate\Support\Facades\Http;

class Driver extends AbstractTransferWallet
{
    protected string $accountModel = J3Account::class;

    protected string $betRecordModel = J3BetRecord::class;

    protected string $actionTitle = 'J3';

    private string $apiUrl;

    private string $gameUrl;

    private string $agentLogin;

    private string $agentPassword;

    public function __construct(int $vendorId, ?Member $member)
    {
        parent::__construct($vendorId, $member);

        if (!$member) {
            return;
        }

        $this->setInfo($this->vendorInfo->info);
    }

    public function setInfo(array $infoDetail): void
    {
        $this->apiUrl = rtrim($infoDetail['API_URL'], '/');
        $this->gameUrl = $infoDetail['GAME_URL'];
        $this->agentLogin = $infoDetail['AGENT_LOGIN'];
        $this->agentPassword = [REDACTED]'AGENT_PASSWORD'];
    }

    /**
     * 取得遊戲網址
     */
    public function getGameUrl(VendorGameUrlParamsData $params): VendorGameUrlResultData
    {
        $action = $this->actionTitle . '_getGameUrl';
        $url = $this->apiUrl . '/gettoken.php';
        $languageCode = $this->mapLanguageCode($params->language);

        $params = [
            'agent' => $this->agentLogin,
            'agentpassword' => $this->agentPassword,
            'login' => $this->vendorAccount->username,
        ];

        $response = Http::logAction($action)->asForm()->post($url, $params)->throw();
        $json = $response->json();

        if (!$json) {
            throw new ApiException('Third party error: not json response');
        }

        if (!isset($json['status']) || $json['status'] !== 'success') {
            $message = $json['message'] ?? '';
            throw new ApiException('Third party error: not success, message: ' . $message);
        }

        if (!isset($json['data'])) {
            throw new ApiException('Third party error: format error');
        }

        $url = $this->gameUrl . '?token=' . $json['data'] . '&lang=' . $languageCode;

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
        $action = $this->actionTitle . '_getbalance';
        $url = $this->apiUrl . '/getbalance.php';

        $params = [
            'agent' => $this->agentLogin,
            'agentpassword' => $this->agentPassword,
            'login' => $this->vendorAccount->username,
        ];

        $response = Http::logAction($action)->asForm()->post($url, $params)->throw();
        $json = $response->json();

        if (!$json) {
            throw new ApiException('Third party error: not json response');
        }

        if (!isset($json['status']) || $json['status'] !== 'success') {
            $message = $json['message'] ?? '';
            throw new ApiException('Third party error: not success, message: ' . $message);
        }

        if (!isset($json['data'])) {
            throw new ApiException('Third party error: format error');
        }

        $balance = $json['balance'] ?? $json['data'];

        return (string) $balance;
    }

    /**
     * 轉帳
     */
    protected function transfer(int $type, VendorTransferData $params): VendorTransferResultEnum
    {
        if ($type == 1) {
            // 存款
            $url = $this->apiUrl . '/topup.php';
            $action = $this->actionTitle . '_Deposit';
            $amount = $params->vendorAmount;
        } else {
            // 提款
            $url = $this->apiUrl . '/withdraw.php';
            $action = $this->actionTitle . '_Withdrawal';
            $amount = $params->vendorAmount;
        }

        $data = [
            'agent' => $this->agentLogin,
            'agentpassword' => $this->agentPassword,
            'login' => $this->vendorAccount->username,
            'amount' => $amount,
        ];

        $response = Http::logAction($action)->asForm()->post($url, $data)->throw();
        $json = $response->json();

        if (!$json) {
            return VendorTransferResultEnum::TIMEOUT;
        }

        if (!isset($json['status']) || $json['status'] !== 'success') {
            $message = $json['message'] ?? '';
            throw new ApiException('Third party error: not success, message: ' . $message);
        }

        if (!isset($json['data'])) {
            throw new ApiException('Third party error: format error');
        }

        return VendorTransferResultEnum::SUCCESS;
    }

    /**
     * 玩家餘額轉入遊戲商 (存款)
     */
    public function transferToVendor(VendorTransferData $params): VendorTransferResultEnum
    {
        return $this->transfer(type: 1, params: $params);
    }

    /**
     * 轉出遊戲商餘額回本地 (提款)
     */
    public function transferToLocal(VendorTransferData $params): VendorTransferResultEnum
    {
        return $this->transfer(type: 2, params: $params);
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
}
