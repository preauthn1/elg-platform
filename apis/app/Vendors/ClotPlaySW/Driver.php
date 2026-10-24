<?php

declare(strict_types=1);

namespace App\Vendors\ClotPlaySW;

use App\Data\BaseTotalBetRecordData;
use App\Data\GameInfoData;
use App\Data\InsertedBetRecordData;
use App\Data\Vendor\CreateAccountParamsData;
use App\Data\Vendor\CreateAccountResultData;
use App\Data\Vendor\GetGameListParamsData;
use App\Data\Vendor\GetGameListResultData;
use App\Data\VendorBonusCodeRequestData;
use App\Data\VendorBonusCodeResponseData;
use App\Data\VendorGameUrlParamsData;
use App\Data\VendorGameUrlResultData;
use App\Enums\Game\GameTypeEnum;
use App\Enums\LanguageEnum;
use App\Exceptions\ApiException;
use App\Helpers\Common;
use App\Vendors\AbstractSingleWallet;
use App\Vendors\Contracts\BonusCodeInterface;
use App\Vendors\Contracts\HasCallbackPath;
use App\Vendors\Contracts\ShouldScheduleAccountCreation;
use App\Vendors\Contracts\ShouldSyncGameList;
use App\Vendors\Contracts\SupportsBetRecordFetch;
use App\Vendors\Models\ClotswAccount;
use App\Vendors\Models\ClotswBetRecord;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * @knowledge rules/bet-record-pull
 */
class Driver extends AbstractSingleWallet implements BonusCodeInterface, HasCallbackPath, ShouldScheduleAccountCreation, ShouldSyncGameList, SupportsBetRecordFetch
{
    /** 三方對帳 API 的時間參數時區（api.md：+8 時區字串） */
    private const string API_TIMEZONE = 'Asia/Taipei';

    /** 對帳 API 單頁筆數；開大讓單一時間片盡量一頁取完（第 2 頁起同樣佔三方 10 req/min 預算） */
    private const int FETCH_PER_PAGE = 10000;

    /** 分頁上限，防三方 pagination 異常打成無限迴圈 */
    private const int FETCH_MAX_PAGES = 20;

    protected string $accountModel = ClotswAccount::class;

    protected string $betRecordModel = ClotswBetRecord::class;

    protected string $actionTitle = 'CLOTSW';

    /** 拉注單走 setInfo 載入的商戶連線參數（該路徑不經 setVendorInfo，取不到 $this->vendorInfo） */
    protected array $info = [];

    /**
     * 預設回調路徑後綴，對應 routes/external/sw.php 的 clot 路由群組
     */
    public static function defaultCallbackPath(): string
    {
        return '/external/api/sw/clot';
    }

    public function setInfo(array $info): void
    {
        // 錢包／遊戲相關流程透過 $this->vendorInfo->info 直接存取；本屬性供拉注單使用
        $this->info = $info;
    }

    /**
     * 遊戲清單（分頁聚合；維護中遊戲帶 status=0；
     * 子類 ClotPlayP2PSW / ClotPlayLegendSlotSW 繼承本實作，一併納入同步排程）
     */
    public function getGameList(GetGameListParamsData $params): GetGameListResultData
    {
        $this->setVendorInfo($params->vendorInfo);

        $headers = [
            'Authorization' => $this->vendorInfo->info['operatorToken'],
        ];
        $url = $this->vendorInfo->info['apiUrl'] . '/get-game-list';
        $action = $this->actionTitle . '_api_call_getGameList';

        // 依 pagination.last_page 迴圈聚合，上限 50 頁防三方分頁異常
        $maxPage = 50;
        $page = 1;
        $lastPage = 1;
        $gameList = [];
        while ($page <= min($lastPage, $maxPage)) {
            $json = Http::logAction($action)
                ->withHeaders($headers)
                ->get($url, ['page' => $page, 'per_page' => 100])
                ->throw()
                ->json();

            if (!$json) {
                throw new ApiException('Third party error: not json response');
            }

            $gameList = array_merge($gameList, $json['data'] ?? []);
            $lastPage = $json['pagination']['last_page'] ?? 1;
            $page++;
        }

        $data = [];
        foreach ($gameList as $game) {
            $gameCode = (string) $game['slug'];
            $gameInfo = GameInfoData::from([
                'gameCode' => $gameCode,
                'gameName' => $game['name'],
                'imageUrl' => $game['thumbnails']['en']['square'] ?? '',
                'gameType' => ($game['category'] ?? '') === 'table_game'
                    ? GameTypeEnum::ARCADE->value
                    : GameTypeEnum::SLOTS->value,
                'status' => empty($game['isMaintenance']) ? 1 : 0,
                'info' => json_encode($game),
            ]);
            $gameInfo->setGameNames(LanguageEnum::EN->value, $game['name']);
            if (!empty($game['name_cn'])) {
                $gameInfo->setGameNames(LanguageEnum::ZH_TW->value, $game['name_cn']);
                $gameInfo->setGameNames(LanguageEnum::ZH_CN->value, $game['name_cn']);
            }
            $data[$gameCode] = $gameInfo;
        }

        return GetGameListResultData::from(['gameList' => $data]);
    }

