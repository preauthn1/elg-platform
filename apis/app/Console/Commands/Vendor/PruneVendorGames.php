<?php

declare(strict_types=1);

namespace App\Console\Commands\Vendor;

use App\Helpers\Common;
use App\Models\AgentGame;
use App\Models\Game;
use App\Models\GameName;
use App\Models\Vendor;
use App\Models\VendorInfoGame;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * 移除指定 vendor_id 底下全部的 games 及其關聯記錄（vendor_info_games / agent_games / game_names）
 *
 * # 乾跑模式（只顯示將刪除的資料，不寫入）
 * php artisan vendor:prune-games {vendorId} --dry-run
 *
 * # 正式執行（會再次要求文字確認）
 * php artisan vendor:prune-games {vendorId}
 */
class PruneVendorGames extends Command
{
    protected $signature = 'vendor:prune-games
        {vendorId : 遊戲商 ID}
        {--dry-run : 僅預覽不寫入}';

    protected $description = '移除指定 vendor_id 的全部遊戲及其關聯記錄';

    public function handle(): int
    {
        $vendorId = (int) $this->argument('vendorId');
        $isDryRun = (bool) $this->option('dry-run');

        $vendor = Vendor::find($vendorId);

        if (!$vendor) {
            $this->error("找不到 vendor_id={$vendorId}");

            return self::FAILURE;
        }

        $games = Game::query()->where('vendor_id', $vendorId)->get();

        if ($games->isEmpty()) {
            $this->info("vendor_id={$vendorId} name={$vendor->name} 沒有任何遊戲，無需移除");

            return self::SUCCESS;
        }

        $gameIds = $games->pluck('id')->all();

        $this->warn(sprintf(
            '%s vendor_id=%d name=%s 共 %d 款遊戲：',
            $isDryRun ? '[乾跑]' : '[執行]',
            $vendorId,
            $vendor->name,
            count($gameIds)
        ));
        foreach ($games as $game) {
            $this->line("  - game_id={$game->id} game_type={$game->game_type} name={$game->name}");
        }

        if ($isDryRun) {
            $this->newLine();
            $this->info('=== 乾跑模式結束，未刪除任何資料 ===');

            return self::SUCCESS;
        }

        if (!$this->confirm("確認刪除 vendor_id={$vendorId} 的全部 {$games->count()} 款遊戲及其關聯？", false)) {
            $this->info('已取消刪除。');

            return self::SUCCESS;
        }

        DB::beginTransaction();

        try {
            $vendorInfoGamesCount = VendorInfoGame::query()->whereIn('game_id', $gameIds)->delete();
            $agentGamesCount = AgentGame::query()->whereIn('game_id', $gameIds)->delete();
            $gameNamesCount = GameName::query()->whereIn('game_id', $gameIds)->delete();
            $gamesCount = Game::query()->whereIn('id', $gameIds)->delete();

            DB::commit();

            $this->info(sprintf(
                '✓ 已刪除 games=%d, vendor_info_games=%d, agent_games=%d, game_names=%d',
                $gamesCount,
                $vendorInfoGamesCount,
                $agentGamesCount,
                $gameNamesCount
            ));

            Log::info('PruneVendorGames 完成', [
                'vendor_id' => $vendorId,
                'game_ids' => $gameIds,
                'games' => $gamesCount,
                'vendor_info_games' => $vendorInfoGamesCount,
                'agent_games' => $agentGamesCount,
                'game_names' => $gameNamesCount,
            ]);

            return self::SUCCESS;
        } catch (Throwable $e) {
            DB::rollBack();
            $this->error('刪除失敗: ' . $e->getMessage());
            Log::error('PruneVendorGames 錯誤', [
                'vendor_id' => $vendorId,
                'error' => $e->getMessage(),
                'trace' => Common::formatTrace($e),
            ]);

            return self::FAILURE;
        }
    }
}
