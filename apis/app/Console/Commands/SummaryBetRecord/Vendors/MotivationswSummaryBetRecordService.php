<?php

declare(strict_types=1);

namespace App\Console\Commands\SummaryBetRecord\Vendors;

use App\Console\Commands\SummaryBetRecord\SummaryBetRecordService;
use App\Models\TotalBetRecord;
use Illuminate\Database\Eloquent\Collection;

/**
 * MOTIVATIONSW (vendor_id=125) 彙總服務。
 *
 * 與標準 `SummaryBetRecordService` 的差異：
 * member_id 來源不直接信任 `total_bet_records.member_id`，而是透過
 * `motivationsw_bet_records.bet_record_id → motivationsw_accounts.username → member_id`
 * 反查取得，與舊系統 `RD-Studio-01` 的 `App\Services\SummaryBetRecords\MotivSW` 行為一致。
 *
 * 效果：`total_bet_records` 中找不到對應 vendor bet record 的孤兒列會被排除，
 * 避免歷史殘留資料汙染彙總結果。
 */
class MotivationswSummaryBetRecordService extends SummaryBetRecordService
{
    protected function buildAggregateQuery(string $start, string $end): Collection
    {
        $timeField = 'total_bet_records.' . $this->timeField;

        return TotalBetRecord::query()
            ->join('motivationsw_bet_records as bet', 'bet.id', '=', 'total_bet_records.bet_record_id')
            ->join('motivationsw_accounts as acc', 'acc.username', '=', 'bet.username')
            ->join('members as m', 'm.id', '=', 'acc.member_id')
            ->join('agents as a', 'a.id', '=', 'm.parent')
            ->leftJoin('games as g', 'g.id', '=', 'total_bet_records.game_id')
            ->where('total_bet_records.vendor_id', $this->vendorId)
            ->where($timeField, '>=', $start)
            ->where($timeField, '<', $end)
            ->select($this->aggregateSelectColumns())
            ->groupBy(['m.id', 'g.id'])
            ->get();
    }
}