    public function getGameUrl(VendorGameUrlParamsData $params): VendorGameUrlResultData
    {
        $gameCode = $params->gameCode;
        $language = $this->getLanguage($params->language->value);
        $postData = [
            'player_unique_id' => $this->vendorAccount->username,
            'player_name' => $this->vendorAccount->username,
            'player_currency' => $this->vendorInfo->currency,
            'game_id' => $gameCode,
            'is_demo' => false,
            'language' => $language,
        ];
        if (isset($this->vendorInfo->info['agentCode'])) {
            $postData['agent_code'] = $this->vendorInfo->info['agentCode'];
        }

        $headers = [
            'Content-Type' => 'application/json',
            'Authorization' => $this->vendorInfo->info['operatorToken'],
        ];

        $action = $this->actionTitle . '_api_call_getGameUrl';
        $url = $this->vendorInfo->info['apiUrl'] . '/launch-game';
        $response = Http::logAction($action)->withHeaders($headers)->post($url, $postData)->throw();
        $json = $response->json();

        if (!$json) {
            throw new ApiException('Third party error: not json response');
        }

        if (($json['code'] ?? '') !== 0) {
            $code = $json['code'] ?? '';
            $error = $json['error'] ?? '';
            $error = 'Third party error: (' . $code . ') ' . $error;
            throw new ApiException($error);
        }
        $url = $json['data']['game_url'] ?? '';

        return VendorGameUrlResultData::from([
            'pc' => $url,
            'mobile' => $url,
        ]);
    }

    public function getBonusCode(VendorBonusCodeRequestData $params): VendorBonusCodeResponseData
    {
        $startAt = $params->startAt ?? Carbon::now();
        $endAt = $params->endAt ?? Carbon::now()->addDay();

        $startAtString = $startAt->timezone('Asia/Taipei')->toDateTimeString();
        $endAtString = $endAt->timezone('Asia/Taipei')->toDateTimeString();

        $postData = [
            'player_unique_id' => $this->vendorAccount->username,
            'player_currency' => $this->vendorInfo->currency,
            'config_name' => $this->vendorInfo->info['bonusConfigName'] ?? '',
            'start_at' => $startAtString,
            'end_at' => $endAtString,
        ];

        if (isset($this->vendorInfo->info['agentCode'])) {
            $postData['agent_code'] = $this->vendorInfo->info['agentCode'];
        }

        $headers = [
            'Content-Type' => 'application/json',
            'Authorization' => $this->vendorInfo->info['operatorToken'],
        ];

        $action = $this->actionTitle . '_api_call_getBonusCode';
        $url = $this->vendorInfo->info['apiUrl'] . '/bonus-code/create';
        $response = Http::logAction($action)->withHeaders($headers)->post($url, $postData)->throw();
        $json = $response->json();

        if (!$json) {
            throw new ApiException('Third party error: not json response');
        }

        if (($json['code'] ?? '') !== 0) {
            $code = $json['code'] ?? '';
            $error = $json['error'] ?? '';
            $error = 'Third party error: (' . $code . ') ' . $error;
            throw new ApiException($error);
        }

        return VendorBonusCodeResponseData::from([
            'bonus_code' => $json['data']['bonus_code']['code'] ?? '',
        ]);
    }

    public function getLanguage(int $languageId): string
    {
        return match ($languageId) {
            LanguageEnum::EN->value => 'en-us',
            LanguageEnum::ZH_TW->value => 'zh-tw',
            LanguageEnum::ZH_CN->value => 'zh-cn',
            LanguageEnum::ID_ID->value => 'id',
            LanguageEnum::VI_VN->value => 'vi',
            LanguageEnum::TH_TH->value => 'th',
            default => 'en-us',
        };
    }

