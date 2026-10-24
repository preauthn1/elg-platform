<?php

declare(strict_types=1);

namespace App\Console\Commands\Vendor\PragmaticPlay\Services;

use Illuminate\Support\Facades\DB;

final class CompareGameRoundsWithBetRecordsService
{
    public const TABLE_GAME_ROUNDS = 'ppsw_game_rounds';

    public const TABLE_BET_RECORDS = 'ppsw_bet_records';

    /**
     * @return list<string> missing table names
     */
    public function missingTables(): array
    {
        $tables = [self::TABLE_GAME_ROUNDS, self::TABLE_BET_RECORDS];
        $missing = [];

        foreach ($tables as $table) {
            if (!DB::getSchemaBuilder()->hasTable($table)) {
                $missing[] = $table;
            }
        }

        return $missing;
    }

    /**
     * Summary used by displayTableSummary().
     */
    public function getTableSummary(string $startDate, string $endDate): array
    {
        $gameRoundsStats = DB::table(self::TABLE_GAME_ROUNDS . ' as gr')
            ->join(self::TABLE_BET_RECORDS . ' as br', 'gr.playSessionID', '=', 'br.bet_id')
            ->where('gr.endDate', '>=', $startDate)
            ->where('gr.endDate', '<', $endDate)
            ->selectRaw('
                COUNT(*) as total_count,
                COALESCE(SUM(gr.bet), 0) as total_bet,
                COALESCE(SUM(gr.win), 0) as total_win,
                COALESCE(SUM(gr.win) - SUM(gr.bet), 0) as total_profit
            ')
            ->first();

        $betRecordsStats = DB::table(self::TABLE_BET_RECORDS . ' as br')
            ->where('br.payout_time', '>=', $startDate)
            ->where('br.payout_time', '<', $endDate)
            ->selectRaw('
                COUNT(*) as total_count,
                COALESCE(SUM(br.bet), 0) as total_bet,
                COALESCE(SUM(br.win), 0) as total_win,
                COALESCE(SUM(br.win) - SUM(br.bet), 0) as total_profit
            ')
            ->first();

        return [
            'game_rounds' => [
                'total_count' => (float) ($gameRoundsStats->total_count ?? 0),
                'total_bet' => (float) ($gameRoundsStats->total_bet ?? 0),
                'total_win' => (float) ($gameRoundsStats->total_win ?? 0),
                'total_profit' => (float) ($gameRoundsStats->total_profit ?? 0),
            ],
            'bet_records' => [
                'total_count' => (float) ($betRecordsStats->total_count ?? 0),
                'total_bet' => (float) ($betRecordsStats->total_bet ?? 0),
                'total_win' => (float) ($betRecordsStats->total_win ?? 0),
                'total_profit' => (float) ($betRecordsStats->total_profit ?? 0),
            ],
        ];
    }

    /**
     * @return list<object>
     */
    public function findOnlyInGameRounds(string $startDate, string $endDate): array
    {
        return DB::table(self::TABLE_GAME_ROUNDS . ' as gr')
            ->leftJoin(self::TABLE_BET_RECORDS . ' as br', 'gr.playSessionID', '=', 'br.bet_id')
            ->where('gr.endDate', '>=', $startDate)
            ->where('gr.endDate', '<', $endDate)
            ->whereNull('br.bet_id')
            ->where('gr.parentSessionID', '=', 'null')
            ->select(
                'gr.playSessionID',
                'gr.parentSessionID',
                'gr.extPlayerID',
                'gr.gameID',
                'gr.startDate',
                'gr.endDate',
                'gr.bet',
                'gr.win'
            )
            ->get()
            ->toArray();
    }

    /**
     * @return list<object>
     */
    public function findOnlyInBetRecords(string $startDate, string $endDate): array
    {
        return DB::table(self::TABLE_BET_RECORDS . ' as br')
            ->leftJoin(self::TABLE_GAME_ROUNDS . ' as gr', function ($join) use ($startDate, $endDate) {
                $join->on('br.bet_id', '=', 'gr.playSessionID')
                    ->where('gr.endDate', '>=', $startDate)
                    ->where('gr.endDate', '<', $endDate);
            })
            ->where('br.payout_time', '>=', $startDate)
            ->where('br.payout_time', '<', $endDate)
            ->whereNull('gr.playSessionID')
            ->select(
                'br.bet_id',
                'br.member_id',
                'br.game_code',
                'br.bet_time',
                'br.payout_time',
                'br.bet',
                'br.win'
            )
            ->get()
            ->toArray();
    }

    /**
     * @return array{records:list<object>, total_bet_diff:float, total_win_diff:float}
     */
    public function findAmountDifferences(string $startDate, string $endDate): array
    {
        $baseQuery = DB::table(self::TABLE_GAME_ROUNDS . ' as gr')
            ->join(self::TABLE_BET_RECORDS . ' as br', 'gr.playSessionID', '=', 'br.bet_id')
            ->where('gr.endDate', '>=', $startDate)
            ->where('gr.endDate', '<', $endDate)
            ->whereRaw('(gr.bet != br.bet OR gr.win != br.win)');

        $records = (clone $baseQuery)
            ->select(
                'gr.playSessionID',
                'gr.parentSessionID',
                'gr.extPlayerID',
                'gr.gameID',
                'gr.startDate',
                'gr.endDate',
                'gr.bet as gr_bet',
                'gr.win as gr_win',
                'gr.currency as gr_currency',
                'br.bet as br_bet',
                'br.win as br_win',
                'br.payout_time'
            )
            ->get()
            ->toArray();

        $diffAgg = (clone $baseQuery)
            ->selectRaw('COALESCE(SUM(ABS(gr.bet - br.bet)), 0) as total_bet_diff, COALESCE(SUM(ABS(gr.win - br.win)), 0) as total_win_diff')
            ->first();

        return [
            'records' => $records,
            'total_bet_diff' => (float) ($diffAgg->total_bet_diff ?? 0),
            'total_win_diff' => (float) ($diffAgg->total_win_diff ?? 0),
        ];
    }

    /**
     * @return list<object>
     */
    public function findBetRecordsWithSubRounds(string $startDate, string $endDate): array
    {
        return DB::table(self::TABLE_BET_RECORDS . ' as br')
            ->join(self::TABLE_GAME_ROUNDS . ' as gr', 'br.bet_id', '=', 'gr.playSessionID')
            ->where('gr.endDate', '>=', $startDate)
            ->where('gr.endDate', '<', $endDate)
            ->where('gr.parentSessionID', '!=', 'null')
            ->select(
                'br.bet_id',
                'br.member_id',
                'br.game_code',
                'br.bet_time',
                'br.payout_time',
                'br.bet',
                'br.win',
                'gr.parentSessionID',
                'gr.gameID',
                'gr.endDate'
            )
            ->orderBy('br.bet', 'desc')
            ->get()
            ->toArray();
    }

    /**
     * @return array{cross_day:array{count:int,bet_sum:float,win_sum:float,records:list<object>},missing_game_round:array{count:int,bet_sum:float,win_sum:float,records:list<object>},missing_bet_record:array{count:int,bet_sum:float,win_sum:float,records:list<object>},reverse_cross_day:array{count:int,bet_sum:float,win_sum:float,records:list<object>},summary:array{game_rounds_total_bet:float,bet_records_total_bet:float,bet_diff:float,game_rounds_total_win:float,bet_records_total_win:float,win_diff:float}}
     */
    public function analyzeSummaryDifferences(string $startDate, string $endDate): array
    {
        $crossDay = $this->fetchRecordsWithBetWinSums(
            DB::table(self::TABLE_BET_RECORDS . ' as br')
                ->join(self::TABLE_GAME_ROUNDS . ' as gr', 'br.bet_id', '=', 'gr.playSessionID')
                ->where('br.payout_time', '>=', $startDate)
                ->where('br.payout_time', '<', $endDate)
                ->where(function ($q) use ($startDate, $endDate) {
                    $q->where('gr.endDate', '<', $startDate)
                        ->orWhere('gr.endDate', '>=', $endDate);
                })
                ->select(
                    'br.bet_id',
                    'br.member_id',
                    'br.game_code',
                    'br.bet_time',
                    'br.payout_time',
                    'br.bet as br_bet',
                    'br.win as br_win',
                    'gr.endDate as gr_endDate',
                    'gr.bet as gr_bet',
                    'gr.win as gr_win',
                    'gr.parentSessionID'
                )
                ->orderBy('br.bet', 'desc'),
            'br_bet',
            'br_win'
        );

        $missingGameRound = $this->fetchRecordsWithBetWinSums(
            DB::table(self::TABLE_BET_RECORDS . ' as br')
                ->leftJoin(self::TABLE_GAME_ROUNDS . ' as gr', 'br.bet_id', '=', 'gr.playSessionID')
                ->where('br.payout_time', '>=', $startDate)
                ->where('br.payout_time', '<', $endDate)
                ->whereNull('gr.playSessionID')
                ->select(
                    'br.bet_id',
                    'br.member_id',
                    'br.game_code',
                    'br.bet_time',
                    'br.payout_time',
                    'br.bet as br_bet',
                    'br.win as br_win'
                )
                ->orderBy('br.bet', 'desc'),
            'br_bet',
            'br_win'
        );

        $missingBetRecord = $this->fetchRecordsWithBetWinSums(
            DB::table(self::TABLE_GAME_ROUNDS . ' as gr')
                ->leftJoin(self::TABLE_BET_RECORDS . ' as br', 'gr.playSessionID', '=', 'br.bet_id')
                ->where('gr.endDate', '>=', $startDate)
                ->where('gr.endDate', '<', $endDate)
                ->whereNull('br.bet_id')
                ->select(
                    'gr.playSessionID',
                    'gr.extPlayerID',
                    'gr.gameID',
                    'gr.startDate',
                    'gr.endDate',
                    'gr.bet as gr_bet',
                    'gr.win as gr_win',
                    'gr.parentSessionID'
                )
                ->orderBy('gr.bet', 'desc'),
            'gr_bet',
            'gr_win'
        );

        $reverseCrossDay = $this->fetchRecordsWithBetWinSums(
            DB::table(self::TABLE_GAME_ROUNDS . ' as gr')
                ->join(self::TABLE_BET_RECORDS . ' as br', 'gr.playSessionID', '=', 'br.bet_id')
                ->where('gr.endDate', '>=', $startDate)
                ->where('gr.endDate', '<', $endDate)
                ->where(function ($q) use ($startDate, $endDate) {
                    $q->whereNull('br.payout_time')
                        ->orWhere('br.payout_time', '<', $startDate)
                        ->orWhere('br.payout_time', '>=', $endDate);
                })
                ->select(
                    'br.bet_id',
                    'br.member_id',
                    'br.game_code',
                    'br.bet_time',
                    'br.payout_time',
                    'br.bet as br_bet',
                    'br.win as br_win',
                    'gr.endDate as gr_endDate',
                    'gr.bet as gr_bet',
                    'gr.win as gr_win',
                    'gr.parentSessionID'
                )
                ->orderBy('gr.bet', 'desc'),
            'gr_bet',
            'gr_win'
        );

        $gameRoundsStats = DB::table(self::TABLE_GAME_ROUNDS . ' as gr')
            ->join(self::TABLE_BET_RECORDS . ' as br', 'gr.playSessionID', '=', 'br.bet_id')
            ->where('gr.endDate', '>=', $startDate)
            ->where('gr.endDate', '<', $endDate)
            ->selectRaw('COALESCE(SUM(gr.bet), 0) as total_bet, COALESCE(SUM(gr.win), 0) as total_win')
            ->first();

        $betRecordsStats = DB::table(self::TABLE_BET_RECORDS . ' as br')
            ->where('br.payout_time', '>=', $startDate)
            ->where('br.payout_time', '<', $endDate)
            ->selectRaw('COALESCE(SUM(br.bet), 0) as total_bet, COALESCE(SUM(br.win), 0) as total_win')
            ->first();

        $summaryBetDiff = abs(((float) ($gameRoundsStats->total_bet ?? 0)) - ((float) ($betRecordsStats->total_bet ?? 0)));
        $summaryWinDiff = abs(((float) ($gameRoundsStats->total_win ?? 0)) - ((float) ($betRecordsStats->total_win ?? 0)));

        return [
            'summary' => [
                'game_rounds_total_bet' => (float) ($gameRoundsStats->total_bet ?? 0),
                'bet_records_total_bet' => (float) ($betRecordsStats->total_bet ?? 0),
                'bet_diff' => $summaryBetDiff,
                'game_rounds_total_win' => (float) ($gameRoundsStats->total_win ?? 0),
                'bet_records_total_win' => (float) ($betRecordsStats->total_win ?? 0),
                'win_diff' => $summaryWinDiff,
            ],
            'cross_day' => $crossDay,
            'missing_game_round' => $missingGameRound,
            'missing_bet_record' => $missingBetRecord,
            'reverse_cross_day' => $reverseCrossDay,
        ];
    }

    /**
     * @return array{records:list<object>, bet_sum:float, win_sum:float, count:int}
     */
    private function fetchRecordsWithBetWinSums($query, string $betField, string $winField): array
    {
        $records = $query->get()->toArray();

        return [
            'records' => $records,
            'count' => count($records),
            'bet_sum' => $this->sumField($records, $betField),
            'win_sum' => $this->sumField($records, $winField),
        ];
    }

    /**
     * @param  list<object>  $records
     */
    private function sumField(array $records, string $field): float
    {
        $sum = 0.0;
        foreach ($records as $record) {
            /** @var mixed $value */
            $value = $record->{$field} ?? 0;
            $sum += (float) $value;
        }

        return $sum;
    }
}
