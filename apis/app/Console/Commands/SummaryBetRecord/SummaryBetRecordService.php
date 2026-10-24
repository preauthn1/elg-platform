<?php

declare(strict_types=1);

namespace App\Console\Commands\SummaryBetRecord;

use App\Enums\Vendor\VendorIdEnum;
use App\Helpers\Common;
use App\Models\SummaryBetRecord;
use App\Models\TotalBetRecord;
use App\Services\BotNotifyService;
use Illuminate\Console\OutputStyle;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * @knowledge rules/summary-bet-record
 */
class SummaryBetRecordService
{
    /** @var array<int> valid_bet_rate = 0 的遊戲 ID 清單，用於排除 v-jackpot 計算 */
    protected array $excludedGameIds = [];

    /** @var array<int, array<string|int, mixed>> [agent_group_id][vendor_id|'DEFAULT'] => rate */
    protected array $vJackpotRates = [];

    /** @var array<int, array<int, mixed>> [customized_setting_group_id][vendor_id] => cost */
    protected array $gameFeeRates = [];

    protected int $vendorId = 0;

    protected string $timeField = 'payout_time';

    /**
     * 最近一次 process() 的寫入統計，供呼叫端記錄 end 日誌。
     *
     * @var array{mode: string, written: int, reversed: int}
     */
    protected array $lastStats = ['mode' => 'none', 'written' => 0, 'reversed' => 0];

    public function __construct(private readonly OutputStyle $output) {}

    /**
     * 執行單一廠商的彙總邏輯。
     *
     * - 未關帳：刪除舊資料後重新寫入 summary_bet_records
     * - 已關帳：計算差異後以調帳模式（correction = 1）寫入
     *
     * @return bool 是否為調帳作業
     *
     * @throws QueryException|\Exception 彙總失敗（告警後上拋——呼叫端 Runner 據此
     *                                   rollback、不寫完成標記，讓回看補洞／retry 接手；
     *                                   吞掉例外會把失敗格永久標成已完成）
     */
    public function process(string $start, string $end): bool
    {
        try {
            $records = $this->buildAggregateQuery($start, $end);

            $isPastCutoff = self::hasPastCutoff($start);

            if ($isPastCutoff) {
                $this->applyHourlyCorrection($records, $start);
            } else {
                $this->clearPreviousSummaryRecords($start);
                $this->persistSummaryRecords($records, $start);
                $this->lastStats = ['mode' => 'overwrite', 'written' => $records->count(), 'reversed' => 0];
                $this->line('  彙總時間：' . $start);
                $this->line(sprintf('  彙總寫入：%d 筆', $records->count()));
                $this->line('  彙總完成');
            }

            return $isPastCutoff;
        } catch (QueryException $e) {
            $this->line('查詢例外：' . $e->getMessage());
            $this->reportError(sprintf('bucket %s：%s', $start, $e->getMessage()));

            throw $e;
        } catch (\Exception $e) {
            $this->line('執行例外：' . $e->getMessage());
            $this->reportError(sprintf('bucket %s：%s', $start, $e->getMessage()));

            throw $e;
        }
    }

    public function setVendorId(int $vendorId): void
    {
        $this->vendorId = $vendorId;
    }

    public function setTimeField(string $field): void
    {
        $this->timeField = $field;
    }

    public function setExcludedGameIds(array $gameIds): void
    {
        $this->excludedGameIds = $gameIds;
    }

    public function setVJackpotRates(array $rates): void
    {
        $this->vJackpotRates = $rates;
    }

    public function setGameFeeRates(array $rates): void
    {
        $this->gameFeeRates = $rates;
    }

    public function getVendorId(): int
    {
        return $this->vendorId;
    }

    /**
     * 取得最近一次 process() 的寫入統計（mode/written/reversed），供 end 日誌使用。
     *
     * @return array{mode: string, written: int, reversed: int}
     */
    public function getLastStats(): array
    {
        return $this->lastStats;
    }

