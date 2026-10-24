<?php

declare(strict_types=1);

namespace App\Console\Commands\Vendor\PragmaticPlay;

use App\Helpers\Common;
use App\Services\TotalBetRecordService;
use App\Vendors\Models\PpswBetRecord;
use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * 修正 ppsw_bet_records 因 K8S POD 重啟導致的資料異常
 *
 * 自動處理兩種情況：
 *   (A) status=1 但 total_bet_records 已存在 → 僅更新 status=3
 *   (B) status=1 且 total_bet_records 缺失   → 校對 ppsw_game_rounds 真值後補寫 total_bet_records 再更新 status=3
 *
 * (B) 三道驗證，未通過直接 skip + log warning：
 *   1. ppsw_game_rounds 必須有對應 (playSessionID=bet_id, extPlayerID=username) — 否則 PP 端根本沒這 round
 *   2. ppsw_game_rounds.status 必須為 'F' (Finished) — 否則 PP 端尚未確認結算
 *   3. 同 (bet_id, username) 在 ppsw_bet_records 必須只有 1 筆 — multi-bet round 請改用 ppsw:backfill-stuck-bet-records
 *
 * 通過驗證後，會以 ppsw_game_rounds.win 校正 ppsw_bet_records.win / win_lose 再建 TBR，
 * 避免因 result callback 漏進造成 win=0 而灌入錯誤的「玩家全輸」TBR。
 *
 * 使用方式：
 *   php artisan ppsw:fix-records 2026-04-02
 *   php artisan ppsw:fix-records 2026-04-02 --dry-run
 */
class FixRecords extends Command
{
    private const int VENDOR_ID = 83;

    private const bool IS_MULTIPLE_WALLET = true;

    /** (A) 批次更新 status=3，純 SQL 操作可較大 */
    private const int STUCK_CHUNK_SIZE = 500;

    /** (B) 逐筆呼叫 TotalBetRecordService，需較小 chunk */
    private const int MISSING_CHUNK_SIZE = 100;

    /** Dry-run 預覽筆數 */
    private const int DRY_RUN_PREVIEW_LIMIT = 50;

    protected $signature = 'ppsw:fix-records
                            {date : 查詢日期 Y-m-d，依 ppsw_bet_records.payout_time}
                            {--dry-run : 僅列出受影響記錄，不實際修正}';

    protected $description = '修正 ppsw_bet_records 因 POD 重啟導致的 status=1 卡住或 total_bet_records 缺失問題';

