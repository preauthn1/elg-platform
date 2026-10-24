<?php

declare(strict_types=1);

namespace App\Vendors\NextSpin;

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
use App\Vendors\Models\NsAccount;
use App\Vendors\Models\NsBetRecord;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * @property NsAccount $vendorAccount
 */
class Driver extends AbstractTransferWallet implements ShouldScheduleAccountCreation, ShouldSyncGameList
{
    /**
     * 舊系統未分類遊戲寫 type_id=0（共用 DB 既有慣例，維持一致）
     */
    private const int LEGACY_UNCATEGORIZED_TYPE_ID = 0;

    /**
     * NextSpin/SpadeGaming 遊戲類型（api.md §遊戲類型 附錄）→ 平台遊戲類型
     *
     * 本 driver 為 NextSpin(48)／SpadeGaming(47，繼承未覆寫) 共用實作
     * （target/op_tickets#2866：原本完全未讀三方 category 欄位，一律寫死 0）。
     * BN(Bingo) 平台無對應分類，依歷史人工分類慣例併入 Arcade。
     */
    private const array CATEGORY_MAP = [
        'SM' => GameTypeEnum::SLOTS->value,    // Slot Machine
        'TB' => GameTypeEnum::TABLE->value,    // Table
        'AD' => GameTypeEnum::ARCADE->value,   // Arcade
        'BN' => GameTypeEnum::ARCADE->value,   // Bingo
        'FH' => GameTypeEnum::FISHING->value,  // Fishing
    ];

    protected string $actionTitle = 'NextSpin';

    protected string $accountModel = NsAccount::class;

    protected string $betRecordModel = NsBetRecord::class;

    protected string $apiUrl;

    protected string $gameUrl;

