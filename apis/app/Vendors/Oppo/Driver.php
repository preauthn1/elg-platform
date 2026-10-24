<?php

declare(strict_types=1);

namespace App\Vendors\Oppo;

use App\Data\BaseTotalBetRecordData;
use App\Data\InsertedBetRecordData;
use App\Data\Vendor\CreateAccountParamsData;
use App\Data\Vendor\CreateAccountResultData;
use App\Data\VendorGameUrlParamsData;
use App\Data\VendorGameUrlResultData;
use App\Data\VendorTransferData;
use App\Enums\Vendor\VendorTransferResultEnum;
use App\Exceptions\ApiException;
use App\Helpers\Common;
use App\Models\Member;
use App\Vendors\AbstractTransferWallet;
use App\Vendors\Contracts\ShouldScheduleAccountCreation;
use App\Vendors\Contracts\SupportsBetRecordFetch;
use App\Vendors\Models\OppoAccount;
use App\Vendors\Models\OppoBetRecord;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * @knowledge rules/bet-record-pull
 */
class Driver extends AbstractTransferWallet implements ShouldScheduleAccountCreation, SupportsBetRecordFetch
{
    /** 三方查詢時間參數皆為 GMT+8 */
    private const string API_TIMEZONE = 'Asia/Taipei';

    /** 三方分頁上限 */
    private const int PAGE_SIZE = 1000;

    /** 翻頁保護：三方 last_page 異常時避免無限迴圈 */
    private const int MAX_PAGES = 100;

    /** 舊系統排程即以 1 小時為一片 */
    private const int FETCH_WINDOW_MINUTES = 60;

    /** 三方注單不分遊戲，全部掛在同一個 game_type */
    private const string GAME_CODE = 'lobby';

    protected string $accountModel = OppoAccount::class;

    protected string $betRecordModel = OppoBetRecord::class;

    protected string $actionTitle = 'OPPO';

    protected string $api_url;

    protected string $agent_name;