    /**
     * 建立指定時間區間的彙總查詢並執行。
     *
     * 預設依 `total_bet_records.member_id` 直接 join `members`。
     * 若廠商需要透過廠商專屬 accounts 表反查 member_id（例如 WESW、MOTIVATIONSW），
     * 子類可覆寫此方法替換 JOIN 路徑。
     */
    protected function buildAggregateQuery(string $start, string $end): Collection
    {
        $timeField = 'total_bet_records.' . $this->timeField;

        return TotalBetRecord::query()
            ->join('members as m', 'm.id', '=', 'total_bet_records.member_id')
            ->join('agents as a', 'a.id', '=', 'm.parent')
            ->leftJoin('games as g', 'g.id', '=', 'total_bet_records.game_id')
            ->where('total_bet_records.vendor_id', $this->vendorId)
            ->where($timeField, '>=', $start)
            ->where($timeField, '<', $end)
            ->select($this->aggregateSelectColumns())
            ->groupBy(['m.id', 'g.id'])
            ->get();
    }

    /**
     * 彙總查詢使用的 SELECT 欄位（供子類共用）。
     *
     * @return array<int, mixed>
     */
    protected function aggregateSelectColumns(): array
    {
        return [
            'm.id as member_id',
            'g.id as game_id',
            DB::raw('COUNT(*) as record_count'),
            DB::raw('SUM(total_bet_records.valid_bet) as bet'),
            DB::raw('SUM(total_bet_records.win_lose) as win_lose'),
            DB::raw('SUM(total_bet_records.rolling_chip) as rolling_chip'),
            DB::raw('SUM(total_bet_records.commission) as commission'),
            DB::raw('SUM(total_bet_records.jackpot) as jackpot'),
            DB::raw('ANY_VALUE(a.percentage) as percentage'),
            DB::raw('ANY_VALUE(a.customized_setting_group_id) as customized_setting_group_id'),
        ];
    }

    /**
     * 取得當日關帳時間（UTC）。
     *
     * 計算方式：
     * 1. 先從「現在 -6 小時」取得該日 00:00（6 小時緩衝避免跨日誤判）
     * 2. 再 -8 小時將台北時間轉為 UTC → 前一日 16:00 UTC
     */
    public static function getDailyCutoffTime(): string
    {
        $now = time();
        $taipeiMidnight = date('Y-m-d 00:00:00', strtotime('-6 hours', $now));

        return date('Y-m-d H:i:s', strtotime($taipeiMidnight . ' -8 hours'));
    }

    /**
     * 判斷指定時間是否已過關帳時間（true = 已關帳，需用調帳模式）。
     */
    public static function hasPastCutoff(string $datetime): bool
    {
        return strtotime($datetime) < strtotime(self::getDailyCutoffTime());
    }

