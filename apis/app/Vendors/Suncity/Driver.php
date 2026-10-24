<?php

declare(strict_types=1);

namespace App\Vendors\Suncity;

use App\Data\BaseTotalBetRecordData;
use App\Data\InsertedBetRecordData;
use App\Data\Vendor\CreateAccountParamsData;
use App\Exceptions\ApiException;
use App\Helpers\Common;
use App\Vendors\Contracts\ShouldScheduleBetRecordFetch;
use App\Vendors\Models\SuncityAccount;
use App\Vendors\Models\SuncityBetRecord;
use Carbon\Carbon;

/**
 * SunCity（vendor 292）：iGaming 平台的 Clubsuncity 品牌，轉帳錢包（target/tickets#4434）
 *
 * API 規格與 Play8oy（vendor 30，同平台同版本 v10.1）完全相同，簽名／轉帳／查餘額／建帳號／遊戲清單
 * 全數沿用 Play8oy\Driver，僅覆寫 App deep-link scheme 與本地帳號表結構。
 *
 * 與 Play8oy 不同：Play8oy 的注單由 RD-Studio 舊系統拉取，本 vendor 的注單由 APIs
 * fetch_bet_record 排程呼叫 `game/log/record` 拉取，並走整點 SBR（config/summary_bet_record.php）。
 * 規格見 docs/integrations/vendors/Suncity/api.md。
 */
class Driver extends \App\Vendors\Play8oy\Driver implements ShouldScheduleBetRecordFetch
{
    /** 查無資料（該窗口無注單，不是錯誤） */
    private const string CODE_NO_DATA = '-50001';

    private const string CODE_SUCCESS = '0';

    /** 三方查詢參數（StartDate／EndDate）與注單 CreateTime 的時區（UTC+8） */
    private const string VENDOR_TIMEZONE = '+08:00';

    /** suncity_bet_records 標準 schema 的狀態值（0 建立中／1 已結算／2 取消），非全域 BetRecordStatusEnum */
    private const int STATUS_SETTLED = 1;

    /**
     * 拉單結束時間與現在的安全餘量（秒）
     *
     * 三方對當日查詢要求 EndDate 嚴格早於其伺服器現在時間，否則回 -50003（2026-10-01 dev 實測）；
     * 留 2 分鐘吸收時鐘誤差。被截掉的尾段由下一輪排程的 1 小時回看窗覆蓋，不會漏；
     * 三方資料本身另有約 20 分鐘延遲，所以這裡不需要更大的餘量。
     */
    private const int END_SAFETY_LAG_SECONDS = 120;

    protected string $accountModel = SuncityAccount::class;

    protected string $betRecordModel = SuncityBetRecord::class;

    protected string $actionTitle = 'SUNCITY';

    protected string $appScheme = 'lobbyclubsuncity';

    /**
     * suncity_accounts 沒有 username_modified 欄（Play8oy 舊系統表才有）
     *
     * @return array<string, mixed>
     */
    protected function newAccountAttributes(CreateAccountParamsData $params): array
    {
        return [
            'vendor_info_id' => $params->vendorInfo->id,
            'username' => $params->username,
            'admin' => 1,
            'member_id' => -999999999,
        ];
    }

    public function betRecordCredentialKey(array $info): string
    {
        // 三方以 merchantName 識別商戶：同商戶的 vendor_info 同片只拉一次
        return (string) ($info['API_MERCHANT_NAME'] ?? '');
    }

    public function betRecordApiWindowMinutes(): int
    {
        // game/log/record 單次區間 ≤ 600 秒
        return 10;
    }

    public function betRecordMinStartTime(): ?string
    {
        // 新 vendor，無舊系統已入帳的歷史注單
        return null;
    }

    /**
     * 三方 game/log/record 每分鐘 40 次（附錄 6）。框架以每次 fetchBetRecords() 計 1 次，
     * 但一次呼叫可能因跨日拆段與翻頁打出多個請求，故對 40/分再對折
     */
    public function betRecordApiRateLimit(): array
    {
        return ['max_calls' => 20, 'per_seconds' => 60];
    }

    /**
     * 以 game/log/record 拉取已結算注單
     *
     * 入參為 UTC，三方查詢時間為 UTC+8、格式 `Y-m-d H:i:s.v`；EndDate 取 `to - 1 秒` 的 .999，
     * 相鄰時間片邊界秒不重疊。三方限制 StartDate／EndDate 同一日（-50004），跨日窗口拆兩段。
     * 依賴 BetRecordFetchService 已依 betRecordApiWindowMinutes() 切成 ≤10 分鐘的片，故至多跨一次午夜、只拆兩段。
     * 窗口尾端先夾到「現在 − END_SAFETY_LAG_SECONDS」，夾完無剩餘窗口則不打三方。
     */
    public function fetchBetRecords(string $fromDate, string $toDate): array
    {
        $from = Carbon::parse($fromDate, 'UTC');
        $to = Carbon::parse($toDate, 'UTC')
            ->min(Carbon::now('UTC')->startOfSecond()->subSeconds(self::END_SAFETY_LAG_SECONDS));

        if ($to <= $from) {
            return [];
        }

        $from = $from->setTimezone(self::VENDOR_TIMEZONE);
        $end = $to->setTimezone(self::VENDOR_TIMEZONE)->subSecond();

        $segments = $from->isSameDay($end)
            ? [[$from, $end]]
            : [[$from, $from->copy()->endOfDay()], [$end->copy()->startOfDay(), $end]];

        $records = [];
        foreach ($segments as [$segmentStart, $segmentEnd]) {
            array_push($records, ...$this->fetchBetRecordSegment(
                startDate: $segmentStart->format('Y-m-d H:i:s') . '.000',
                endDate: $segmentEnd->format('Y-m-d H:i:s') . '.999',
            ));
        }

        return $records;
    }

