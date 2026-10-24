<?php

declare(strict_types=1);

namespace App\Console\Commands\Vendor\PragmaticPlay;

use App\Enums\Vendor\VendorIdEnum;
use App\Helpers\Common;
use App\Vendors\Models\PpswBetRecord;
use App\Vendors\PPSW\BetRecordStatusEnum;
use Exception;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * 修復 multi-bet round 的 stuck bet_record。
 *
 * 找出同 (bet_id, username) 有多筆且至少 1 筆 status=SETTLE 的群組，
 * 將群組內 status=END_ROUND (1) 的紀錄更新成 SETTLE (3)，
 * 並補建對應的 TotalBetRecord（若尚未存在）。
 *
 * 使用方式：
 *   php artisan ppsw:backfill-stuck-bet-records 2026-04-01 2026-04-08
 *   php artisan ppsw:backfill-stuck-bet-records 2026-04-01 2026-04-08 --commit
 */
class BackfillStuckBetRecords extends Command
{
    protected $signature = 'ppsw:backfill-stuck-bet-records
                            {start-date : 起始日期 Y-m-d}
                            {end-date : 結束日期 Y-m-d}
                            {--commit : 實際寫入（不加此參數為 dry-run）}';

    protected $description = '修復 multi-bet round 的 stuck bet_record（status 1→3 + 補 TotalBetRecord）';

    public function handle(): int
    {
        $startDate = (string) $this->argument('start-date');
        $endDate = (string) $this->argument('end-date');
        $commit = (bool) $this->option('commit');

        try {
            $start = Carbon::createFromFormat('Y-m-d', $startDate)->startOfDay();
            $end = Carbon::createFromFormat('Y-m-d', $endDate)->endOfDay();
        } catch (Exception) {
            $this->error('❌ 日期格式錯誤，請使用 Y-m-d');

            return 1;
        }

        $this->info('========================================');
        $this->info('🔧 PPSW multi-bet stuck record 修復工具');
        $this->info('========================================');
        $this->info("查詢區間: {$start->toDateTimeString()} ~ {$end->toDateTimeString()}");
        $this->info('模式: ' . ($commit ? '✏️  COMMIT (實際寫入)' : '🧪 DRY-RUN (僅模擬)'));
        $this->newLine();

        $stuckRecords = $this->findStuckRecords($start, $end);

        if ($stuckRecords->isEmpty()) {
            $this->info('✅ 沒有需要修復的 stuck 紀錄');

            return 0;
        }

        $this->info(sprintf('🎯 找到 %d 筆需修復的 stuck 紀錄', $stuckRecords->count()));
        $this->newLine();

        $this->showStuckSummary($stuckRecords);
        $this->newLine();

        $before = $this->aggregateSnapshot($start, $end);

        if (!$commit) {
            $this->showPredictedAggregate($before, $stuckRecords);
            $this->newLine();
            $this->warn('🧪 Dry-run 結束，實際無變動。加 --commit 執行修復。');

            return 0;
        }

        if (!$this->confirm("即將修復 {$stuckRecords->count()} 筆紀錄，是否繼續？", false)) {
            $this->warn('使用者取消');

            return 0;
        }

        $stats = $this->applyFix($stuckRecords);

        $after = $this->aggregateSnapshot($start, $end);

        $this->newLine();
        $this->showExecutionResult($stats, $stuckRecords->count());
        $this->newLine();
        $this->showAggregateComparison($before, $after);

        return $stats['errors'] > 0 ? 1 : 0;
    }

    /**
     * 找出 Type A+C 的 stuck 紀錄：
     * 同 (bet_id, username) 有多筆且該群組內至少 1 筆 status=SETTLE 的群組中，
     * status=END_ROUND 的紀錄。
     *
     * @return Collection<int, PpswBetRecord>
     */
    private function findStuckRecords(Carbon $start, Carbon $end): Collection
    {
        $rows = DB::table('ppsw_bet_records')
            ->whereBetween('bet_time', [$start, $end])
            ->groupBy('bet_id', 'username')
            ->havingRaw('COUNT(*) > 1')
            ->havingRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) > 0', [BetRecordStatusEnum::SETTLE])
            ->select('bet_id', 'username')
            ->get();