    protected string $merchantCode;

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
        $this->apiUrl = $info['API_URL'];
        $this->gameUrl = $info['GAME_URL'];
        $this->merchantCode = $info['Merchant_Code'];
        $this->currency = $info['CURRENCY'];
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
        );
    }

    /**
     * 取得遊戲網址
     */
    /**
     * 遊戲清單（動作由 header API 指定、直接 POST API_URL；SpadeGaming(47) 繼承本實作。
     * 圖片前綴 IMAGE_URL 與簡中名 cnName 依各家商戶/回應有無而定）
     */
    public function getGameList(GetGameListParamsData $params): GetGameListResultData
    {
        $this->setVendorInfo($params->vendorInfo);

        $postData = [
            'merchantCode' => $this->merchantCode,
            'serialNo' => time() . rand(10, 99),
        ];

        $json = Http::logAction($this->actionTitle . '_getGames')
            ->asJson()
            ->withHeaders([
                'API' => 'getGames',
                'DataType' => 'JSON',
                'Content-Type' => 'application/json',
            ])
            ->post($this->apiUrl, $postData)
            ->throw()
            ->json();

        if (!is_array($json) || ($json['msg'] ?? '') !== 'Success') {
            throw new ApiException('Third party error: ' . ($json['msg'] ?? 'no msg'));
        }

        $imageUrlPrefix = $this->vendorInfo->info['IMAGE_URL'] ?? '';

        $gameList = [];
        $unknownCategories = [];
        foreach ($json['games'] ?? [] as $game) {
            $gameCode = (string) $game['gameCode'];
            $category = (string) ($game['category'] ?? '');
            $gameType = self::CATEGORY_MAP[$category] ?? self::LEGACY_UNCATEGORIZED_TYPE_ID;

            if ($gameType === self::LEGACY_UNCATEGORIZED_TYPE_ID && $category !== '') {
                $unknownCategories[$category] = $gameCode;
            }

            $gameInfo = GameInfoData::from([
                'gameCode' => $gameCode,
                'gameName' => $game['gameName'],
                'imageUrl' => ($imageUrlPrefix !== '' && !empty($game['thumbnail'])) ? $imageUrlPrefix . $game['thumbnail'] : '',
                'gameType' => $gameType,
                'status' => 1,
                'info' => json_encode($game),
            ]);

            if (!empty($game['cnName'])) {
                $gameInfo->setGameNames(LanguageEnum::EN->value, $game['gameName']);
                $gameInfo->setGameNames(LanguageEnum::ZH_CN->value, $game['cnName']);
            }

            $gameList[$gameCode] = $gameInfo;
        }

        if (!empty($unknownCategories)) {
            Log::warning($this->actionTitle . ' 遊戲清單出現對應表外的遊戲類型', [
                'vendor_info_id' => $params->vendorInfo->id,
                'unknown_categories' => $unknownCategories,
            ]);
        }

        return GetGameListResultData::from(['gameList' => $gameList]);
    }

    public function getGameUrl(VendorGameUrlParamsData $params): VendorGameUrlResultData
    {
        $username = $this->vendorAccount->username;
        $language = $this->getLanguage($params->language->value);
        $token = [REDACTED];
        $game_type = $params->gameCode;

        $url = $this->gameUrl . $this->merchantCode .
            "/auth/?acctId=$username&language=$language&token=[REDACTED]";

        return VendorGameUrlResultData::from([
            'mobile' => $url,
            'pc' => $url,
        ]);

    }

    /**
     * 取得餘額
     */
    public function getBalance(): string
    {
        $url = $this->apiUrl;
        $action = 'NextSpin_Balance';

        $data = [
            'acctId' => $this->vendorAccount->username,
            'pageIndex' => 1,
            'merchantCode' => $this->merchantCode,
            'serialNo' => Carbon::now()->getTimestampMs() . rand(10, 99),
        ];

        $headers = [
            'API' => 'getAcctInfo',
            'DataType' => 'JSON',
            'Content-Type' => 'application/json',
        ];

        $response = Http::logAction($action)->withHeaders($headers)->post($url, $data)->throw();
        $json = $response->json();

        if (!$json) {
            throw new ApiException('Third party error: not json response');
        }

        if (!isset($json['code']) || !is_numeric($json['code'])) {
            throw new ApiException('Third party error: format error');
        }

        if ((string) $json['code'] != '0') {
            throw new ApiException('Third party error: ' . ($json['msg'] ?? 'Unknown error'));
        }

        return (string) ($json['list'][0]['balance'] ?? '0');
    }

    /**
     * 轉帳
     */
    protected function transfer(string $username, int $type, string $credit): VendorTransferResultEnum
    {
        $url = $this->apiUrl;
        $headers = [];

        if ($type == 1) {
            $headers['API'] = 'deposit';
            $action = 'NextSpin_Deposit';
        } else {
            $headers['API'] = 'withdraw';
            $action = 'NextSpin_Withdrawal';
        }

        $headers['DataType'] = 'JSON';
        $headers['Content-Type'] = 'application/json';

        $postData = [];
        $postData['acctId'] = $username;
        $postData['currency'] = $this->currency;
        $postData['amount'] = $credit;
        $postData['serialNo'] = Carbon::now()->getTimestampMs() . rand(10, 99);
        $postData['merchantCode'] = $this->merchantCode;

        $response = Http::logAction($action)->withHeaders($headers)->post($url, $postData)->throw();
        $json = $response->json();

        if (!$json) {
            return VendorTransferResultEnum::TIMEOUT;
        }

        if (!isset($json['code']) || !is_numeric($json['code'])) {
            throw new ApiException('Third party error: format error');
        }

        if ((string) $json['code'] != '0') {
            throw new ApiException('Third party error: ' . $json['msg']);
        }

        return VendorTransferResultEnum::SUCCESS;
    }

    protected function getLanguage(int $languageId): string
    {
        return match ($languageId) {
            LanguageEnum::EN->value => 'en_US',
            LanguageEnum::ZH_CN->value => 'zh_CN',
            LanguageEnum::ZH_TW->value => 'zh_TW',
            LanguageEnum::TH_TH->value => 'th_TH',
            LanguageEnum::ID_ID->value => 'id_ID',
            LanguageEnum::VI_VN->value => 'vi_VN',
            LanguageEnum::MY_MM->value => 'my_MM',
            LanguageEnum::HI_IN->value => 'hi_IN',
            default => 'en_US',
        };
    }

    /**
     * 建立我方遊戲商帳號（遷自 RD-Studio create_ns_account／create_spadegaming_account）
     *
     * 三方沒有註冊 API：以「存入 1 → 提出 1」讓三方隱式開戶（SpadeGaming 子類共用本實作）。
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
     * 以 deposit(1) + withdraw(1) 觸發三方開戶；transfer() 失敗會拋例外，這裡吞掉轉 false
     */
    private function createVendorAccount(string $username): bool
    {
        try {
            if ($this->transfer($username, 1, '1') !== VendorTransferResultEnum::SUCCESS) {
                Log::error("[{$this->actionTitle}][createAccount] deposit failed", ['username' => $username]);

                return false;
            }

            // 三方限流：1 秒只能呼叫一次
            sleep(1);

            if ($this->transfer($username, 2, '1') !== VendorTransferResultEnum::SUCCESS) {
                // 存入的 1 元會滯留在三方，需人工回收
                Log::error("[{$this->actionTitle}][createAccount] withdraw failed, 1 credit stranded at vendor", ['username' => $username]);

                return false;
            }
        } catch (Throwable $e) {
            Log::error("[{$this->actionTitle}][createAccount] failed", [
                'username' => $username,
                'error' => $e->getMessage(),
                'trace' => Common::formatTrace($e),
            ]);

            return false;
        }

        return true;
    }
}
