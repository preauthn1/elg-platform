<?php

declare(strict_types=1);

namespace App\Vendors\Evo;

use App\Data\BaseTotalBetRecordData;
use App\Data\GameInfoData;
use App\Data\InsertedBetRecordData;
use App\Data\Vendor\CreateAccountParamsData;
use App\Data\Vendor\CreateAccountResultData;
use App\Data\Vendor\GetGameListParamsData;
use App\Data\Vendor\GetGameListResultData;
use App\Data\VendorGameUrlParamsData;
use App\Data\VendorGameUrlResultData;
use App\Data\VendorResetPasswordData;
use App\Data\VendorTransferData;
use App\Enums\Game\GameTypeEnum;
use App\Enums\General\DeviceTypeEnum;
use App\Enums\LanguageEnum;
use App\Enums\Vendor\VendorChannelEnum;
use App\Enums\Vendor\VendorTransferResultEnum;
use App\Exceptions\ApiException;
use App\Helpers\Common;
use App\Models\Member;
use App\Vendors\AbstractTransferWallet;
use App\Vendors\Contracts\HasAggregatorChannel;
use App\Vendors\Contracts\HasGameList;
use App\Vendors\Contracts\ShouldScheduleAccountCreation;
use App\Vendors\Contracts\SupportsBetRecordFetch;
use App\Vendors\Models\EvoAccount;
use App\Vendors\Models\EvoBetRecord;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Titan(EVO) 聚合遊戲商共用 Driver：旗下子遊戲商共用同一組 TitanHub API，
 * 以 vendor_infos.info 的 PROVIDER 區分實際遊戲商。
 *
 * @property EvoAccount $vendorAccount
 */
// 拉單未掛排程 marker：Titan 注單仍由外部系統排程拉取，本端僅供 --vendor 手動補拉
/**
 * @knowledge rules/game-list-sync
 */
class Driver extends AbstractTransferWallet implements HasAggregatorChannel, HasGameList, ShouldScheduleAccountCreation, SupportsBetRecordFetch
{
    protected string $accountModel = EvoAccount::class;

    protected string $betRecordModel = EvoBetRecord::class;

    protected string $name;

    protected string $apiUrl;

    protected string $agentId;

    protected string $provider;

    protected int $maxLimitBet;

    protected string $betLimitId;

    protected string $rate;

    protected bool $isLobby;

    /** 拉單每頁筆數（BET_PAGE_SIZE，預設 500） */
    protected int $betPageSize = 500;

    public function __construct(int $vendorId, ?Member $member)
    {
        parent::__construct($vendorId, $member);

        if (!$member) {
            return;
        }

        $this->setInfo($this->vendorInfo->info);
    }

    /**
     * 接入通道：經由聚合商 TitanHub
     */
    public static function aggregatorChannel(): VendorChannelEnum
    {
        return VendorChannelEnum::TITAN_HUB;
    }

    public function setInfo(array $info): void
    {
        // $this->name = $this->vendorInfo->name;
        // $this->rate = (string) $this->vendorInfo->rate;
        $this->apiUrl = $info['API_URL'];
        $this->agentId = $info['AGENT_ID'];
        $this->provider = (string) $info['PROVIDER'];
        $this->maxLimitBet = (int) ($info['MAX_LIMIT_BET'] ?? 0);
        $this->betLimitId = (string) ($info['BET_LIMIT_ID'] ?? '');
        $this->betPageSize = (int) ($info['BET_PAGE_SIZE'] ?? 500);

        // 直進大廳的遊戲，舊的用 provider 判斷，新的會在 info 裡寫 isLobby
        $lobbyGameCode = [5, 6, 7, 12, 20, 27, 33, 40, 41, 58];
        $this->isLobby = ($info['isLobby'] ?? 0) || in_array($this->provider, $lobbyGameCode);
    }