    protected function applyHourlyCorrection($records, string $start): void
    {
        $this->line('  調帳時間：' . $start);

        $originRecords = $this->fetchAggregatedOriginRecords($start);

        // 新增或有差異的記錄 → 寫入差值
        $corrections = $records->map(function ($item) use ($originRecords) {
            $origin = $originRecords
                ->where('member_id', $item->member_id)
                ->where('game_id', $item->game_id)
                ->first();

            $bet = $item->bet ?? 0;
            $recordCount = $item->record_count ?? 0;
            $winLose = $item->win_lose ?? 0;
            $rollingChip = $item->rolling_chip ?? 0;
            $commission = $item->commission ?? 0;
            $jackpot = $item->jackpot ?? 0;
            $cost = $this->calculateGameFee($winLose, $item->customized_setting_group_id);

            if ($origin
                && $bet == $origin->bet
                && $recordCount == $origin->record_count
                && $winLose == $origin->win_lose
                && $rollingChip == $origin->rolling_chip
                && $commission == $origin->commission
                && $jackpot == $origin->jackpot
                && $cost == $origin->cost
            ) {
                return false;
            } elseif (!$origin) {
                return $item;
            } else {
                $item->bet = Common::numSub($bet, $origin->bet, 8);
                $item->record_count = (int) $recordCount - (int) $origin->record_count;
                $item->win_lose = Common::numSub($winLose, $origin->win_lose, 8);
                $item->rolling_chip = Common::numSub($rollingChip, $origin->rolling_chip, 8);
                $item->commission = Common::numSub($commission, $origin->commission, 8);
                $item->jackpot = Common::numSub($jackpot, $origin->jackpot, 8);
                $item->cost = Common::numSub($cost, $origin->cost, 8);

                return $item;
            }
        })->reject(fn ($value) => $value === false);

        // 已不存在的原始記錄 → 寫入負值抵消
        $reverseCorrections = $originRecords->map(function ($item) use ($records) {
            $matchCount = $records
                ->where('member_id', $item->member_id)
                ->where('game_id', $item->game_id)
                ->count();

            $cost = $this->calculateGameFee($item->win_lose, $item->customized_setting_group_id);

            $hasNonZeroValues = abs((float) $item->bet) > 0
                || abs((float) $item->record_count) > 0
                || abs((float) $item->win_lose) > 0
                || abs((float) $item->rolling_chip) > 0
                || abs((float) $item->commission) > 0
                || abs((float) $item->jackpot) > 0
                || abs((float) $cost) > 0;

            if (!$matchCount && $hasNonZeroValues) {
                $item->bet = Common::numMul($item->bet, '-1', 8);
                $item->record_count = -(int) $item->record_count;
                $item->win_lose = Common::numMul($item->win_lose, '-1', 8);
                $item->rolling_chip = Common::numMul($item->rolling_chip, '-1', 8);
                $item->commission = Common::numMul($item->commission, '-1', 8);
                $item->jackpot = Common::numMul($item->jackpot, '-1', 8);
                $item->cost = Common::numMul($cost, '-1', 8);

                return $item;
            }

            return false;
        })->reject(fn ($value) => $value === false);

        $this->lastStats = [
            'mode' => 'correction',
            'written' => $corrections->count(),
            'reversed' => $reverseCorrections->count(),
        ];

        $this->line(sprintf(
            '  調帳寫入：新增或補差值 %d 筆，沖銷舊記錄 %d 筆',
            $corrections->count(),
            $reverseCorrections->count()
        ));

        $cutoffTime = self::getDailyCutoffTime();
        $this->persistSummaryRecords($corrections, $start, correction: 1, summaryTime: $cutoffTime);
        $this->persistSummaryRecords($reverseCorrections, $start, correction: 1, summaryTime: $cutoffTime);

        $this->line('  調帳完成');
    }

    /**
     * 讀取現有 `summary_bet_records` 並依 member × game 聚合，供調帳比對使用。
     */
    protected function fetchAggregatedOriginRecords(string $start): Collection
    {
        return SummaryBetRecord::from('summary_bet_records as sbr')
            ->join('members as m', 'm.id', 'sbr.member_id')
            ->join('agents as a', 'a.id', 'm.parent')
            ->where('sbr.vendor_id', $this->vendorId)
            ->where('sbr.data_time', $start)
            ->select([
                'sbr.member_id',
                'sbr.vendor_id',
                'sbr.game_id',
                'sbr.percentage',
                'a.customized_setting_group_id',
                DB::raw('SUM(sbr.bet) AS bet'),
                DB::raw('SUM(sbr.record_count) AS record_count'),
                DB::raw('SUM(sbr.win_lose) AS win_lose'),
                DB::raw('SUM(sbr.rolling_chip) AS rolling_chip'),
                DB::raw('SUM(sbr.commission) AS commission'),
                DB::raw('SUM(sbr.jackpot) AS jackpot'),
                DB::raw('SUM(sbr.cost) AS cost'),
            ])
            ->groupBy('sbr.member_id', 'sbr.game_id')
            ->get();
    }

