<?php

declare(strict_types=1);

namespace App\Console\Commands\Vendor\PragmaticPlay\RetrieveRecords;

use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 將 ppsw_game_rounds 的 endDate 同步到 ppsw_bet_records 的 payout_time
 *
 * 使用方式：php artisan ppsw:sync-payout-time {date}
 * 範例：php artisan ppsw:sync-payout-time 2025-11-18
 */
class SyncPayoutTimeFromGameRounds extends Command
{
    private const int VENDOR_ID = 83;

    protected $signature = 'ppsw:sync-payout-time {date} {--dry-run : 僅顯示將更新的記錄，不實際執行}';

    protected $description = '將 ppsw_game_rounds 的 endDate 同步到 ppsw_bet_records 的 payout_time，並同步更新 total_bet_records 與 summary_bet_records';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $dateStr = $this->argument('date');
        $isDryRun = $this->option('dry-run');

        // 驗證日期格式
        try {
            $date = Carbon::createFromFormat('Y-m-d', $dateStr)->startOfDay();
        } catch (Exception $e) {
            $this->error('❌ 日期格式錯誤，請使用 Y-m-d 格式（例如：2025-11-18）');

            return 1;
        }

        $this->info('========================================');
        $this->info('📊 同步 payout_time 從 game_rounds.endDate');
        $this->info('========================================');
        $this->info('查詢日期: ' . $date->format('Y-m-d'));
        $this->info('模式: ' . ($isDryRun ? '預覽模式（不更新）' : '執行模式'));
        $this->info('========================================');
        $this->newLine();

        // 檢查資料表是否存在
        if (!$this->checkTablesExist()) {
            return 1;
        }

        // 取得日期範圍
        $startDate = $date->format('Y-m-d 00:00:00');
        $endDate = $date->copy()->addDay()->format('Y-m-d 00:00:00');

        // 查詢需要更新的記錄
        $this->info('🔍 查詢需要更新的記錄...');
        $records = $this->findRecordsToUpdate($startDate, $endDate);

        if (empty($records)) {
            $this->info('✅ 沒有需要更新的記錄');

            return 0;
        }

        $this->info('找到 ' . count($records) . ' 筆需要更新的記錄');
        $this->newLine();

        // 顯示前 10 筆記錄預覽
        if (count($records) > 0) {
            $preview = array_slice($records, 0, 10);
            $this->table(
                ['BetID', 'MemberID', 'GameCode', 'Current PayoutTime', 'New PayoutTime (from endDate)'],
                array_map(function ($record) {
                    return [
                        $record->bet_id,
                        $record->member_id,
                        $record->game_code,
                        $record->current_payout_time ?? 'NULL',
                        $record->new_payout_time,
                    ];
                }, $preview)
            );

            if (count($records) > 10) {
                $this->line('... 還有 ' . (count($records) - 10) . ' 筆記錄');
            }
            $this->newLine();
        }

        if ($isDryRun) {
            $this->warn('⚠️  預覽模式：未執行實際更新');
            $this->info('移除 --dry-run 參數以執行實際更新');

            return 0;
        }

        // 確認是否執行更新
        if (!$this->confirm('是否繼續執行更新？', true)) {
            $this->warn('❌ 使用者取消執行');

            return 0;
        }

        // 執行批次更新
        $this->info('📝 開始更新...');
        $updated = $this->batchUpdatePayoutTime($records);

        $this->newLine();
        $this->info('========================================');
        $this->info('✅ 更新完成！');
        $this->info('========================================');
        $this->info('成功更新: ' . $updated . ' 筆記錄');
        $this->info('========================================');