    /**
     * 建立我方遊戲商帳號
     */
    public function createAccount(CreateAccountParamsData $params): CreateAccountResultData
    {
        // 取目前帳號最大值
        $latestNum = $params->serialNumber;

        // 注入遊戲商戶資料
        $this->setVendorInfo($params->vendorInfo);
        $prefix = $this->vendorInfo->account_prefix;

        // 帳號規則，前綴加上8位數字
        $username = $prefix . str_pad((string) ($latestNum + 1), 8, '0', STR_PAD_LEFT);

        // evo_accounts／c93_accounts／l4_accounts 的 password 為 NOT NULL 無 default，
        // 必須在 insert 當下帶值，否則整筆補建被 DB 以 1364 擋下。登入密碼於 getGameUrl 時另行重算。
        $account = $this->accountModel::create([
            'vendor_info_id' => $this->vendorInfo->id,
            'username' => $username,
            'password' => substr(md5($username . now()->timestamp), 8, 12),
        ]);

        if (!$account) {
            return CreateAccountResultData::from([
                'status' => false,
                'username' => $username,
            ]);
        }

        // 呼叫三方 API 建立遊戲商帳號
        if (!$this->createVendorAccount($account)) {
            // 如果失敗就刪除這筆記錄
            $account->update(['member_id' => -999999999]);

            return CreateAccountResultData::from([
                'status' => false,
                'username' => $username,
            ]);
        }

        // vendor_id = 57 時需額外設定限紅
        if ($this->vendor->id === 57) {
            if (!$this->updateBetLimit($account)) {
                // 帳號創成功,但更改限紅失敗就先用-1
                $account->update(['member_id' => -1]);

                return CreateAccountResultData::from([
                    'status' => false,
                    'username' => $username,
                ]);
            }
        }

        return CreateAccountResultData::from([
            'status' => true,
            'username' => $username,
        ]);
    }

    public function resetPassword(VendorResetPasswordData $params): VendorTransferResultEnum
    {
        $account = $this->vendorAccount;

        $data = [
            'AgentId' => $this->agentId,
            'LoginId' => $account->username,
            'OldPassword' => $account->password,
            'NewPassword' => $params->newPassword,
            'Reset' => true,
        ];

        $result = $this->sendRequest('/User/EditPassword', $data);

        if ((string) $result['code'] !== '1') {
            return VendorTransferResultEnum::FAILED;
        }

        $account->update([
            'password' => $result['password'],
        ]);

        return VendorTransferResultEnum::SUCCESS;
    }

    /**
     * 建立三方遊戲商帳號
     */
    protected function createVendorAccount($account): bool
    {
        $data = [
            'AgentId' => $this->agentId,
            'LoginId' => $account->username,
            'Password' => $account->password,
        ];

        $result = $this->sendRequest('/User/Register', $data);

        if (!is_array($result)) {
            return false;
        }

        if ((string) $result['code'] === '1') {
            return true;
        }

        return false;
    }

    /**
     * 修改限紅
     */
    protected function updateBetLimit($account): bool
    {
        if (!$this->betLimitId) {
            Log::error(sprintf('vendorInfo %s 限紅配置 BET_LIMIT_ID 未定義', $this->vendorInfo->id));

            return false;
        }

        $data = [
            'AgentId' => $this->agentId,
            'LoginId' => $account->username,
            'MaxBetLimit' => $this->maxLimitBet,
            'BetLimit' => [
                'EVO' => [
                    'limitId' => $this->betLimitId,
                ],
            ],
        ];

        $result = $this->sendRequest('/User/EditMax', $data);

        if (!is_array($result)) {
            return false;
        }

        if ((string) $result['code'] === '1') {
            return true;
        }

        return false;
    }

    /**
     * 玩家餘額轉入遊戲商 (存款)
     */
    public function transferToVendor(VendorTransferData $params): VendorTransferResultEnum
    {
        $data = [
            'AgentId' => $this->agentId,
            'LoginId' => $this->vendorAccount->username,
            'Reference' => $params->transactionNo,
            'Amount' => $params->vendorAmount,
        ];

        return $this->transfer($data);
    }