    /**
     * 拉取同一日內的單段時間注單（逐頁續拉）
     *
     * @return array<int, array<string, mixed>>
     *
     * @throws ApiException
     */
    private function fetchBetRecordSegment(string $startDate, string $endDate): array
    {
        $records = [];
        $page = 1;
        $totalPage = 1;

        do {
            $data = [
                'PageNumber' => $page,
                'StartDate' => $startDate,
                'EndDate' => $endDate,
            ];

            // 業務碼走 HTTP 400（-50001 查無資料也是 400），不可 throw()；5xx／非 JSON 由下方拋例外讓時間片重跑
            $response = $this->postJson($this->actionTitle . '_FetchBetRecord', 'game/log/record', $data);
            $json = $this->decode($response);

            if ($json === null) {
                throw new ApiException('Third party error: not json response (HTTP ' . $response->status() . ')');
            }

            $code = (string) ($json['Code'] ?? '');

            if ($code === self::CODE_NO_DATA) {
                // 前頁已宣告還有後續頁卻回查無資料：三方資料不一致，回已拉到的部分會讓時間片被標記完成而漏帳
                if ($page > 1) {
                    throw new ApiException(sprintf('Third party error: page %d returned no-data while total pages is %d', $page, $totalPage));
                }

                break;
            }

            if ($code !== self::CODE_SUCCESS) {
                // 必須拋例外讓時間片失敗重跑，回空陣列會被當成「該片無注單」而漏帳
                throw new ApiException('Third party error: (' . $code . ') ' . ($json['Message'] ?? ''));
            }

            // 成功碼卻缺欄位時不可當成無資料：回空會讓時間片被標記完成而永久缺帳
            if (!isset($json['Data']['Page']['TotalPage']) || !is_array($json['Data']['Record'] ?? null)) {
                throw new ApiException('Third party error: format error');
            }

            array_push($records, ...array_values($json['Data']['Record']));

            $totalPage = (int) $json['Data']['Page']['TotalPage'];
            $page++;
        } while ($page <= $totalPage);

        return $records;
    }

    /**
     * 轉為 suncity_bet_records 欄位；金額除以 rate 換算平台點數
     *
     * vendor_info_id／member_id 不填（欄位預設 0）：歸戶在 TBR 轉檔時以 username → suncity_accounts 解析
     */
    public function formatBetRecord(array $raw, string $rate): array
    {
        $rate = Common::numCompare($rate, '0', 4) > 0 ? $rate : '1';

        $bet = Common::numDiv(Common::numAdd('0', (string) ($raw['BetCoin'] ?? '0'), 4), $rate, 4);
        $win = Common::numDiv(Common::numAdd('0', (string) ($raw['WinAmount'] ?? '0'), 4), $rate, 4);
        // 三方 CreateTime 為 UTC+8，入庫轉 UTC
        $createTime = Carbon::parse((string) $raw['CreateTime'], self::VENDOR_TIMEZONE)->utc()->format('Y-m-d H:i:s');

        return [
            'bet_id' => (string) $raw['GameSerialID'],
            'username' => (string) $raw['UserName'],
            // ThemeID 前四碼 = GameID（對應 games.game_type），後四碼 = 廳館 ID
            'game_code' => (string) intdiv((int) $raw['ThemeID'], 10000),
            'bet' => $bet,
            'valid_bet' => Common::numDiv(Common::numAdd('0', (string) ($raw['AvailTotalBet'] ?? '0'), 4), $rate, 4),
            'win' => $win,
            'win_lose' => Common::numSub($win, $bet, 4),
            'rolling_chip' => '0.0000',
            'bet_time' => $createTime,
            'payout_time' => $createTime,
            // game/log/record 只回已結算的結果單
            'status' => self::STATUS_SETTLED,
            'info' => json_encode($raw, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ];
    }

    public function formatBaseTotalBetRecord(InsertedBetRecordData $record): ?BaseTotalBetRecordData
    {
        $betRecord = $record->attributes;

        if ((int) ($betRecord['status'] ?? 0) !== self::STATUS_SETTLED) {
            return null;
        }

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
        return SuncityBetRecord::class;
    }

    public function getAccountModelClass(): string
    {
        return SuncityAccount::class;
    }
}