        return 0;
    }

    /**
     * 檢查資料表是否存在
     */
    private function checkTablesExist(): bool
    {
        $tables = ['ppsw_game_rounds', 'ppsw_bet_records'];
        $missing = [];

        foreach ($tables as $table) {
            if (!DB::getSchemaBuilder()->hasTable($table)) {
                $missing[] = $table;
            }
        }

        if (!empty($missing)) {
            $this->error('❌ 找不到以下資料表：' . implode(', ', $missing));

            return false;
        }

        return true;
    }

    /**
     * 查詢需要更新的記錄
     * 使用 game_rounds.endDate 作為時間範圍條件（符合遊戲商後台報表數據）
     * 只查找 payout_time 與 endDate 不同的記錄
     */
    private function findRecordsToUpdate(string $startDate, string $endDate): array
    {
        $records = DB::table('ppsw_bet_records as br')
            ->join('ppsw_game_rounds as gr', 'br.bet_id', '=', 'gr.playSessionID')
            ->where('gr.endDate', '>=', $startDate)  // 使用 game_rounds.endDate
            ->where('gr.endDate', '<', $endDate)     // 使用 game_rounds.endDate
            ->whereNotNull('gr.endDate')
            ->where(function ($query) {
                // payout_time 為 NULL，或者 payout_time 與 endDate 不同
                $query->whereNull('br.payout_time')
                    ->orWhereRaw('br.payout_time != gr.endDate');
            })
            ->select(
                'br.id',
                'br.bet_id',
                'br.member_id',
                'br.game_code',
                'br.payout_time as current_payout_time',
                'gr.endDate as new_payout_time'
            )
            ->get()
            ->toArray();

        return $records;
    }

    /**
     * 批次更新 payout_time（使用 CASE WHEN 真正的批量更新）
     */
    private function batchUpdatePayoutTime(array $records): int
    {
        $progressBar = $this->output->createProgressBar(count($records));
        $progressBar->start();

        $updated = 0;
        $chunkSize = 500;

        foreach (array_chunk($records, $chunkSize) as $chunk) {
            try {
                DB::beginTransaction();

                $ids = array_map(fn ($r) => $r->id, $chunk);

                // 1. 更新 ppsw_bet_records.payout_time
                $caseSql = 'CASE id ';
                $bindings = [];
                foreach ($chunk as $record) {
                    $caseSql .= 'WHEN ? THEN ? ';
                    $bindings[] = $record->id;
                    $bindings[] = $record->new_payout_time;
                }
                $caseSql .= 'END';

                $idsPlaceholder = implode(',', array_fill(0, count($ids), '?'));
                $affectedRows = DB::affectingStatement(
                    "UPDATE ppsw_bet_records SET payout_time = {$caseSql}, updated_at = ? WHERE id IN ({$idsPlaceholder})",
                    [...$bindings, now()->toDateTimeString(), ...$ids]
                );
                $updated += $affectedRows;

                // 2. 同步 total_bet_records.payout_time
                $this->syncTotalBetRecordsPayoutTime($chunk, $ids, $idsPlaceholder);

                // 3. 若小時桶改變，同步調整 summary_bet_records
                $this->syncSummaryBetRecords($chunk);

                $progressBar->advance(count($chunk));
                DB::commit();
            } catch (Exception $e) {
                DB::rollBack();
                Log::error('SyncPayoutTimeFromGameRounds: 更新失敗', [
                    'chunk_size' => count($chunk),
                    'error' => $e->getMessage(),
                ]);
                $this->error('更新失敗: ' . $e->getMessage());
            }
        }

        $progressBar->finish();
        $this->newLine();

        return $updated;
    }

    /**
     * 同步 total_bet_records.payout_time，與 ppsw_bet_records 保持一致
     */
    private function syncTotalBetRecordsPayoutTime(array $chunk, array $ids, string $idsPlaceholder): void
    {
        $caseSql = 'CASE bet_record_id ';
        $bindings = [];
        foreach ($chunk as $record) {
            $caseSql .= 'WHEN ? THEN ? ';
            $bindings[] = $record->id;
            $bindings[] = $record->new_payout_time;
        }
        $caseSql .= 'END';

        DB::affectingStatement(
            "UPDATE total_bet_records SET payout_time = {$caseSql}, updated_at = ? WHERE vendor_id = ? AND bet_record_id IN ({$idsPlaceholder})",
            [...$bindings, now()->toDateTimeString(), self::VENDOR_ID, ...$ids]
        );
    }

    /**
     * 當 payout_time 的小時桶改變時，調整 summary_bet_records 的加總
     *
     * summary_bet_records.data_time = payout_time 截斷至整點（YYYY-MM-DD HH:00:00）
     * 小時桶改變代表此筆記錄應從舊桶移出、計入新桶
     */
    private function syncSummaryBetRecords(array $chunk): void
    {
        foreach ($chunk as $record) {
            $oldHour = $this->toHourSlot((string) $record->current_payout_time);
            $newHour = $this->toHourSlot((string) $record->new_payout_time);

            if ($oldHour === $newHour) {
                continue;
            }

            $tbr = DB::table('total_bet_records')
                ->where('vendor_id', self::VENDOR_ID)
                ->where('bet_record_id', $record->id)
                ->first(['member_id', 'game_id', 'bet', 'win_lose']);

            if ($tbr === null) {
                continue;
            }

            // 從舊小時桶扣除
            DB::table('summary_bet_records')
                ->where('vendor_id', self::VENDOR_ID)
                ->where('member_id', $tbr->member_id)
                ->where('game_id', $tbr->game_id)
                ->where('data_time', $oldHour)
                ->update([
                    'bet' => DB::raw("bet - {$tbr->bet}"),
                    'win_lose' => DB::raw("win_lose - {$tbr->win_lose}"),
                    'record_count' => DB::raw('record_count - 1'),
                    'updated_at' => now(),
                ]);

            // 計入新小時桶（有則更新，無則插入）
            $existing = DB::table('summary_bet_records')
                ->where('vendor_id', self::VENDOR_ID)
                ->where('member_id', $tbr->member_id)
                ->where('game_id', $tbr->game_id)
                ->where('data_time', $newHour)
                ->orderByDesc('id')
                ->first();

            if ($existing !== null) {
                DB::table('summary_bet_records')
                    ->where('id', $existing->id)
                    ->update([
                        'bet' => DB::raw("bet + {$tbr->bet}"),
                        'win_lose' => DB::raw("win_lose + {$tbr->win_lose}"),
                        'record_count' => DB::raw('record_count + 1'),
                        'updated_at' => now(),
                    ]);
            } else {
                DB::table('summary_bet_records')->insert([
                    'summary_time' => now()->format('Y-m-d H:00:00'),
                    'data_time' => $newHour,
                    'member_id' => $tbr->member_id,
                    'vendor_id' => self::VENDOR_ID,
                    'game_id' => $tbr->game_id,
                    'bet' => $tbr->bet,
                    'win_lose' => $tbr->win_lose,
                    'record_count' => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            Log::info('SyncPayoutTimeFromGameRounds: summary_bet_records 小時桶已調整', [
                'bet_record_id' => $record->id,
                'member_id' => $tbr->member_id,
                'old_hour' => $oldHour,
                'new_hour' => $newHour,
                'bet' => $tbr->bet,
            ]);
        }
    }

    private function toHourSlot(string $datetime): string
    {
        return Carbon::parse($datetime)->format('Y-m-d H:00:00');
    }
}