    public function handle(): int
    {
        $dateStr = (string) $this->argument('date');
        $dryRun = (bool) $this->option('dry-run');

        try {
            $date = Carbon::createFromFormat('Y-m-d', $dateStr)->startOfDay();
        } catch (Exception) {
            $this->error('❌ 日期格式錯誤，請使用 Y-m-d 格式');

            return 1;
        }

        $start = $date->copy()->format('Y-m-d H:i:s');
        $end = $date->copy()->addDay()->format('Y-m-d H:i:s');

        $this->info('========================================');
        $this->info('🔧 修正 ppsw_bet_records 資料異常');
        $this->info('========================================');
        $this->info('日期：' . $dateStr . '（payout_time 範圍）');
        $this->info('模式：' . ($dryRun ? 'Dry-run（僅列出，不修正）' : '實際修正'));
        $this->info('========================================');
        $this->newLine();

        // ────────────────────────────────────────────────
        // 情況 (A)：status=1 但 total_bet_records 已存在
        // ────────────────────────────────────────────────
        $stuckQuery = PpswBetRecord::query()
            ->join('total_bet_records as tbr', function ($join) {
                $join->on('tbr.bet_record_id', '=', 'ppsw_bet_records.id')
                    ->where('tbr.vendor_id', '=', self::VENDOR_ID);
            })
            ->where('ppsw_bet_records.status', 1)
            ->whereBetween('ppsw_bet_records.payout_time', [$start, $end])
            ->select('ppsw_bet_records.*')
            ->orderBy('ppsw_bet_records.id');

        $stuckTotal = (clone $stuckQuery)->count();

        // ────────────────────────────────────────────────
        // 情況 (B)：status=1 且 total_bet_records 缺失
        // ────────────────────────────────────────────────
        $missingQuery = PpswBetRecord::query()
            ->with('member')
            ->leftJoin('total_bet_records as tbr', function ($join) {
                $join->on('tbr.bet_record_id', '=', 'ppsw_bet_records.id')
                    ->where('tbr.vendor_id', '=', self::VENDOR_ID);
            })
            ->whereNull('tbr.id')
            ->where('ppsw_bet_records.status', 1)
            ->whereBetween('ppsw_bet_records.payout_time', [$start, $end])
            ->select('ppsw_bet_records.*')
            ->orderBy('ppsw_bet_records.id');

        $missingTotal = (clone $missingQuery)->count();

        $this->info("(A) status=1 卡住（tbr 已存在）：{$stuckTotal} 筆");
        $this->info("(B) status=1 缺失（tbr 不存在）：{$missingTotal} 筆");
        $this->newLine();

        if ($stuckTotal === 0 && $missingTotal === 0) {
            $this->info('✅ 無需修正，資料正常。');

            return 0;
        }

        if ($dryRun) {
            $this->warn('Dry-run 模式：僅列出，不執行修正。');

            if ($stuckTotal > 0) {
                $this->line('');
                $this->line('── (A) 卡住的記錄（前 ' . self::DRY_RUN_PREVIEW_LIMIT . ' 筆）──');
                $rows = (clone $stuckQuery)->limit(self::DRY_RUN_PREVIEW_LIMIT)->get();
                $this->table(
                    ['BR.id', 'BetID', 'MemberID', 'GameCode', 'Bet', 'WinLose', 'PayoutTime'],
                    $rows->map(fn ($r) => [$r->id, $r->bet_id, $r->member_id, $r->game_code, $r->bet, $r->win_lose, $r->payout_time])->toArray()
                );
                if ($stuckTotal > self::DRY_RUN_PREVIEW_LIMIT) {
                    $this->line('（僅顯示前 ' . self::DRY_RUN_PREVIEW_LIMIT . ' 筆，共 ' . $stuckTotal . ' 筆）');
                }
            }

            if ($missingTotal > 0) {
                $this->line('');
                $this->line('── (B) 缺失 tbr 的記錄（前 ' . self::DRY_RUN_PREVIEW_LIMIT . ' 筆）──');
                $rows = (clone $missingQuery)->limit(self::DRY_RUN_PREVIEW_LIMIT)->get();
                $this->table(
                    ['BR.id', 'BetID', 'MemberID', 'GameCode', 'Bet', 'WinLose', 'PayoutTime'],
                    $rows->map(fn ($r) => [$r->id, $r->bet_id, $r->member_id, $r->game_code, $r->bet, $r->win_lose, $r->payout_time])->toArray()
                );
                if ($missingTotal > self::DRY_RUN_PREVIEW_LIMIT) {
                    $this->line('（僅顯示前 ' . self::DRY_RUN_PREVIEW_LIMIT . ' 筆，共 ' . $missingTotal . ' 筆）');
                }
            }

            return 0;
        }

        if (!$this->confirm("確認修正以上 (A){$stuckTotal} + (B){$missingTotal} 筆記錄？")) {
            $this->warn('已取消。');

            return 0;
        }

        $this->newLine();
        $totalFail = 0;

        // ────────────────────────────────────────────────
        // 執行 (A)：批次更新 status=3
        // ────────────────────────────────────────────────
        if ($stuckTotal > 0) {
            $this->line("▶ 修正 (A) {$stuckTotal} 筆卡住記錄...");
            $stuckSuccess = 0;
            $stuckFail = 0;
            $bar = $this->output->createProgressBar($stuckTotal);
            $bar->start();

            $stuckQuery->chunkById(self::STUCK_CHUNK_SIZE, function ($records) use (&$stuckSuccess, &$stuckFail, $bar) {
                $ids = $records->pluck('id')->all();

                try {
                    $updated = DB::table('ppsw_bet_records')
                        ->whereIn('id', $ids)
                        ->where('status', 1)
                        ->update(['status' => 3]);

                    $stuckSuccess += $updated;

                    Log::info('ppsw:fix-records (A) 批次更新成功', [
                        'ids_count' => count($ids),
                        'updated' => $updated,
                    ]);
                } catch (Throwable $e) {
                    $stuckFail += count($ids);

                    Log::error('ppsw:fix-records (A) 批次更新失敗', [
                        'ids' => $ids,
                        'error' => $e->getMessage(),
                    ]);
                }

                $bar->advance(count($ids));
            }, 'ppsw_bet_records.id', 'id');

            $bar->finish();
            $this->newLine();
            $this->info("  ✅ (A) 成功 {$stuckSuccess} 筆，失敗 {$stuckFail} 筆。");
            $totalFail += $stuckFail;
        }

        // ────────────────────────────────────────────────
        // 執行 (B)：校對 ppsw_game_rounds 後補寫 total_bet_records 再更新 status=3
        // ────────────────────────────────────────────────
        if ($missingTotal > 0) {
            $this->newLine();
            $this->line("▶ 補寫 (B) {$missingTotal} 筆缺失記錄（含 ppsw_game_rounds 三道驗證）...");
            $missingSuccess = 0;
            $missingFail = 0;
            $missingSkipNoRound = 0;
            $missingSkipNotFinished = 0;
            $missingSkipMultiBet = 0;
            $bar = $this->output->createProgressBar($missingTotal);
            $bar->start();

            $missingQuery->chunkById(self::MISSING_CHUNK_SIZE, function ($records) use (
                &$missingSuccess,
                &$missingFail,
                &$missingSkipNoRound,
                &$missingSkipNotFinished,
                &$missingSkipMultiBet,
                $bar
            ) {
                // 批次預載 ppsw_game_rounds 與同 round BR 數量，避免逐筆查 DB
                $betIds = $records->pluck('bet_id')->unique()->values()->all();

                $gameRounds = DB::table('ppsw_game_rounds')
                    ->whereIn('playSessionID', $betIds)
                    ->get(['playSessionID', 'extPlayerID', 'status', 'bet', 'win'])
                    ->keyBy(fn ($r) => $r->playSessionID . '|' . $r->extPlayerID);

                $roundBrCounts = DB::table('ppsw_bet_records')
                    ->whereIn('bet_id', $betIds)
                    ->select('bet_id', 'username', DB::raw('COUNT(*) as cnt'))
                    ->groupBy('bet_id', 'username')
                    ->get()
                    ->mapWithKeys(fn ($r) => [$r->bet_id . '|' . $r->username => (int) $r->cnt])
                    ->all();

                foreach ($records as $betRecord) {
                    $key = $betRecord->bet_id . '|' . $betRecord->username;
                    $gr = $gameRounds[$key] ?? null;
                    $sameRoundCnt = $roundBrCounts[$key] ?? 1;

                    // 驗證 1：ppsw_game_rounds 必須有對應 round（PP 端確實存在）
                    if ($gr === null) {
                        $missingSkipNoRound++;
                        Log::warning('ppsw:fix-records (B) skip：ppsw_game_rounds 無對應 round', [
                            'bet_record_id' => $betRecord->id,
                            'bet_id' => $betRecord->bet_id,
                            'username' => $betRecord->username,
                        ]);
                        $bar->advance();

                        continue;
                    }

                    // 驗證 2：round 必須是 Finished（PP 端已確認結算）
                    if ($gr->status !== 'F') {
                        $missingSkipNotFinished++;
                        Log::warning('ppsw:fix-records (B) skip：round 尚未結算', [
                            'bet_record_id' => $betRecord->id,
                            'bet_id' => $betRecord->bet_id,
                            'gr_status' => $gr->status,
                        ]);
                        $bar->advance();

                        continue;
                    }

                    // 驗證 3：multi-bet round（同 bet_id 多筆 BR）不在本命令處理範圍
                    if ($sameRoundCnt > 1) {
                        $missingSkipMultiBet++;
                        Log::warning('ppsw:fix-records (B) skip：multi-bet round 請改用 ppsw:backfill-stuck-bet-records', [
                            'bet_record_id' => $betRecord->id,
                            'bet_id' => $betRecord->bet_id,
                            'same_round_br_cnt' => $sameRoundCnt,
                        ]);
                        $bar->advance();

                        continue;
                    }

                    // 通過三道驗證 — 用 ppsw_game_rounds 的真值校正 BR 後再補建 TBR
                    try {
                        DB::transaction(function () use ($betRecord, $gr) {
                            $newWin = (string) $gr->win;
                            $newWinLose = Common::numSub($newWin, (string) $betRecord->bet, 4);

                            $betRecord->update([
                                'win' => $newWin,
                                'win_lose' => $newWinLose,
                                'status' => 3,
                            ]);

                            $service = new TotalBetRecordService(
                                vendorId: self::VENDOR_ID,
                                memberId: $betRecord->member_id,
                                isMultipleWallet: self::IS_MULTIPLE_WALLET,
                            );

                            // transaction_log_id 傳 0：createLogRelation 遇 empty 會自動跳過
                            $service->create($betRecord, transactionLogId: 0);
                        });

                        $missingSuccess++;

                        Log::info('ppsw:fix-records (B) 補寫成功', [
                            'bet_record_id' => $betRecord->id,
                            'bet_id' => $betRecord->bet_id,
                            'member_id' => $betRecord->member_id,
                            'br_bet' => (string) $betRecord->bet,
                            'gr_win' => (string) $gr->win,
                            'new_win_lose' => (string) $betRecord->win_lose,
                        ]);
                    } catch (Throwable $e) {
                        $missingFail++;

                        Log::error('ppsw:fix-records (B) 補寫失敗', [
                            'bet_record_id' => $betRecord->id,
                            'bet_id' => $betRecord->bet_id,
                            'error' => $e->getMessage(),
                            'trace' => Common::formatTrace($e),
                        ]);
                    }

                    $bar->advance();
                }
            }, 'ppsw_bet_records.id', 'id');

            $bar->finish();
            $this->newLine();
            $this->info("  ✅ (B) 成功 {$missingSuccess} 筆，失敗 {$missingFail} 筆。");

            $totalSkip = $missingSkipNoRound + $missingSkipNotFinished + $missingSkipMultiBet;
            if ($totalSkip > 0) {
                $this->warn(sprintf(
                    '  ⚠️  (B) skip 共 %d 筆：no_game_round=%d，not_finished=%d，multi_bet=%d（明細請查 Laravel log，關鍵字：ppsw:fix-records (B) skip）',
                    $totalSkip,
                    $missingSkipNoRound,
                    $missingSkipNotFinished,
                    $missingSkipMultiBet
                ));
            }

            $totalFail += $missingFail;
        }

        $this->newLine();
        $this->info('========================================');
        $this->info('修正完成。');

        if ($totalFail > 0) {
            $this->warn("共 {$totalFail} 筆失敗，請查看 Laravel log（關鍵字：ppsw:fix-records）。");
        }

        return $totalFail > 0 ? 1 : 0;
    }
}