    protected function persistSummaryRecords($records, string $start, int $correction = 0, string|false $summaryTime = false): void
    {
        $summaryTime = $summaryTime ?: $start;

        foreach ($records as $row) {
            $bet = $row->bet ?? 0;
            $recordCount = $row->record_count ?? 0;
            $winLose = $row->win_lose ?? 0;
            $rollingChip = $row->rolling_chip ?? 0;
            $commission = $row->commission ?? 0;
            $jackpot = $row->jackpot ?? 0;
            $cost = $this->calculateGameFee($winLose, $row->customized_setting_group_id);

            if (!$bet && !$recordCount && !$winLose && !$rollingChip && !$commission && !$jackpot) {
                continue;
            }

            $vJackpotRate = isset($this->vJackpotRates[$row->customized_setting_group_id])
                ? ($this->vJackpotRates[$row->customized_setting_group_id][$this->vendorId]
                    ?? $this->vJackpotRates[$row->customized_setting_group_id]['DEFAULT'])
                : 0.003;

            $record = new SummaryBetRecord;
            $record->summary_time = $summaryTime;
            $record->data_time = $start;
            $record->member_id = $row->member_id;
            $record->vendor_id = $this->vendorId;
            $record->game_id = $row->game_id ?: 0;
            $record->bet = $bet;
            $record->record_count = $recordCount;
            $record->win_lose = $winLose;
            $record->rolling_chip = $rollingChip;
            $record->commission = $commission;
            $record->jackpot = $jackpot;
            $percentage = $row->percentage ?? 0;
            $record->percentage = $percentage;
            $record->share_amount = Common::numDiv(
                Common::numMul($winLose, $percentage, 8),
                '100',
                4
            );
            $record->share_range = '';

            // v_jackpot：排除特定廠商與排除遊戲後，計算抽水並截取小數四位
            $isExcludedVendor = in_array($this->vendorId, [VendorIdEnum::HAOLONG->value, VendorIdEnum::VSPOKER->value]);
            $isExcludedGame = in_array($row->game_id, $this->excludedGameIds);

            if ($bet && !$isExcludedVendor && !$isExcludedGame) {
                $vJackpotRaw = Common::numMul(Common::numMul($bet, '10000', 4), $vJackpotRate, 4);
                $record->v_jackpot = Common::numDiv(Common::numFloor($vJackpotRaw, 0), '10000', 4);
            } else {
                $record->v_jackpot = 0;
            }

            $record->correction = $correction;
            $record->cost = $cost;

            $record->save();
        }
    }

    protected function clearPreviousSummaryRecords(string $summaryTime): void
    {
        SummaryBetRecord::where('vendor_id', $this->vendorId)
            ->where('summary_time', $summaryTime)
            ->where('correction', '!=', 1)
            ->delete();
    }

    protected function reportError(string $message): void
    {
        // 帶 vendor_id（bucket 由呼叫端寫進 $message）：值班收到告警才知道要補哪一格
        $fullMessage = '[SummaryBetRecord 錯誤][' . static::class . "][vendor_id:{$this->vendorId}] " . $message;
        Log::error($fullMessage);
        (new BotNotifyService)->send($fullMessage);
    }

    private function calculateGameFee(mixed $winLose, int $groupId): string|int
    {
        if (!isset($this->gameFeeRates[$groupId][$this->vendorId])) {
            return 0;
        }

        $raw = Common::numMul(
            Common::numMul($winLose, $this->gameFeeRates[$groupId][$this->vendorId], 8),
            '-1',
            8
        );

        // round half-up to scale 4 對齊 summary_bet_records.cost DECIMAL(18,4) 儲存精度，
        // 避免 bcmul truncate 與 DB round-half-up 不一致造成 0.0001 雜訊觸發偽 correction
        return Common::numCompare($raw, '0', 8) >= 0
            ? Common::numAdd($raw, '0.00005', 4)
            : Common::numSub($raw, '0.00005', 4);
    }

    private function line(string $message): void
    {
        $this->output->writeln($message);
    }
}