    /**
     * 轉出遊戲商餘額回本地 (提款)
     */
    public function transferToLocal(VendorTransferData $params): VendorTransferResultEnum
    {
        $data = [
            'AgentId' => $this->agentId,
            'LoginId' => $this->vendorAccount->username,
            'Reference' => $params->transactionNo,
            'Amount' => '-' . $params->vendorAmount,
        ];

        return $this->transfer($data);
    }

    /**
     * 轉帳
     */
    protected function transfer(array $data): VendorTransferResultEnum
    {
        $result = $this->sendRequest('/User/UpdateBalance', $data);

        if (!is_array($result)) {
            return VendorTransferResultEnum::TIMEOUT;
        }

        if (!isset($result['code']) || !is_numeric($result['code'])) {
            // throw new ApiException('Third party error: format error');
            return VendorTransferResultEnum::TIMEOUT;
        }

        // code = 1 表示成功
        if ((string) $result['code'] === '1') {
            return VendorTransferResultEnum::SUCCESS;
        }

        // 其他狀況皆視為失敗
        return VendorTransferResultEnum::TIMEOUT;
    }

    /**
     * 取得遊戲網址
     */
    public function getGameUrl(VendorGameUrlParamsData $params): VendorGameUrlResultData
    {
        $languageId = $params->language->value;
        $openUrl = $this->isLobby ? '/Game/OpenGame' : '/Game/OpenGameByCode';

        $data = [
            'AgentId' => $this->agentId,
            'LoginId' => $this->vendorAccount->username,
            'Password' => $this->vendorAccount->password,
            'GameProviderCode' => $this->provider,
            'Language' => $this->mapLanguageCode($languageId),
            'IsMobile' => $params->deviceType === DeviceTypeEnum::MOBILE,
        ];

        if (!$this->isLobby) {
            $data['GameCode'] = $params->gameCode;
        }

        $result = $this->sendRequest($openUrl, $data);

        if (!is_array($result)) {
            throw new ApiException('Third party error: not json response');
        }

        if (!isset($result['code']) || !is_numeric($result['code'])) {
            throw new ApiException('Third party error: format error');
        }

        if ((string) $result['code'] !== '1') {
            throw new ApiException('Third party error: ' . $result['message']);
        }

        return VendorGameUrlResultData::from([
            'mobile' => $result['url'],
            'pc' => $result['url'],
        ]);
    }

    /**
     * 取得餘額
     */
    public function getBalance(): string
    {
        $data = [
            'AgentId' => $this->agentId,
            'LoginId' => $this->vendorAccount->username,
        ];

        $result = $this->sendRequest('/User/GetBalance', $data);

        if (!is_array($result)) {
            throw new ApiException('Third party error: not json response');
        }

        if (!isset($result['code']) || !is_numeric($result['code'])) {
            throw new ApiException('Third party error: format error');
        }

        if ((string) $result['code'] !== '1') {
            throw new ApiException('Third party error: ' . ($result['message'] ?? 'Unknown error'));
        }

        return (string) ($result['balance'] ?? '0');
    }

    public function betRecordApiWindowMinutes(): int
    {
        // TransactionDetail 以起訖時間查詢並分頁撈完，三方無窗口上限，取 1 小時防單片過大
        return 60;
    }

    public function betRecordMinStartTime(): ?string
    {
        return null;
    }

    public function betRecordApiRateLimit(): array
    {
        // 三方未公告頻率限制；上限防護用，避免補拉大窗口時打爆三方
        return ['max_calls' => 60, 'per_seconds' => 60];
    }

    public function betRecordCredentialKey(array $info): string
    {
        // 聚合遊戲商多個 vendor_info（各代理組）常共用同一組 AGENT_ID + PROVIDER，
        // TransactionDetail 以此二值查詢，同組合回同一批注單
        return ($info['AGENT_ID'] ?? '') . '|' . ($info['PROVIDER'] ?? '');
    }