        if ($rows->isEmpty()) {
            return PpswBetRecord::query()->whereRaw('1=0')->get();
        }

        $query = PpswBetRecord::query()
            ->where('status', BetRecordStatusEnum::END_ROUND)
            ->whereBetween('bet_time', [$start, $end])
            ->where(function ($outer) use ($rows) {
                foreach ($rows as $row) {
                    $outer->orWhere(function ($q) use ($row) {
                        $q->where('bet_id', $row->bet_id)
                            ->where('username', $row->username);
                    });
                }
            });

        return $query->orderBy('bet_time')->get();
    }

    private function showStuckSummary(Collection $records): void
    {
        $groups = $records->groupBy(fn ($r) => $r->bet_id . '|' . $r->username);

        $this->info(sprintf('共 %d 個受影響的 round（%d 筆 stuck 紀錄）', $groups->count(), $records->count()));
        $this->newLine();

        foreach ($groups as $key => $stuckInGroup) {
            [$betId, $username] = explode('|', (string) $key, 2);

            $allRecords = PpswBetRecord::query()
                ->where('bet_id', $betId)
                ->where('username', $username)
                ->orderBy('id')
                ->get();

            $tbrMap = DB::table('total_bet_records')
                ->where('vendor_id', VendorIdEnum::PPSW->value)
                ->whereIn('bet_record_id', $allRecords->pluck('id'))
                ->get()
                ->keyBy('bet_record_id');

            $stuckIds = $stuckInGroup->pluck('id')->flip();
            $primaryBr = $allRecords->first(fn ($r) => $r->status === BetRecordStatusEnum::SETTLE);

            $this->line(sprintf('🎯 round bet_id=%s, username=%s', $betId, $username));

            $rows = $allRecords->map(function ($r) use ($tbrMap, $stuckIds, $primaryBr) {
                $isPrimary = $primaryBr && $r->id === $primaryBr->id;
                $isStuck = $stuckIds->has($r->id);

                return [
                    $r->id . ($isPrimary ? ' ★primary' : ''),
                    $this->statusLabel($r->status),
                    number_format((float) $r->bet, 4),
                    number_format((float) $r->win, 4),
                    number_format((float) $r->win_lose, 4),
                    $tbrMap->has($r->id) ? 'Y' : '—',
                    $isStuck ? '→ SETTLE (3)' : '(不變)',
                    match (true) {
                        $isPrimary => '→ 累加 stuck 金額',
                        $isStuck && $tbrMap->has($r->id) => '→ 刪除',
                        default => '—',
                    },
                ];
            })->all();

            $this->table(
                ['id', 'status_now', 'bet', 'win', 'win_lose', 'TBR', 'status_after', 'TBR_after'],
                $rows
            );

            if (!$primaryBr) {
                $this->error('     ❌ 此 group 沒有 SETTLE primary BR，會被跳過');
                $this->newLine();

                continue;
            }

            $primaryTbr = $tbrMap->get($primaryBr->id);
            if (!$primaryTbr) {
                $this->error(sprintf('     ❌ Primary BR id=%d 沒有 TBR，無法 consolidate', $primaryBr->id));
                $this->newLine();

                continue;
            }

            // 計算累加後的 primary TBR
            $sumBet = '0';
            $sumWinLose = '0';
            foreach ($stuckInGroup as $s) {
                $sumBet = Common::numAdd($sumBet, (string) $s->bet, 4);
                $sumWinLose = Common::numAdd($sumWinLose, (string) $s->win_lose, 4);
            }
            $newPrimaryTbrBet = Common::numAdd((string) $primaryTbr->bet, $sumBet, 4);
            $newPrimaryTbrWinLose = Common::numAdd((string) $primaryTbr->win_lose, $sumWinLose, 4);

            $this->line('   修復 SQL：');
            $this->line(sprintf(
                '     UPDATE total_bet_records SET bet=%s, win_lose=%s, ... WHERE id=%d AND vendor_id=83;  -- primary',
                $newPrimaryTbrBet,
                $newPrimaryTbrWinLose,
                $primaryTbr->id
            ));
            foreach ($stuckInGroup as $r) {
                $stuckTbr = $tbrMap->get($r->id);
                if ($stuckTbr) {
                    $this->line(sprintf(
                        '     DELETE FROM transaction_log_total_bet_records WHERE total_bet_record_id=%d;',
                        $stuckTbr->id
                    ));
                    $this->line(sprintf(
                        '     DELETE FROM total_bet_records WHERE id=%d AND vendor_id=83;',
                        $stuckTbr->id
                    ));
                }

                $payoutValue = $r->payout_time
                    ? "'" . $r->payout_time->toDateTimeString() . "'"
                    : ($primaryBr->payout_time
                        ? "'" . $primaryBr->payout_time->toDateTimeString() . "'"
                        : 'NOW()');
                $this->line(sprintf(
                    '     UPDATE ppsw_bet_records SET status=3, payout_time=COALESCE(payout_time, %s) WHERE id=%d;',
                    $payoutValue,
                    $r->id
                ));
            }
            $this->newLine();
        }
    }

    /**
     * Dry-run 預期 aggregate 變化（consolidation 模式）：
     * - br 不變（只翻 status）
     * - tbr count 減少（每個 stuck-with-TBR 刪除一筆）
     * - tbr 金額：stuck-with-TBR 是搬移（金額不變），stuck-without-TBR 是淨增加
     *
     * @param  array{br_count:int,br_bet:string,br_win:string,br_winlose:string,tbr_count:int,tbr_bet:string,tbr_win:string,tbr_winlose:string}  $before
     */
    private function showPredictedAggregate(array $before, Collection $stuckRecords): void
    {
        $tbrExistIds = DB::table('total_bet_records')
            ->where('vendor_id', VendorIdEnum::PPSW->value)
            ->whereIn('bet_record_id', $stuckRecords->pluck('id'))
            ->pluck('bet_record_id')
            ->flip();

        $deletedCount = 0;
        $addBet = '0';
        $addWinlose = '0';

        foreach ($stuckRecords as $r) {
            if ($tbrExistIds->has($r->id)) {
                $deletedCount++;
                // 搬移：總額 0 變動
            } else {
                // 淨增加到 primary TBR
                $addBet = Common::numAdd($addBet, (string) $r->bet, 4);
                $addWinlose = Common::numAdd($addWinlose, (string) $r->win_lose, 4);
            }
        }

        $predictedTbrCount = $before['tbr_count'] - $deletedCount;
        $predictedTbrBet = Common::numAdd($before['tbr_bet'], $addBet, 4);
        $predictedTbrWinlose = Common::numAdd($before['tbr_winlose'], $addWinlose, 4);
        $predictedTbrWin = Common::numAdd($predictedTbrBet, $predictedTbrWinlose, 4);

        $signedAddBet = $addBet === '0' ? '0' : (str_starts_with($addBet, '-') ? $addBet : "+{$addBet}");
        $signedAddWinlose = $addWinlose === '0' ? '0' : (str_starts_with($addWinlose, '-') ? $addWinlose : "+{$addWinlose}");
        $addWin = Common::numAdd($addBet, $addWinlose, 4);
        $signedAddWin = $addWin === '0' ? '0' : (str_starts_with($addWin, '-') ? $addWin : "+{$addWin}");

        $this->info('========== 預期 Aggregate 變化（Dry-run）==========');
        $this->table(
            ['指標', '當前', '預期', '差異'],
            [
                ['br count', $before['br_count'], $before['br_count'], 0],
                ['br bet', $before['br_bet'], $before['br_bet'], '0.0000'],
                ['br win', $before['br_win'], $before['br_win'], '0.0000'],
                ['br win_lose', $before['br_winlose'], $before['br_winlose'], '0.0000'],
                ['tbr count', $before['tbr_count'], $predictedTbrCount, "-{$deletedCount}"],
                ['tbr bet', $before['tbr_bet'], $predictedTbrBet, $signedAddBet],
                ['tbr win', $before['tbr_win'], $predictedTbrWin, $signedAddWin],
                ['tbr win_lose', $before['tbr_winlose'], $predictedTbrWinlose, $signedAddWinlose],
            ]
        );
        $this->line('說明：');
        $this->line('  - br 金額/筆數不變（只翻 status）');
        $this->line(sprintf('  - tbr 將刪除 %d 筆 stuck TBR（金額搬移到 primary TBR）', $deletedCount));
        $this->line('  - tbr 金額淨增加（來自無 TBR 的 stuck BR，amount 補進 primary TBR）');
        $this->line('  - 完成後請執行 SBR rebuild：php artisan summary_bet_record --vendor=83 --date=... --hour=...');
    }

    private function statusLabel(int $status): string
    {
        return match ($status) {
            BetRecordStatusEnum::BET => 'BET (0)',
            BetRecordStatusEnum::END_ROUND => 'END_ROUND (1)',
            BetRecordStatusEnum::REFUND => 'REFUND (2)',
            BetRecordStatusEnum::SETTLE => 'SETTLE (3)',
            default => "UNKNOWN ({$status})",
        };
    }

    /**
     * 取得區間 br aggregate 與 TBR aggregate 的快照。
     *
     * @return array{br_count:int,br_bet:string,br_win:string,br_winlose:string,tbr_count:int,tbr_bet:string,tbr_win:string,tbr_winlose:string}
     */
    private function aggregateSnapshot(Carbon $start, Carbon $end): array
    {
        $brStats = DB::table('ppsw_bet_records')
            ->whereBetween('bet_time', [$start, $end])
            ->selectRaw('
                COUNT(*) as cnt,
                COALESCE(SUM(bet), 0) as total_bet,
                COALESCE(SUM(win), 0) as total_win,
                COALESCE(SUM(win_lose), 0) as total_winlose
            ')
            ->first();

        $tbrStats = DB::table('total_bet_records as tbr')
            ->join('ppsw_bet_records as br', 'tbr.bet_record_id', '=', 'br.id')
            ->where('tbr.vendor_id', VendorIdEnum::PPSW->value)
            ->whereBetween('br.bet_time', [$start, $end])
            ->selectRaw('
                COUNT(*) as cnt,
                COALESCE(SUM(tbr.bet), 0) as total_bet,
                COALESCE(SUM(tbr.win_lose) + SUM(tbr.bet), 0) as total_win,
                COALESCE(SUM(tbr.win_lose), 0) as total_winlose
            ')
            ->first();

        return [
            'br_count' => (int) ($brStats->cnt ?? 0),
            'br_bet' => (string) ($brStats->total_bet ?? '0'),
            'br_win' => (string) ($brStats->total_win ?? '0'),
            'br_winlose' => (string) ($brStats->total_winlose ?? '0'),
            'tbr_count' => (int) ($tbrStats->cnt ?? 0),
            'tbr_bet' => (string) ($tbrStats->total_bet ?? '0'),
            'tbr_win' => (string) ($tbrStats->total_win ?? '0'),
            'tbr_winlose' => (string) ($tbrStats->total_winlose ?? '0'),
        ];
    }

    /**
     * Consolidation 模式：1 round = 1 TBR
     *
     * 對每個 multi-bet group：
     *   - 找 primary BR（status=SETTLE, 最小 id）並讀取其 primary TBR
     *   - 把所有 stuck BR 的 bet/valid_bet/win_lose/bonus/jackpot/rolling_chip 累加到 primary TBR
     *   - 刪除每個 stuck BR 的 TBR（含 transaction_log_total_bet_records 關聯）
     *   - 翻轉 stuck BR.status 1→3
     *
     * @return array{fixed:int,tbr_merged:int,tbr_deleted:int,errors:int,affected_hours:array<string,bool>}
     */
    private function applyFix(Collection $records): array
    {
        $stats = [
            'fixed' => 0,
            'tbr_merged' => 0,
            'tbr_deleted' => 0,
            'errors' => 0,
            'affected_hours' => [],
        ];

        $groups = $records->groupBy(fn ($r) => $r->bet_id . '|' . $r->username);
        $bar = $this->output->createProgressBar($groups->count());
        $bar->start();

        foreach ($groups as $key => $stuckInGroup) {
            [$betId, $username] = explode('|', (string) $key, 2);

            try {
                DB::beginTransaction();

                $primaryBr = PpswBetRecord::query()
                    ->where('bet_id', $betId)
                    ->where('username', $username)
                    ->where('status', BetRecordStatusEnum::SETTLE)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->first();

                if (!$primaryBr) {
                    throw new Exception("找不到 primary BR (status=SETTLE) for bet_id={$betId}, username={$username}");
                }

                $primaryTbr = DB::table('total_bet_records')
                    ->where('vendor_id', VendorIdEnum::PPSW->value)
                    ->where('bet_record_id', $primaryBr->id)
                    ->lockForUpdate()
                    ->first();

                if (!$primaryTbr) {
                    throw new Exception("primary BR id={$primaryBr->id} 沒有對應 TBR，無法 consolidate");
                }

                $sumBet = '0';
                $sumValidBet = '0';
                $sumWinLose = '0';
                $sumBonus = '0';
                $sumJackpot = '0';
                $sumRollingChip = '0';

                foreach ($stuckInGroup as $stuck) {
                    $sumBet = Common::numAdd($sumBet, (string) $stuck->bet, 4);
                    $sumValidBet = Common::numAdd($sumValidBet, (string) $stuck->valid_bet, 4);
                    $sumWinLose = Common::numAdd($sumWinLose, (string) $stuck->win_lose, 4);
                    $sumBonus = Common::numAdd($sumBonus, (string) $stuck->bonus, 4);
                    $sumJackpot = Common::numAdd($sumJackpot, (string) $stuck->jackpot, 4);
                    $sumRollingChip = Common::numAdd($sumRollingChip, (string) $stuck->rolling_chip, 4);
                }

                DB::table('total_bet_records')
                    ->where('id', $primaryTbr->id)
                    ->where('vendor_id', VendorIdEnum::PPSW->value)
                    ->update([
                        'bet' => Common::numAdd((string) $primaryTbr->bet, $sumBet, 4),
                        'valid_bet' => Common::numAdd((string) $primaryTbr->valid_bet, $sumValidBet, 4),
                        'win_lose' => Common::numAdd((string) $primaryTbr->win_lose, $sumWinLose, 4),
                        'bonus' => Common::numAdd((string) $primaryTbr->bonus, $sumBonus, 4),
                        'jackpot' => Common::numAdd((string) $primaryTbr->jackpot, $sumJackpot, 4),
                        'rolling_chip' => Common::numAdd((string) $primaryTbr->rolling_chip, $sumRollingChip, 4),
                        'updated_at' => now(),
                    ]);

                $stats['tbr_merged']++;

                foreach ($stuckInGroup as $stuck) {
                    $stuckTbrId = DB::table('total_bet_records')
                        ->where('vendor_id', VendorIdEnum::PPSW->value)
                        ->where('bet_record_id', $stuck->id)
                        ->value('id');

                    if ($stuckTbrId) {
                        DB::table('transaction_log_total_bet_records')
                            ->where('total_bet_record_id', $stuckTbrId)
                            ->delete();
                        DB::table('total_bet_records')
                            ->where('id', $stuckTbrId)
                            ->where('vendor_id', VendorIdEnum::PPSW->value)
                            ->delete();
                        $stats['tbr_deleted']++;
                    }

                    $payoutTime = $stuck->payout_time ?? $primaryBr->payout_time ?? $stuck->updated_at ?? now();
                    $stuck->update([
                        'status' => BetRecordStatusEnum::SETTLE,
                        'payout_time' => $payoutTime,
                    ]);

                    $hourKey = Carbon::parse($payoutTime)->format('Y-m-d H:00');
                    $stats['affected_hours'][$hourKey] = true;
                    $stats['fixed']++;
                }

                DB::commit();
            } catch (Throwable $e) {
                DB::rollBack();
                $stats['errors']++;
                Log::error('Backfill consolidate group failed', [
                    'bet_id' => $betId,
                    'username' => $username,
                    'error' => $e->getMessage(),
                    'trace' => Common::formatTrace($e),
                ]);
                $this->newLine();
                $this->error(sprintf('❌ bet_id=%s 合併失敗：%s', $betId, $e->getMessage()));
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine();

        return $stats;
    }

    /**
     * @param  array{fixed:int,tbr_merged:int,tbr_deleted:int,errors:int,affected_hours:array<string,bool>}  $stats
     */
    private function showExecutionResult(array $stats, int $total): void
    {
        $this->info('========== 執行結果 ==========');
        $this->info("成功翻轉 stuck BR: {$stats['fixed']} / {$total}");
        $this->info("Primary TBR 累加: {$stats['tbr_merged']} 筆");
        $this->info("Stuck TBR 刪除: {$stats['tbr_deleted']} 筆");
        if ($stats['errors'] > 0) {
            $this->error("失敗: {$stats['errors']}");
        }

        if (!empty($stats['affected_hours'])) {
            $hours = array_keys($stats['affected_hours']);
            sort($hours);
            $this->newLine();
            $this->warn('⚠️  受影響的小時 slot（需重建 SBR）：');
            foreach ($hours as $h) {
                $this->line("  - {$h}");
            }
            $this->newLine();
            $this->line('💡 重建 SBR 命令：');
            foreach ($hours as $h) {
                [$d, $hh] = explode(' ', $h);
                $hh = (int) explode(':', $hh)[0];
                $this->line(sprintf('   php artisan summary_bet_record --vendor=83 --date=%s --hour=%d', $d, $hh));
            }
        }
    }

    /**
     * @param  array{br_count:int,br_bet:string,br_win:string,br_winlose:string,tbr_count:int,tbr_bet:string,tbr_win:string,tbr_winlose:string}  $before
     * @param  array{br_count:int,br_bet:string,br_win:string,br_winlose:string,tbr_count:int,tbr_bet:string,tbr_win:string,tbr_winlose:string}  $after
     */
    private function showAggregateComparison(array $before, array $after): void
    {
        $this->info('========== Aggregate 對照 ==========');
        $this->table(
            ['指標', 'Before', 'After', 'Diff'],
            [
                ['br count', $before['br_count'], $after['br_count'], $after['br_count'] - $before['br_count']],
                ['br bet', $before['br_bet'], $after['br_bet'], Common::numSub($after['br_bet'], $before['br_bet'], 4)],
                ['br win', $before['br_win'], $after['br_win'], Common::numSub($after['br_win'], $before['br_win'], 4)],
                ['br win_lose', $before['br_winlose'], $after['br_winlose'], Common::numSub($after['br_winlose'], $before['br_winlose'], 4)],
                ['tbr count', $before['tbr_count'], $after['tbr_count'], $after['tbr_count'] - $before['tbr_count']],
                ['tbr bet', $before['tbr_bet'], $after['tbr_bet'], Common::numSub($after['tbr_bet'], $before['tbr_bet'], 4)],
                ['tbr win', $before['tbr_win'], $after['tbr_win'], Common::numSub($after['tbr_win'], $before['tbr_win'], 4)],
                ['tbr win_lose', $before['tbr_winlose'], $after['tbr_winlose'], Common::numSub($after['tbr_winlose'], $before['tbr_winlose'], 4)],
            ]
        );

        $this->line('預期: br 金額皆 0 變動（只動 status）；tbr 應增加 N 筆與對應金額。');
    }
}
