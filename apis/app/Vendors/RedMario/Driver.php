<?php

declare(strict_types=1);

namespace App\Vendors\RedMario;

use App\Data\GameInfoData;
use App\Data\Vendor\CreateAccountParamsData;
use App\Data\Vendor\CreateAccountResultData;
use App\Data\Vendor\GetGameListParamsData;
use App\Data\Vendor\GetGameListResultData;
use App\Data\VendorGameUrlParamsData;
use App\Data\VendorGameUrlResultData;
use App\Data\VendorTransferData;
use App\Enums\Game\GameTypeEnum;
use App\Enums\LanguageEnum;
use App\Enums\Vendor\VendorTransferResultEnum;
use App\Exceptions\ApiException;
use App\Helpers\Common;
use App\Models\Member;
use App\Vendors\AbstractTransferWallet;
use App\Vendors\Contracts\ShouldScheduleAccountCreation;
use App\Vendors\Contracts\ShouldSyncGameList;
use App\Vendors\Models\RmAccount;
use App\Vendors\Models\RmBetRecord;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class Driver extends AbstractTransferWallet implements ShouldScheduleAccountCreation, ShouldSyncGameList
{
    protected string $accountModel = RmAccount::class;

    protected string $betRecordModel = RmBetRecord::class;

    protected string $actionTitle = 'RedMario';

    protected string $apiUrl;

    protected string $apiId;

    protected string $apiKey;

    protected string $minAmount;

    protected string $maxAmount;

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
        $this->apiId = $info['API_ID'];
        $this->apiKey = [REDACTED]'API_KEY'];
    }

    public function getGameUrl(VendorGameUrlParamsData $params): VendorGameUrlResultData
    {
        $action = $this->actionTitle . '_api_getGameUrl';
        $username = $this->vendorAccount->username;
        $language = $this->getLanguage($params->language->value);
        $gameCode = $params->gameCode;
        $exitUrl = $params->exitUrl;

        $url = $this->apiUrl . 'api/acc/gameLogIn';
        $time = date('U');

        $data = [];
        $data['api_id'] = $this->apiId;
        $data['timestamp'] = $time;
        $data['sign'] = $this->sign($time);
        $data['user_id'] = $username;
        $data['game_code'] = $gameCode;
        $data['language'] = $language;
        $data['return_url'] = $exitUrl;

        $response = Http::logAction($action)->asForm()->post($url, $data)->throw();
        $json = $response->json();

        if (!$json) {
            throw new ApiException('Third party error: not json response');
        }

        if (!isset($json['code']) || !is_numeric($json['code'])) {
            throw new ApiException('Third party error: format error');
        }

        if ((string) $json['code'] != '0') {
            $code = $json['code'];
            throw new ApiException('Third party error: error code ' . $code);
        }

        $url = $json['gameUrl'];

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

        $url = $this->apiUrl . 'api/acc/getBalance';
        $time = date('U');

        $data = [];
        $data['api_id'] = $this->apiId;
        $data['timestamp'] = $time;
        $data['sign'] = $this->sign($time);
        $data['user_id'] = $username;
        $response = Http::logAction($action)->asForm()->post($url, $data)->throw();
        $json = $response->json();

        if (!$json) {
            throw new ApiException('Third party error: not json response');
        }
        if (!isset($json['code']) || !is_numeric($json['code'])) {
            throw new ApiException('Third party error: format error');
        }

        if ((string) $json['code'] != '0') {
            $code = $json['code'];
            throw new ApiException('Third party error: error code ' . $code);
        }

        return (string) $json['balance'];
    }

    /**
     * 轉帳
     */
    protected function transfer(string $username, int $type, string $credit, string $billNo): VendorTransferResultEnum
    {
        if ($type == 1) {
            // 存款
            $action = $this->actionTitle . '_Deposit';
        } else {
            // 提款
            $action = $this->actionTitle . '_Withdrawal';
        }

        $url = $this->apiUrl . 'api/acc/updateBalance';
        $time = date('U');

        $data = [];
        $data['api_id'] = $this->apiId;
        $data['timestamp'] = $time;
        $data['sign'] = $this->sign($time);
        $data['user_id'] = $username;
        $data['amount'] = $credit;
        $data['action'] = ($type == 1) ? 'deposit' : 'withdraw';
        $data['reference_id'] = $billNo;

        $response = Http::logAction($action)->asForm()->post($url, $data)->throw();
        $json = $response->json();

        if (!$json) {
            return VendorTransferResultEnum::TIMEOUT;
        }

        if (!isset($json['code']) || !is_numeric($json['code'])) {
            throw new ApiException('Third party error: format error');
        }

        if ((string) $json['code'] != '0') {
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

        $url = $this->apiUrl . 'api/acc/getTransactionLog';
        $time = date('U');

        $data = [];
        $data['api_id'] = $this->apiId;
        $data['timestamp'] = $time;
        $data['sign'] = $this->sign($time);
        $data['user_id'] = $this->vendorAccount->username;
        $data['reference_id'] = $transactionNo;

        $response = Http::logAction($action)->asForm()->post($url, $data)->throw();
        $json = $response->json();

        if (!$json) {
            return VendorTransferResultEnum::TIMEOUT;
        }

        if (!isset($json['code']) || !is_numeric($json['code'])) {
            throw new ApiException('Third party error: format error');
        }

        if ((string) $json['code'] == '0') {
            $list = $json['walletLogDTO'];
            if (count($list) > 0) {
                return VendorTransferResultEnum::SUCCESS;
            }
        }

        return VendorTransferResultEnum::FAILED;
    }

    /**
     * 遊戲清單（圖片以 API_URL 相對路徑組出縮圖）
     */
    public function getGameList(GetGameListParamsData $params): GetGameListResultData
    {
        $this->setVendorInfo($params->vendorInfo);

        $timestamp = date('U');
        $postData = [
            'api_id' => $this->apiId,
            'timestamp' => $timestamp,
            'sign' => $this->sign($timestamp),
        ];

        $json = Http::logAction($this->actionTitle . '_getGameList')
            ->asForm()
            ->post($this->apiUrl . 'getGames', $postData)
            ->throw()
            ->json();

        if ((string) ($json['code'] ?? '') !== '0') {
            throw new ApiException('Third party error: (' . ($json['code'] ?? '') . ') ' . ($json['msg'] ?? ''));
        }

        $typeMap = [
            'SLOT' => GameTypeEnum::SLOTS->value,
            'CASINO' => GameTypeEnum::LIVE_CASINO->value,
            'ARCADE' => GameTypeEnum::ARCADE->value,
            'FISHING' => GameTypeEnum::FISHING->value,
        ];

        $data = [];
        foreach ($json['gameList'] ?? [] as $game) {
            $gameCode = (string) $game['gameCode'];
            $data[$gameCode] = GameInfoData::from([
                'gameCode' => $gameCode,
                'gameName' => $game['gameName'],
                'imageUrl' => $this->apiUrl . 'images/thumb/square/' . $gameCode . '.jpg',
                'gameType' => $typeMap[strtoupper($game['gameType'] ?? '')] ?? GameTypeEnum::EVENT_AND_OTHERS->value,
                'status' => 1,
                'info' => json_encode($game),
            ]);
        }

        return GetGameListResultData::from(['gameList' => $data]);
    }

    private function sign($time)
    {
        return md5(($this->apiId . $time . $this->apiKey));
    }

    public function getLanguage(int $languageId): string
    {
        // English en
        // Chinese zh
        // Indonesian idn
        // Thai th
        // Vietnamese vi
        return match ($languageId) {
            LanguageEnum::EN->value => 'en',
            LanguageEnum::ZH_TW->value => 'zh',
            LanguageEnum::ZH_CN->value => 'zh',
            LanguageEnum::ID_ID->value => 'idn',
            LanguageEnum::TH_TH->value => 'th',
            LanguageEnum::VI_VN->value => 'vi',
            default => 'en',
        };
    }

    /**
     * 建立我方遊戲商帳號（遷自 RD-Studio create_rm_account）
     *
     * RedMario 登入不使用密碼（gameLogIn 只帶簽名），建號不送密碼。
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

        // /api/acc/created 限流：同一 API 連續呼叫需間隔 1 秒
        sleep(1);

        if (!$this->createVendorAccount($username)) {
            return CreateAccountResultData::from(['status' => false, 'username' => $username]);
        }

        $account->update(['member_id' => 0]);

        return CreateAccountResultData::from(['status' => true, 'username' => $username]);
    }

    /**
     * 建立三方遊戲商帳號（/api/acc/created）
     */
    private function createVendorAccount(string $username): bool
    {
        $action = $this->actionTitle . '_createAccount';
        $time = date('U');

        $data = [
            'api_id' => $this->apiId,
            'timestamp' => $time,
            'sign' => $this->sign($time),
            'user_id' => $username,
        ];

        try {
            $json = Http::logAction($action)
                ->asForm()
                ->post($this->apiUrl . 'api/acc/created', $data)
                ->throw()
                ->json();
        } catch (Throwable $e) {
            Log::error('[RedMario][createAccount] request failed', [
                'username' => $username,
                'error' => $e->getMessage(),
                'trace' => Common::formatTrace($e),
            ]);

            return false;
        }

        if (!is_array($json) || (string) ($json['code'] ?? '') !== '0') {
            Log::error('[RedMario][createAccount] failed', ['username' => $username, 'response' => $json]);

            return false;
        }

        return true;
    }
}
