<?php

declare(strict_types=1);

namespace App\Console\Commands\Schedule;

use App\Helpers\Common;
use App\Models\AgentGame;
use App\Models\Game;
use App\Models\GameName;
use App\Models\VendorInfoGame;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 清理孤立的遊戲關聯記錄
 *
 * 找出並刪除以下資料表中 game_id 不存在於 games 資料表的記錄：
 * - vendor_info_games
 * - agent_games
 * - game_names
 *
 * # 正常執行（會刪除資料）
 * php artisan clean:orphan-game-records
 *
 * # 乾跑模式（只顯示將要刪除的數據，不實際刪除）
 * php artisan clean:orphan-game-records --dry-run
 */
class CleanOrphanGameRecords extends Command
{
    protected $signature = 'clean:orphan-game-records {--dry-run : 乾跑模式，不實際刪除資料}';

    protected $description = '清理孤立的遊戲關聯記錄（game_id 不存在於 games 表中）';

    public function handle(): int
    {
        $isDryRun = $this->option('dry-run');

        if ($isDryRun) {
            $this->warn('=== 乾跑模式：只顯示將要刪除的數據 ===');
        }

        try {
            DB::beginTransaction();

            $this->info('開始檢查孤立的遊戲關聯記錄...');
            $this->newLine();

            // 1. 清理 vendor_info_games
            $vendorInfoGamesCount = $this->cleanVendorInfoGames($isDryRun);

            // 2. 清理 agent_games
            $agentGamesCount = $this->cleanAgentGames($isDryRun);

            // 3. 清理 game_names
            $gameNamesCount = $this->cleanGameNames($isDryRun);

            // 總計
            $totalCount = $vendorInfoGamesCount + $agentGamesCount + $gameNamesCount;

            $this->newLine();
            if ($isDryRun) {
                $this->info('=== 乾跑模式結束 ===');
                $this->info("共找到 {$totalCount} 筆孤立記錄");
                DB::rollBack();
            } else {
                $this->info("共刪除 {$totalCount} 筆孤立記錄");
                DB::commit();

                // 記錄日誌
                Log::info('清理孤立遊戲記錄完成', [
                    'vendor_info_games' => $vendorInfoGamesCount,
                    'agent_games' => $agentGamesCount,
                    'game_names' => $gameNamesCount,
                    'total' => $totalCount,
                ]);
            }

            return self::SUCCESS;
        } catch (\Throwable $e) {
            DB::rollBack();
            $this->error('清理失敗: ' . $e->getMessage());
            Log::error('CleanOrphanGameRecords 錯誤', [
                'error' => $e->getMessage(),
                'trace' => Common::formatTrace($e),
            ]);

            return self::FAILURE;
        }
    }

    /**
     * 清理 vendor_info_games 表中的孤立記錄
     */
    private function cleanVendorInfoGames(bool $isDryRun): int
    {
        $this->line('檢查 vendor_info_games 表...');

        // 找出孤立的記錄（game_id 不存在於 games 表中）
        $orphanRecords = VendorInfoGame::query()
            ->whereNotExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('games')
                    ->whereColumn('games.id', 'vendor_info_games.game_id');
            })
            ->get();

        $count = $orphanRecords->count();

        if ($count > 0) {
            if ($isDryRun) {
                $this->warn("  找到 {$count} 筆孤立記錄");
                // 顯示前 10 筆作為範例
                $sample = $orphanRecords->take(10);
                foreach ($sample as $record) {
                    $this->line("    - vendor_info_id: {$record->vendor_info_id}, game_id: {$record->game_id}");
                }
                if ($count > 10) {
                    $this->line('    ... 還有 ' . ($count - 10) . ' 筆');
                }
            } else {
                VendorInfoGame::query()
                    ->whereNotExists(function ($query) {
                        $query->select(DB::raw(1))
                            ->from('games')
                            ->whereColumn('games.id', 'vendor_info_games.game_id');
                    })
                    ->delete();
                $this->info("  ✓ 刪除 {$count} 筆孤立記錄");
            }
        } else {
            $this->info('  ✓ 沒有孤立記錄');
        }

        return $count;
    }

    /**
     * 清理 agent_games 表中的孤立記錄
     */
    private function cleanAgentGames(bool $isDryRun): int
    {
        $this->line('檢查 agent_games 表...');

        // 找出孤立的記錄（game_id 不存在於 games 表中）
        $orphanRecords = AgentGame::query()
            ->whereNotExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('games')
                    ->whereColumn('games.id', 'agent_games.game_id');
            })
            ->get();

        $count = $orphanRecords->count();

        if ($count > 0) {
            if ($isDryRun) {
                $this->warn("  找到 {$count} 筆孤立記錄");
                // 顯示前 10 筆作為範例
                $sample = $orphanRecords->take(10);
                foreach ($sample as $record) {
                    $this->line("    - agent_id: {$record->agent_id}, game_id: {$record->game_id}");
                }
                if ($count > 10) {
                    $this->line('    ... 還有 ' . ($count - 10) . ' 筆');
                }
            } else {
                AgentGame::query()
                    ->whereNotExists(function ($query) {
                        $query->select(DB::raw(1))
                            ->from('games')
                            ->whereColumn('games.id', 'agent_games.game_id');
                    })
                    ->delete();
                $this->info("  ✓ 刪除 {$count} 筆孤立記錄");
            }
        } else {
            $this->info('  ✓ 沒有孤立記錄');
        }

        return $count;
    }

    /**
     * 清理 game_names 表中的孤立記錄
     */
    private function cleanGameNames(bool $isDryRun): int
    {
        $this->line('檢查 game_names 表...');

        // 找出孤立的記錄（game_id 不存在於 games 表中）
        $orphanRecords = GameName::query()
            ->whereNotExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('games')
                    ->whereColumn('games.id', 'game_names.game_id');
            })
            ->get();

        $count = $orphanRecords->count();

        if ($count > 0) {
            if ($isDryRun) {
                $this->warn("  找到 {$count} 筆孤立記錄");
                // 顯示前 10 筆作為範例
                $sample = $orphanRecords->take(10);
                foreach ($sample as $record) {
                    $this->line("    - language_id: {$record->language_id}, game_id: {$record->game_id}, name: {$record->name}");
                }
                if ($count > 10) {
                    $this->line('    ... 還有 ' . ($count - 10) . ' 筆');
                }
            } else {
                GameName::query()
                    ->whereNotExists(function ($query) {
                        $query->select(DB::raw(1))
                            ->from('games')
                            ->whereColumn('games.id', 'game_names.game_id');
                    })
                    ->delete();
                $this->info("  ✓ 刪除 {$count} 筆孤立記錄");
            }
        } else {
            $this->info('  ✓ 沒有孤立記錄');
        }

        return $count;
    }
}