    /**
     * 拉取三方注單（分頁撈完為止）
     *
     * @return array<int, array<string, mixed>>
     */
    public function fetchBetRecords(string $fromDate, string $toDate): array
    {
        // 三方時間參數為 UTC+8
        $fromDate = Carbon::parse($fromDate, 'UTC')->setTimezone('+08:00')->format('Y-m-d\TH:i:sP');
        $toDate = Carbon::parse($toDate, 'UTC')->setTimezone('+08:00')->format('Y-m-d\TH:i:sP');
        $pageSize = $this->betPageSize;

        $records = [];
        $pageIndex = 1;

        do {
            $result = $this->sendRequest('/Report/TransactionDetail', [
                'AgentId' => $this->agentId,
                'GameProviderCode' => $this->provider,
                'FromDate' => $fromDate,
                'ToDate' => $toDate,
                'PageIndex' => $pageIndex,
                'RowPerPage' => $pageSize,
            ], 'fetchBetRecords');

            if (!is_array($result) || (string) ($result['code'] ?? '') !== '1') {
                throw new ApiException('Third party error: ' . ($result['message'] ?? 'unexpected response'));
            }

            array_push($records, ...($result['data'] ?? []));
        } while ($pageIndex++ < (int) ($result['totalPage'] ?? 1));

        return $records;
    }