    // -------------------------------------------------------------------------
    // SupportsBetRecordFetch
    //
    // 單一錢包的注單由 seamless 回調即時記帳，這裡拉單只做「三方 ↔ DB 比對」用途，
    // 寫入模式會生出沒有錢包異動的孤兒注單，故 BetRecordFetchService 對
    // is_single_wallet 遊戲商只放行乾跑（--dry-run）。
    // 對照舊系統 RD-Studio `php artisan clotsw_check_bet_record`。
    // -------------------------------------------------------------------------

    /**
     * 三方 /get-transaction-details 單次查詢窗最多 30 分鐘（api.md 資料保留期章節）
     */
    public function betRecordApiWindowMinutes(): int
    {
        return 30;
    }

    /**
     * 對帳 API 不吃 agent_code，回傳範圍由 Authorization 的 operatorToken 決定：
     * 同 token 的商戶共用同一批注單，同時間片只需拉一次
     */
    public function betRecordCredentialKey(array $info): string
    {
        return (string) ($info['operatorToken'] ?? '');
    }

    /**
     * 計費類交易三方只保留 60 天（api.md 資料保留期），更早的窗口拉不到東西
     */
    public function betRecordMinStartTime(): ?string
    {
        return Carbon::now('UTC')->subDays(60)->toDateTimeString();
    }

    /**
     * 三方對帳 API 限 10 req/min（api.md）
     *
     * @return array{max_calls: int, per_seconds: int}
     */
    public function betRecordApiRateLimit(): array
    {
        return ['max_calls' => 10, 'per_seconds' => 60];
    }

    /**
     * 拉取單一時間片的三方交易（切片由 BetRecordFetchService 負責），並依回合加總
     *
     * @return array<int, array<string, mixed>>
     *
     * @throws ApiException
     */
    public function fetchBetRecords(string $fromDate, string $toDate): array
    {
        $start = Carbon::parse($fromDate, 'UTC')->timezone(self::API_TIMEZONE)->toDateTimeString();
        // 三方的 updated_at_to 為含端點，退 1 秒避免與下一片重疊
        $end = Carbon::parse($toDate, 'UTC')->subSecond()->timezone(self::API_TIMEZONE)->toDateTimeString();

        return $this->groupByRound($this->getTransactions($start, $end));
    }

