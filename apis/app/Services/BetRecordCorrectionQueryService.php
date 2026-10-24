<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\Vendor\VendorInfoTypeEnum;
use App\Helpers\Common;
use App\Models\SummaryBetRecord;
use App\Models\TotalBetRecord;
use App\Models\Vendor;
use App\Models\VendorInfo;
use App\Vendors\Models\AbstractAccount;
use App\Vendors\Models\AbstractBetRecord;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;

/**
 * 補帳查詢：把同一個「遊戲商 × 商戶 × 日期」拉出四份對照數據。
 *
 * 1. SBR 補帳明細（correction = 1）：兩個方向各一份，附上可直接貼給客服的說明字串
 *    - 本日關帳補了哪些資料日（summary_time = 查詢日）
 *    - 本日資料被補在哪些關帳日（data_time = 查詢日）
 * 2. SBR 區間合計
 * 3. TBR 區間合計（時間欄位取 config/summary_bet_record.php 的 time_field）
 * 4. 遊戲商注單合計（{vendor}_bet_records，依商戶限定）
 *
 * 日界依 `vendors.calc_time_zone`（時差小時數）換算，非固定 +8。
 */
readonly class BetRecordCorrectionQueryService
{
    /** TBR 未在 config/summary_bet_record.php 定義時的預設時間欄位 */
    private const string DEFAULT_TIME_FIELD = 'payout_time';

    public function __construct(private VendorService $vendorService) {}

    /**
     * @param  VendorInfo|null  $vendorInfo  商戶；null = 該遊戲商全部啟用中商戶
     * @param  string  $date  日期（Y-m-d），以 $offset 時區解讀
     * @param  int|null  $offset  時區時差（小時）；null 表示沿用 vendors.calc_time_zone
     * @return array{
     *     offset: int,
     *     vendor_offset: int,
     *     start: string,
     *     end: string,
     *     time_field: string,
     *     corrections: array<int, array<string, string>>,
     *     corrections_by_data_time: array<int, array<string, string>>,
     *     summary: array<string, array<string, string>>,
     *     total: array<string, string>,
     *     bet_record: array<string, string|null>,
     * }
     */
    public function query(Vendor $vendor, ?VendorInfo $vendorInfo, string $date, ?int $offset = null): array
    {
        $vendorOffset = (int) $vendor->calc_time_zone;
        $offset ??= $vendorOffset;
        // 該時區當日 00:00 對應的 UTC 時間
        $start = Carbon::parse($date, 'UTC')->startOfDay()->subHours($offset)->toDateTimeString();
        $end = Carbon::parse($start, 'UTC')->addDay()->toDateTimeString();
        $timeField = config('summary_bet_record.' . $vendor->id . '.time_field', self::DEFAULT_TIME_FIELD);

        return [
            'offset' => $offset,
            'vendor_offset' => $vendorOffset,
            'start' => $start,
            'end' => $end,
            'time_field' => $timeField,
            'corrections' => $this->corrections($vendor, $vendorInfo, $start, $end, $offset),
            'corrections_by_data_time' => $this->correctionsByDataTime($vendor, $vendorInfo, $start, $end, $offset),
            'summary' => [
                // 調帳列的 summary_time 記在關帳整點、data_time 才是原始資料日，
                // 兩種篩法都列出來才看得出「這批是補哪幾天的帳」
                'summary_time' => $this->summaryTotals($vendor, $vendorInfo, $start, $end, 'summary_time'),
                'data_time' => $this->summaryTotals($vendor, $vendorInfo, $start, $end, 'data_time'),
            ],
            'total' => $this->totalTotals($vendor, $vendorInfo, $start, $end, $timeField),
            'bet_record' => $this->betRecordTotals($vendor, $vendorInfo, $start, $end),
        ];
    }

    /**
     * SBR 調帳明細：查詢日內（summary_time 落在當日 0～24 點）的調帳，依原始資料日分組。
     *
     * 調帳列的 summary_time 記在補帳當下所用時區的關帳整點（歷史上多為台北 +8），
     * 不一定等於查詢時區的日界整點，故以整日範圍篩、不用整點 exact match。
     *
     * @return array<int, array<string, string>>
     */
    private function corrections(Vendor $vendor, ?VendorInfo $vendorInfo, string $start, string $end, int $offset): array
    {
        return $this->correctionRows(
            $vendor,
            $vendorInfo,
            $offset,
            fn (Builder $query): Builder => $query
                ->where('summary_bet_records.summary_time', '>=', $start)
                ->where('summary_bet_records.summary_time', '<', $end),
        );
    }

    /**
     * 反向的調帳明細：查詢日的資料（data_time）被補在哪些關帳日，依關帳日分組。
     * 帳補在別天時，第一份（summary_time = 查詢日）看不到，只有從資料日這頭查才找得到。
     *
     * @return array<int, array<string, string>>
     */
    private function correctionsByDataTime(Vendor $vendor, ?VendorInfo $vendorInfo, string $start, string $end, int $offset): array
    {
        return $this->correctionRows(
            $vendor,
            $vendorInfo,
            $offset,
            fn (Builder $query): Builder => $query
                ->where('summary_bet_records.data_time', '>=', $start)
                ->where('summary_bet_records.data_time', '<', $end),
        );
    }

    /**
     * @param  callable(Builder): Builder  $filter  時間條件（決定從關帳日還是資料日這頭查）
     * @return array<int, array<string, string>>
     */
    private function correctionRows(Vendor $vendor, ?VendorInfo $vendorInfo, int $offset, callable $filter): array
    {
        $timezone = sprintf('%+03d:00', $offset);

        $rows = SummaryBetRecord::query()
            ->tap($this->scopeByVendorInfo($vendor, $vendorInfo, 'summary_bet_records'))
            ->where('summary_bet_records.vendor_id', $vendor->id)
            ->where('summary_bet_records.correction', 1)
            ->tap($filter)
            ->selectRaw("DATE(CONVERT_TZ(summary_bet_records.data_time, '+00:00', ?)) as data_date", [$timezone])
            ->selectRaw("DATE(CONVERT_TZ(summary_bet_records.summary_time, '+00:00', ?)) as summary_date", [$timezone])
            ->selectRaw('SUM(summary_bet_records.bet) as total_bet')
            ->selectRaw('SUM(summary_bet_records.win_lose) as total_win_lose')
            ->groupBy('data_date', 'summary_date')
            ->orderBy('data_date')
            ->orderBy('summary_date')
            ->get();

        return $rows->map(fn ($row): array => [
            'data_date' => (string) $row->data_date,
            'summary_date' => (string) $row->summary_date,
            'bet' => (string) $row->total_bet,
            'win_lose' => (string) $row->total_win_lose,
            'description' => sprintf(
                '補%s b:%s wl:%s',
                Carbon::parse($row->data_date)->format('n/j'),
                $this->trimZeros((string) $row->total_bet),
                $this->trimZeros((string) $row->total_win_lose),
            ),
        ])->all();
    }

    /**
     * @param  string  $timeField  篩選用的時間欄位：summary_time（彙總時間）或 data_time（原始資料時間）
     * @return array<string, string>
     */
    private function summaryTotals(Vendor $vendor, ?VendorInfo $vendorInfo, string $start, string $end, string $timeField): array
    {
        $column = 'summary_bet_records.' . $timeField;

        $row = SummaryBetRecord::query()
            ->tap($this->scopeByVendorInfo($vendor, $vendorInfo, 'summary_bet_records'))
            ->where('summary_bet_records.vendor_id', $vendor->id)
            ->where($column, '>=', $start)
            ->where($column, '<', $end)
            ->selectRaw('SUM(summary_bet_records.bet) as bet')
            ->selectRaw('SUM(summary_bet_records.win_lose) as win_lose')
            ->selectRaw('SUM(summary_bet_records.jackpot) as jackpot')
            ->selectRaw('COUNT(*) as record_count')
            ->first();

        return [
            'bet' => $this->amount($row?->bet),
            'win_lose' => $this->amount($row?->win_lose),
            'jackpot' => $this->amount($row?->jackpot),
            'record_count' => (string) ($row?->record_count ?? 0),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function totalTotals(Vendor $vendor, ?VendorInfo $vendorInfo, string $start, string $end, string $timeField): array
    {
        $column = 'total_bet_records.' . $timeField;

        $row = TotalBetRecord::query()
            ->tap($this->scopeByVendorInfo($vendor, $vendorInfo, 'total_bet_records'))
            ->where('total_bet_records.vendor_id', $vendor->id)
            ->where($column, '>=', $start)
            ->where($column, '<', $end)
            ->selectRaw('SUM(total_bet_records.bet) as bet')
            ->selectRaw('SUM(total_bet_records.valid_bet) as valid_bet')
            ->selectRaw('SUM(total_bet_records.win_lose) as win_lose')
            ->selectRaw('SUM(total_bet_records.jackpot) as jackpot')
            ->selectRaw('COUNT(*) as record_count')
            ->first();

        return [
            'bet' => $this->amount($row?->bet),
            'valid_bet' => $this->amount($row?->valid_bet),
            'win_lose' => $this->amount($row?->win_lose),
            'jackpot' => $this->amount($row?->jackpot),
            'record_count' => (string) ($row?->record_count ?? 0),
        ];
    }

    /**
     * 遊戲商注單合計。
     *
     * 金額優先走 SQL SUM（欄位名由 Model 的 getBetColumn 等提供）；
     * 遇到金額是多欄位計算得出（回 null，例如 ScrBetRecord 的 win - bet）才退回
     * cursor 逐筆以存取器累加。時間欄位取 payout_time，沒有才退回 bet_time。
     *
     * @return array<string, string|null>
     */
    private function betRecordTotals(Vendor $vendor, ?VendorInfo $vendorInfo, string $start, string $end): array
    {
        $empty = ['bet' => null, 'valid_bet' => null, 'win_lose' => null, 'record_count' => null, 'note' => null];

        // 指定商戶就只看它；查全部 = 該遊戲商全部啟用中的一般商戶（活動商戶另有帳，不混）
        $vendorInfoIds = $vendorInfo
            ? [$vendorInfo->id]
            : VendorInfo::query()
                ->where('vendor_id', $vendor->id)
                ->where('status', 1)
                ->where('type', '!=', VendorInfoTypeEnum::EVENT_V2->value)
                ->pluck('id')
                ->all();

        if ($vendorInfoIds === []) {
            return [...$empty, 'note' => '該遊戲商沒有啟用中的商戶'];
        }

        $driver = $this->vendorService->getDriver($vendor->id);

        /** @var AbstractBetRecord $model */
        $model = new ($driver->getBetRecordModel());
        $betTable = $model->getTable();

        ['column' => $timeColumn, 'note' => $timeNote, 'indexed' => $timeIndexed] =
            $this->betRecordTimeColumn($vendor, $model);

        // 注單表自帶 vendor_info_id 就直接篩，不必 join 帳號表：pgssw 那種共用大表
        // 用帳號 join 會變成「6000+ 帳號 × 每帳號數百筆」的扇出（實測掃 167 萬列）
        $scopedByVendorInfo = Schema::hasColumn($betTable, 'vendor_info_id');

        /** @var AbstractAccount $accountModel */
        $accountModel = new ($driver->getAccountModel());
        // 兩側欄位名不一定相同（ACE333 是 bet.playerID = acc.player_id），各取各的 Model 宣告
        $usernameColumn = $scopedByVendorInfo ? null : $model->getVendorUsernameColumn();
        $accountUsernameColumn = $scopedByVendorInfo ? null : $accountModel->getVendorUsernameColumn();

        if ($timeColumn === null || (!$scopedByVendorInfo && ($usernameColumn === null || $accountUsernameColumn === null))) {
            return [...$empty, 'note' => '該遊戲商注單表缺少時間或商戶／帳號欄位，無法比對'];
        }

        // 沒索引就不跑：這類表動輒數百萬列，全表掃會把 DB 拖垮
        if (!$timeIndexed) {
            return [...$empty, 'note' => sprintf(
                '%s.%s 沒有索引，為免全表掃不執行查詢。請先為該欄位建索引，'
                . '或在 config/summary_bet_record.php 改用有索引的時間欄位。',
                $betTable,
                $timeColumn,
            )];
        }

        $query = $model->newQuery()
            ->where($betTable . '.' . $timeColumn, '>=', $start)
            ->where($betTable . '.' . $timeColumn, '<', $end);

        if ($scopedByVendorInfo) {
            $query->whereIn($betTable . '.vendor_info_id', $vendorInfoIds);
        } else {
            $accountTable = $accountModel->getTable();
            $query
                ->join($accountTable, $accountTable . '.' . $accountUsernameColumn, '=', $betTable . '.' . $usernameColumn)
                ->whereIn($accountTable . '.vendor_info_id', $vendorInfoIds);
        }

        $columns = [$model->getBetColumn(), $model->getValidBetColumn(), $model->getWinLoseColumn()];
        $note = sprintf(
            '時間欄位 %s%s，商戶 %s（%s）',
            $timeColumn,
            $timeNote,
            implode(', ', $vendorInfoIds),
            $scopedByVendorInfo ? '注單表自帶 vendor_info_id' : '經帳號表對應',
        );

        if (!in_array(null, $columns, true)) {
            $grammar = $model->getConnection()->getQueryGrammar();
            [$betColumn, $validBetColumn, $winLoseColumn] = array_map(
                fn (string $column): string => $grammar->wrap($betTable . '.' . $column),
                $columns,
            );

            $row = $query
                ->selectRaw("SUM({$betColumn}) as bet")
                ->selectRaw("SUM({$validBetColumn}) as valid_bet")
                ->selectRaw("SUM({$winLoseColumn}) as win_lose")
                ->selectRaw('COUNT(*) as record_count')
                ->first();

            return [
                'bet' => $this->amount($row?->bet),
                'valid_bet' => $this->amount($row?->valid_bet),
                'win_lose' => $this->amount($row?->win_lose),
                'record_count' => (string) ($row?->record_count ?? 0),
                'note' => $note . '，SQL SUM',
            ];
        }

        // 金額為計算得出，只有存取器知道怎麼算，改逐筆累加
        $bet = '0';
        $validBet = '0';
        $winLose = '0';
        $count = 0;

        foreach ($query->select($betTable . '.*')->cursor() as $record) {
            $bet = Common::numAdd($bet, $record->getBet(), 4);
            $validBet = Common::numAdd($validBet, $record->getValidBet(), 4);
            $winLose = Common::numAdd($winLose, $record->getWinLose(), 4);
            $count++;
        }

        return [
            'bet' => $bet,
            'valid_bet' => $validBet,
            'win_lose' => $winLose,
            'record_count' => (string) $count,
            'note' => $note . '，逐筆累加（金額為計算得出）',
        ];
    }

    /**
     * 注單表要用哪個時間欄位。
     *
     * 1. config/summary_bet_record.php 有設就照它，跟 TBR 那份合計對齊——即使沒索引
     *    也不改欄位，改了等於偷換語意；由呼叫端據 indexed 決定不跑並提示
     * 2. 沒設（或注單表沒有該欄位）才自己挑，優先有索引的
     *
     * @return array{column: string|null, note: string, indexed: bool}
     */
    private function betRecordTimeColumn(Vendor $vendor, AbstractBetRecord $model): array
    {
        // 只認索引的首欄：複合索引的非首欄吃不到 range scan，算它有索引等於自欺
        $indexed = collect(Schema::getIndexes($model->getTable()))
            ->map(fn (array $index): ?string => $index['columns'][0] ?? null)
            ->filter()
            ->unique();

        $configured = config('summary_bet_record.' . $vendor->id . '.time_field');

        if (is_string($configured) && Schema::hasColumn($model->getTable(), $configured)) {
            return [
                'column' => $configured,
                'note' => '（依 config/summary_bet_record.php）',
                'indexed' => $indexed->contains($configured),
            ];
        }

        $candidates = array_values(array_filter([
            $model->getPayoutTimeColumn(),
            $model->getBetTimeColumn(),
        ]));

        if ($candidates === []) {
            return ['column' => null, 'note' => '', 'indexed' => false];
        }

        foreach ($candidates as $index => $column) {
            if ($indexed->contains($column)) {
                return [
                    'column' => $column,
                    'note' => $index === 0 ? '' : sprintf('（%s 無索引，改用有索引的欄位）', $candidates[0]),
                    'indexed' => true,
                ];
            }
        }

        return ['column' => $candidates[0], 'note' => '', 'indexed' => false];
    }

    /**
     * 依商戶界定會員範圍：member 是否屬於某商戶，由該遊戲商的帳號表決定。
     *
     * 未指定商戶（查全部）時不加條件——SBR/TBR 本來就以 vendor_id 篩過，
     * 全商戶的會員集合就是整個 vendor 的會員集合，再 join 只是浪費。
     */
    private function scopeByVendorInfo(Vendor $vendor, ?VendorInfo $vendorInfo, string $table): callable
    {
        return function (Builder $query) use ($vendor, $vendorInfo, $table): void {
            if ($vendorInfo === null) {
                return;
            }

            $accountModel = new ($this->vendorService->getDriver($vendor->id)->getAccountModel());

            // 子查詢而非 join：一個會員在同商戶可能有多個遊戲帳號，join 會把金額算成多份
            $query->whereIn(
                $table . '.member_id',
                $accountModel->newQuery()
                    ->where('vendor_info_id', $vendorInfo->id)
                    ->where('member_id', '>', 0)
                    ->select('member_id'),
            );
        };
    }

    /**
     * DB 讀出的浮點數入境即正規化
     */
    private function amount(mixed $value): string
    {
        return Common::numAdd('0', (string) ($value ?? 0), 4);
    }

    /**
     * 去掉尾端多餘的 0（對齊舊 SQL 的 trim(...)+0）
     */
    private function trimZeros(string $amount): string
    {
        $normalized = $this->amount($amount);

        return str_contains($normalized, '.')
            ? rtrim(rtrim($normalized, '0'), '.')
            : $normalized;
    }
}