    /**
     * 三方回應即平台點數，不做 rate 換算（$rate 依契約收下但不使用）
     *
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>
     */
    public function formatBetRecord(array $raw, string $rate): array
    {
        $betTime = Carbon::parse($raw['endDate'])->setTimezone('UTC')->format('Y-m-d H:i:s');

        return [
            'bet_id' => (string) $raw['id'],
            'username' => (string) $raw['loginId'],
            // 單一入口的遊戲三方不回 gameCode，直接用 vendor_info 的 PROVIDER
            'game_code' => $this->isLobby ? $this->provider : (string) $raw['gameCode'],
            // 投注額取有效投注 turnover，因為返水要用有效投注計算
            // （target/tickets#3183；規則自 +8 時區 2025-11-20 起生效，不回補更早的資料）
            'bet' => Common::numAdd('0', (string) ($raw['turnover'] ?? 0), 4),
            'win_lose' => Common::numMul('-1', Common::numAdd('0', (string) ($raw['winlose'] ?? 0), 4), 4),
            'bet_time' => $betTime,
            'info' => json_encode($raw, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ];
    }

    /**
     * type = 0 的活動單（免費旋轉派彩等，bet / turnover 皆 0、只有玩家淨贏）
     * 刻意不排除，照常規 mapping 進 TBR 才與其他注單同一視角
     */
    public function formatBaseTotalBetRecord(InsertedBetRecordData $record): ?BaseTotalBetRecordData
    {
        $betRecord = $record->attributes;

        return new BaseTotalBetRecordData(
            accountKey: (string) $betRecord['username'],
            gameCode: (string) $betRecord['game_code'],
            referenceNo: (string) $betRecord['bet_id'],
            bet: (string) $betRecord['bet'],
            validBet: (string) $betRecord['bet'],
            winLose: (string) $betRecord['win_lose'],
            betTime: (string) $betRecord['bet_time'],
            // 三方只給一個時間，以投注時間充當結算時間
            payoutTime: (string) $betRecord['bet_time'],
        );
    }

    public function getBetRecordModelClass(): string
    {
        return $this->betRecordModel;
    }

    public function getAccountModelClass(): string
    {
        return $this->accountModel;
    }

    /**
     * TitanHub 遊戲名稱語系代碼 => 平台語系 ID
     */
    private const array GAME_NAME_LANGUAGE_MAP = [
        'EN' => LanguageEnum::EN,
        'ZH' => LanguageEnum::ZH_TW,
        'CN' => LanguageEnum::ZH_CN,
        'TH' => LanguageEnum::TH_TH,
    ];

    /**
     * 取得遊戲清單
     *
     * Titan 聚合旗下子遊戲商共用同一支 /Game/GetList，
     * 以商戶 info 的 PROVIDER（GameProviderCode）取得對應遊戲商的清單。
     */
    public function getGameList(GetGameListParamsData $params): GetGameListResultData
    {
        $this->setVendorInfo($params->vendorInfo);

        $result = $this->sendRequest('/Game/GetList', [
            'AgentId' => $this->agentId,
            'GameProviderCode' => $this->provider,
        ]);

        if (!is_array($result) || !isset($result['code']) || (string) $result['code'] !== '1') {
            Log::error($this->getName() . ' getGameList error: ' . ($result['message'] ?? 'Unknown error'), [
                'vendor_id' => $this->vendor->id,
                'vendor_info_id' => $params->vendorInfo->id,
                'provider' => $this->provider,
            ]);

            return GetGameListResultData::from(['gameList' => []]);
        }

        return $this->formatGameList($result['data'] ?? []);
    }

    private function formatGameList(array $gameList): GetGameListResultData
    {
        $data = [];

        foreach ($gameList as $game) {
            $gameCode = (string) $game['code'];
            $gameNames = is_array($game['name'] ?? null) ? $game['name'] : [];

            $gameInfo = GameInfoData::from([
                'gameCode' => $gameCode,
                'gameName' => (string) ($gameNames['EN'] ?? $gameCode),
                'imageUrl' => (string) ($game['gameIcon'] ?? ''),
                'gameType' => $this->convertGameType((string) ($game['type'] ?? '')),
                'status' => 1,
                'info' => json_encode($game),
            ]);

            foreach ($gameNames as $langCode => $name) {
                $language = self::GAME_NAME_LANGUAGE_MAP[$langCode] ?? null;
                if ($language !== null && trim((string) $name) !== '') {
                    $gameInfo->setGameNames($language->value, trim((string) $name));
                }
            }

            $data[$gameCode] = $gameInfo;
        }

        return GetGameListResultData::from(['gameList' => $data]);
    }

    /**
     * TitanHub 遊戲類型映射：
     * - 3 CASINO -> LIVE_CASINO、2 SLOT -> SLOTS、7 FISHING -> FISHING
     * - 5 KENO / 6 LOTTERY -> LOTTERY、4 SPORTBOOK -> SPORTS、8 ESPORT -> E_SPORTS
     * - 1 OTHER -> ARCADE，未列出的類型回傳 0（未分類）
     */
    private function convertGameType(string $type): int
    {
        return match ($type) {
            '3' => GameTypeEnum::LIVE_CASINO->value,
            '2' => GameTypeEnum::SLOTS->value,
            '7' => GameTypeEnum::FISHING->value,
            '5', '6' => GameTypeEnum::LOTTERY->value,
            '4' => GameTypeEnum::SPORTS->value,
            '8' => GameTypeEnum::E_SPORTS->value,
            '1' => GameTypeEnum::ARCADE->value,
            default => 0,
        };
    }

    private function mapLanguageCode(int $languageId): string
    {
        return match ($languageId) {
            LanguageEnum::ZH_CN->value => '1',
            LanguageEnum::ZH_TW->value => '2',
            LanguageEnum::TH_TH->value => '3',
            default => '0',
        };
    }

    private function sendRequest(string $endpoint, array $data, string $method = '')
    {
        if (!$method) {
            $backtrace = debug_backtrace();
            $method = $backtrace[1]['function'];
        }

        // 拉單路徑（fetchBetRecords）只注入 info 陣列、無 vendorInfo，改以 vendor 名稱標記
        $action = (isset($this->vendorInfo) ? $this->getName() : $this->vendor->name) . ' ' . $method;

        $apiUrl = rtrim($this->apiUrl, '/');

        $url = $apiUrl . $endpoint;

        $result = Http::logAction($action)
            ->post($url, $data)
            ->throw()
            ->json();

        return $result;
    }
}