    protected string $api_key;

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
        $this->api_url = $info['API_URL'];
        $this->api_key = [REDACTED]'API_KEY'];
        $this->agent_name = $info['AGENT_NAME'];
    }

    public function getGameUrl(VendorGameUrlParamsData $params): VendorGameUrlResultData
    {
        $action = $this->actionTitle . '_api_getGameUrl';
        $username = $this->vendorAccount->player_id;

        $url = $this->api_url . '/api/accountlogin';

        $data = [
            'Agent' => $this->agent_name,
            'Playerid' => $username,
        ];

        $data['Hash'] = $this->sign($data);

        $response = Http::logAction($action)->post($url, $data)->throw();
        $json = $response->json();

        if (!$json) {
            throw new ApiException('Third party error: not json response');
        }

        if (!isset($json['data']['launch_url'])) {
            throw new ApiException('Third party error: launch_url not found');
        }

        $url = $json['data']['launch_url'];

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
        $username = $this->vendorAccount->player_id;

        $url = $this->api_url . '/api/playerinfo';

        $data = [
            'Agent' => $this->agent_name,
            'Playerid' => $username,
        ];

        $data['Hash'] = $this->sign($data);

        $response = Http::logAction($action)->post($url, $data)->throw();
        $json = $response->json();

        if (!$json) {
            throw new ApiException('Third party error: not json response');
        }

        if ($json['error'] !== null) {
            throw new ApiException('Third party error: ' . json_encode($json['error'], JSON_UNESCAPED_UNICODE));
        }

        if (!isset($json['data']['Playerbalance'])) {
            throw new ApiException('Third party error: playerbalance not found');
        }

        $balance = $json['data']['Playerbalance'];

        return (string) $balance;
    }

    /**
     * 轉帳
     */
    protected function transfer(string $username, int $type, string $credit, string $billNo): VendorTransferResultEnum
    {
        if ($type == 1) {
            // 存款
            $url = $this->api_url . '/api/transferin';
            $action = $this->actionTitle . '_Deposit';
        } else {
            // 提款
            $url = $this->api_url . '/api/transferout';
            $action = $this->actionTitle . '_Withdrawal';
        }

        $data = [
            'Agent' => $this->agent_name,
            'Playerid' => $username,
            'Amount' => Common::numFloor($credit, 2),
        ];

        $data['Hash'] = $this->sign($data);

        $response = Http::logAction($action)->post($url, $data)->throw();
        $json = $response->json();

        if (!$json) {
            return VendorTransferResultEnum::TIMEOUT;
        }

        if (isset($json['error']) && $json['error'] !== null) {
            throw new ApiException('Third party error: ' . json_encode($json['error'], JSON_UNESCAPED_UNICODE));
        }

        if (!isset($json['data']['status'])) {
            return VendorTransferResultEnum::FAILED;
        }

        $status = (string) $json['data']['status'];
        if ($status == '1') {
            return VendorTransferResultEnum::SUCCESS;
        }

        return VendorTransferResultEnum::FAILED;
    }

    /**
     * 玩家餘額轉入遊戲商 (存款)
     */
    public function transferToVendor(VendorTransferData $params): VendorTransferResultEnum
    {
        return $this->transfer(
            username: $this->vendorAccount->player_id,
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
            username: $this->vendorAccount->player_id,
            type: 2,
            credit: $params->vendorAmount,
            billNo: $params->transactionNo,
        );
    }

    // -------------------------------------------------------------------------
    // SupportsBetRecordFetch
    //
    // 遷自舊系統 RD-Studio `php artisan oppo_bet_record`（GetOppoBetRecord）。
    // 三方沒有「一次撈整個代理」的注單 API：先用 getGameSummary 取該時段有投注的玩家，
    // 再逐一 getGameLog 取注單，兩支都要自己翻頁。
    // -------------------------------------------------------------------------

    /**
     * 三方以 Agent 區分商戶（注單範圍由 Agent + Hash 決定）：同 Agent 的 vendor_info 拉到同一批
     *
     * @param  array<string, mixed>  $info
     */
    public function betRecordCredentialKey(array $info): string
    {
        return (string) ($info['AGENT_NAME'] ?? '');
    }

    /**
     * 三方文件未載明單次查詢窗上限，沿用舊系統排程的 1 小時
     */
    public function betRecordApiWindowMinutes(): int
    {
        return self::FETCH_WINDOW_MINUTES;
    }

    /**
     * 三方文件未載明注單保留期，不設界線
     */
    public function betRecordMinStartTime(): ?string
    {
        return null;
    }

    /**
     * 三方文件未給呼叫上限，先給保守預算。
     *
     * 注意此預算計的是「時間片」而非實際 HTTP 次數：單片內部還會依投注玩家數
     * 展開 1 + N 次呼叫。真的被三方限流要縮 FETCH_WINDOW_MINUTES，不是調大這裡。
     *
     * @return array{max_calls: int, per_seconds: int}
     */
    public function betRecordApiRateLimit(): array
    {
        return ['max_calls' => 30, 'per_seconds' => 60];
    }

    /**
     * 拉取單一時間片（切片由 BetRecordFetchService 負責）
     *
     * @return array<int, array<string, mixed>>
     *
     * @throws ApiException
     */
    public function fetchBetRecords(string $fromDate, string $toDate): array
    {
        $start = Carbon::parse($fromDate, 'UTC')->timezone(self::API_TIMEZONE)->toDateTimeString();
        $end = Carbon::parse($toDate, 'UTC')->timezone(self::API_TIMEZONE)->toDateTimeString();

        $records = [];

        foreach ($this->getBetPlayerIds($start, $end) as $playerId) {
            $records = [...$records, ...$this->getPlayerBetRecords($start, $end, $playerId)];
        }

        return $records;
    }

    /**
     * 轉為 oppo_bet_records 欄位
     *
     * @param  array<string, mixed>  $raw
     * @param  string  $rate  商戶匯率：三方金額是放大後的值，取回要除掉
     * @return array<string, mixed>
     *
     * @throws ApiException
     */
    public function formatBetRecord(array $raw, string $rate): array
    {
        $rate = Common::numCompare($rate, '0', 4) > 0 ? $rate : '1';
        $bet = Common::numDiv(Common::numAdd('0', (string) ($raw['betoppo_amount'] ?? 0), 4), $rate, 4);
        $validBet = Common::numDiv(Common::numAdd('0', (string) ($raw['betoppo_validbet_amount'] ?? 0), 4), $rate, 4);
        // 三方給的是派彩，輸贏要自己減
        $win = Common::numDiv(Common::numAdd('0', (string) ($raw['betoppo_settle_amount'] ?? 0), 4), $rate, 4);

        $betTime = self::toDbTime($raw['betoppo_betdatetime'] ?? null);

        if ($betTime === null) {
            throw new ApiException('Third party error: betoppo_betdatetime is missing');
        }

        return [
            'bet_id' => (string) ($raw['betoppo_bet_id'] ?? ''),
            'username' => (string) ($raw['betoppo_username'] ?? ''),
            'player_id' => (string) ($raw['user_apiplayerkey'] ?? ''),
            'game_code' => self::GAME_CODE,
            'bet' => $bet,
            // 三方後台查不到有效投注，沿用舊系統：直接收三方給的 validbet
            'valid_bet' => $validBet,
            'win' => $win,
            'win_lose' => Common::numSub($win, $bet, 4),
            'jackpot' => ($raw['betoppo_winjackpot'] ?? 0) ? $win : '0.0000',
            'bet_time' => $betTime,
            'payout_time' => self::toDbTime($raw['betoppo_settledatetime'] ?? null),
            'status' => ($raw['betoppo_status'] ?? '') === 'Settled' ? 1 : 0,
            'info' => json_encode($raw, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ];
    }

    /**
     * 注單的玩家識別是三方 player_id（帳號表的 username 是我方帳號，兩者不同值）
     */
    public function betRecordAccountColumn(): string
    {
        return 'player_id';
    }

    /**
     * 只有已結算單轉入 TBR；未結算單先留在 oppo_bet_records，
     * 結算後由 --update 模式（金額有異動）補寫並重轉 TBR
     */
    public function formatBaseTotalBetRecord(InsertedBetRecordData $record): ?BaseTotalBetRecordData
    {
        $betRecord = $record->attributes;

        if ((int) $betRecord['status'] !== 1 || blank($betRecord['payout_time'])) {
            return null;
        }

        return new BaseTotalBetRecordData(
            accountKey: (string) $betRecord['player_id'],
            gameCode: (string) $betRecord['game_code'],
            referenceNo: (string) $betRecord['bet_id'],
            bet: (string) $betRecord['bet'],
            validBet: (string) $betRecord['valid_bet'],
            winLose: (string) $betRecord['win_lose'],
            betTime: (string) $betRecord['bet_time'],
            payoutTime: (string) $betRecord['payout_time'],
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
     * 該時段有投注的三方玩家 id
     *
     * @return array<int, string>
     *
     * @throws ApiException
     */
    private function getBetPlayerIds(string $start, string $end): array
    {
        $rows = $this->fetchAllPages(
            '/api/getGameSummary',
            ['Agent' => $this->agent_name, 'StartDate' => $start, 'EndDate' => $end],
            ['Agent' => $this->agent_name],
            $this->actionTitle . '_api_getBetPlayers',
        );

        return array_values(array_unique(array_filter(array_column($rows, 'user_apiplayerkey'))));
    }

    /**
     * 單一玩家該時段的注單
     *
     * @return array<int, array<string, mixed>>
     *
     * @throws ApiException
     */
    private function getPlayerBetRecords(string $start, string $end, string $playerId): array
    {
        return $this->fetchAllPages(
            '/api/getGameLog',
            ['Agent' => $this->agent_name, 'Playerid' => $playerId, 'StartDate' => $start, 'EndDate' => $end],
            ['Agent' => $this->agent_name, 'Playerid' => $playerId],
            $this->actionTitle . '_api_getBetRecords',
        );
    }

    /**
     * 逐頁取回三方 data 陣列
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $signData  參與簽名的欄位（三方只簽 Agent／Playerid，不是整包 payload）
     * @return array<int, array<string, mixed>>
     *
     * @throws ApiException
     */
    private function fetchAllPages(string $path, array $payload, array $signData, string $action): array
    {
        $rows = [];
        $page = 1;
        $lastPage = 1;

        while ($page <= $lastPage && $page <= self::MAX_PAGES) {
            $json = $this->postApi($path, [
                ...$payload,
                'PageIndex' => $page,
                'PageSize' => self::PAGE_SIZE,
                'Hash' => $this->sign($signData),
            ], $action);

            $rows = [...$rows, ...($json['data'] ?? [])];
            $lastPage = (int) ($json['pagination']['last_page'] ?? 1);
            $page++;
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     *
     * @throws ApiException
     */
    private function postApi(string $path, array $data, string $action): array
    {
        $json = Http::logAction($action)->post($this->api_url . $path, $data)->throw()->json();

        if (!is_array($json)) {
            throw new ApiException('Third party error: not json response');
        }

        // 三方成功時 error 為 null，isset 已排除
        if (isset($json['error'])) {
            throw new ApiException('Third party error: ' . json_encode($json['error'], JSON_UNESCAPED_UNICODE));
        }

        return $json;
    }

    /**
     * 三方時間（GMT+8）轉 DB 存放的 UTC 字串
     */
    private static function toDbTime(mixed $time): ?string
    {
        if (blank($time)) {
            return null;
        }

        return Carbon::parse((string) $time, self::API_TIMEZONE)->timezone('UTC')->toDateTimeString();
    }

    private function sign(array $data)
    {
        // 2025-09-09 OPPO 要求加密時 Amount 去掉多餘的 0 和小數點
        // if (isset($data['Amount']) && str_contains($data['Amount'], '.')) {
        //     $data['Amount'] = rtrim(rtrim($data['Amount'], '0'), '.');
        // }
        // 2026-02-24 OPPO 說不用去掉小數點了, 保持原樣就好
        $sign = md5(strtolower(implode($data) . '8888' . $this->api_key));

        return $sign;
    }

    /**
     * 建立我方遊戲商帳號（遷自 RD-Studio create_oppo_account）
     *
     * 登入／轉帳一律用三方回傳的 player_id，沒拿到 Playerid 的帳號一律作廢。
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

        $password = [REDACTED], $account->created_at->toDateTimeString());
        $playerId = $this->createVendorAccount($username, $password);

        // 三方限制 1 秒只能呼叫一次（比照舊系統逐筆間隔）
        sleep(1);

        if ($playerId === null) {
            return CreateAccountResultData::from(['status' => false, 'username' => $username]);
        }

        $account->update([
            'player_id' => $playerId,
            'password' => $password,
            'member_id' => 0,
        ]);

        return CreateAccountResultData::from(['status' => true, 'username' => $username]);
    }

    /**
     * 建立三方遊戲商帳號
     *
     * @return string|null 三方 Playerid，失敗回 null
     */
    private function createVendorAccount(string $username, string $password): ?string
    {
        // Hash 只簽 Agent／Password／Name 三欄，且順序即 implode 順序；Tel／Memo 不參與簽名
        $signData = [
            'Agent' => $this->agent_name,
            'Password' => $password,
            'Name' => $username,
        ];

        $data = [
            ...$signData,
            'Tel' => '0123456789',
            'Memo' => '',
            'Hash' => $this->sign($signData),
        ];

        try {
            $json = $this->postApi('/api/createplayer', $data, $this->actionTitle . '_api_createAccount');
        } catch (Throwable $e) {
            Log::error('[Oppo][createAccount] request failed', [
                'username' => $username,
                'error' => $e->getMessage(),
                'trace' => Common::formatTrace($e),
            ]);

            return null;
        }

        $playerId = $json['data']['Playerid'] ?? null;

        if (empty($playerId)) {
            Log::error('[Oppo][createAccount] Playerid not found', [
                'username' => $username,
                'response' => $json,
            ]);

            return null;
        }

        return (string) $playerId;
    }
}