    /**
     * 轉為 clotsw_bet_records 欄位；金額欄位只給三方真的知道的部分——
     * rolling_chip（禮碼）與 settle_status 是本系統自有狀態，不列入比對也不覆蓋
     *
     * @param  array<string, mixed>  $raw
     * @param  string  $rate  商戶匯率：三方金額是放大後的值，取回要除掉
     * @return array<string, mixed>
     */
    public function formatBetRecord(array $raw, string $rate): array
    {
        $rate = Common::numCompare($rate, '0', 4) > 0 ? $rate : '1';
        $bet = Common::numDiv(Common::numAdd('0', (string) ($raw['total_bet_amount'] ?? 0), 4), $rate, 4);
        $win = Common::numDiv(Common::numAdd('0', (string) ($raw['winning'] ?? 0), 4), $rate, 4);

        return [
            'bet_id' => (string) ($raw['game_round_id'] ?? ''),
            'username' => (string) ($raw['player_unique_id'] ?? ''),
            'game_code' => (string) ($raw['game'] ?? ''),
            // 回調的 settle 以 valid_bet 同時寫 bet 與 valid_bet，兩欄一致
            'bet' => $bet,
            'valid_bet' => $bet,
            'win' => $win,
            'win_lose' => Common::numSub($win, $bet, 4),
            'bet_time' => self::toDbTime($raw['created_at'] ?? null),
            'payout_time' => self::toDbTime($raw['updated_at'] ?? null),
            'info' => json_encode([$raw], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ];
    }

    public function formatBaseTotalBetRecord(InsertedBetRecordData $record): ?BaseTotalBetRecordData
    {
        $betRecord = $record->attributes;

        return new BaseTotalBetRecordData(
            accountKey: (string) $betRecord['username'],
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
     * 三方以 transaction 為單位回傳，同一 game_round_id 可能多筆（multi-bet）；
     * DB 一回合一列，先加總回合才對得上（同 RD-Studio ClotSwCheckBetRecord 的比對方式）
     *
     * @param  array<int, array<string, mixed>>  $transactions
     * @return array<int, array<string, mixed>>
     */
    private function groupByRound(array $transactions): array
    {
        $rounds = [];

        foreach ($transactions as $transaction) {
            $roundId = (string) ($transaction['game_round_id'] ?? '');
            if ($roundId === '') {
                continue;
            }

            // 三方 JSON 的 float 入境即正規化，避免精度誤差混進加總
            $bet = Common::numAdd('0', (string) ($transaction['total_bet_amount'] ?? 0), 4);
            $win = Common::numAdd('0', (string) ($transaction['winning'] ?? 0), 4);

            if (!isset($rounds[$roundId])) {
                $rounds[$roundId] = ['total_bet_amount' => $bet, 'winning' => $win] + $transaction;

                continue;
            }

            $rounds[$roundId]['total_bet_amount'] = Common::numAdd($rounds[$roundId]['total_bet_amount'], $bet, 4);
            $rounds[$roundId]['winning'] = Common::numAdd($rounds[$roundId]['winning'], $win, 4);
            // 派彩時間取回合內最後一筆交易
            $rounds[$roundId]['updated_at'] = max(
                (string) ($rounds[$roundId]['updated_at'] ?? ''),
                (string) ($transaction['updated_at'] ?? ''),
            );
        }

        return array_values($rounds);
    }

    /**
     * 逐頁取回時間區間內的三方交易
     *
     * @return array<int, array<string, mixed>>
     *
     * @throws ApiException
     */
    private function getTransactions(string $start, string $end): array
    {
        $url = $this->info['apiUrl'] . '/get-transaction-details';
        $action = $this->actionTitle . '_api_call_getTransactions';
        $headers = ['Authorization' => $this->info['operatorToken']];

        $records = [];
        $page = 1;
        $lastPage = 1;
        while ($page <= min($lastPage, self::FETCH_MAX_PAGES)) {
            $json = Http::logAction($action)
                ->withHeaders($headers)
                ->get($url, [
                    'updated_at_from' => $start,
                    'updated_at_to' => $end,
                    'perPage' => self::FETCH_PER_PAGE,
                    'page' => $page,
                ])
                ->throw()
                ->json();

            // 拉取失敗一律拋出：回空陣列會被當成「該時段無注單」而漏掉整片
            if (!is_array($json['data'] ?? null)) {
                throw new ApiException('Third party error: getTransactions no data');
            }

            $records = array_merge($records, $json['data']);
            $lastPage = (int) ($json['pagination']['last_page'] ?? 1);
            $page++;
        }

        return $records;
    }

    /**
     * 三方時間（ISO8601 含時區）轉 DB 的 UTC datetime；缺值時與回調同慣例給 epoch
     */
    private static function toDbTime(mixed $value): string
    {
        $value = (string) ($value ?? '');

        return $value === ''
            ? '1970-01-01 00:00:00'
            : Carbon::parse($value)->timezone('UTC')->format('Y-m-d H:i:s');
    }

    /**
     * 建立我方遊戲商帳號（遷自 RD-Studio create_clotsw_account 家族）
     *
     * 子類 ClotPlayP2PSW／ClotPlayLegendSlotSW 繼承本實作：三個 vendor id 的三方 API
     * 完全相同，差異只在各自的 accountModel 與商戶設定，不需以 info 分流。
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
     * 建立三方遊戲商帳號（/create-member；player_unique_id 即 getGameUrl 的登入鍵）
     */
    private function createVendorAccount(string $username): bool
    {
        $action = $this->actionTitle . '_api_call_register';
        $url = $this->vendorInfo->info['apiUrl'] . '/create-member';

        $postData = [
            'player_unique_id' => $username,
            'player_name' => $username,
            'player_currency' => $this->vendorInfo->currency,
        ];

        if (isset($this->vendorInfo->info['agentCode'])) {
            $postData['agent_code'] = $this->vendorInfo->info['agentCode'];
        }

        try {
            $json = Http::logAction($action)
                ->withHeaders([
                    'Content-Type' => 'application/json',
                    'Authorization' => $this->vendorInfo->info['operatorToken'],
                ])
                ->post($url, $postData)
                ->throw()
                ->json();
        } catch (Throwable $e) {
            Log::error("[{$this->actionTitle}][{$action}] request failed", [
                'username' => $username,
                'error' => $e->getMessage(),
                'trace' => Common::formatTrace($e),
            ]);

            return false;
        }

        // 三方成功碼為 int 0（口徑同 getGameUrl）
        if (!is_array($json) || ($json['code'] ?? '') !== 0) {
            Log::error("[{$this->actionTitle}][{$action}] API error: code is not 0", [
                'username' => $username,
                'response' => $json,
            ]);

            return false;
        }

        return true;
    }
}
