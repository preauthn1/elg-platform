<?php

declare(strict_types=1);

namespace App\Console\Commands\Vendor;

use App\Helpers\Common;
use App\Models\AgentGame;
use App\Models\Game;
use App\Models\GameName;
use App\Models\VendorInfoGame;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 移除 Sexy Slot CQ9 SW（vendor_id=255）不屬於 sexy 系列的遊戲及其關聯記錄（games / vendor_info_games / agent_games / game_names）
 *
 * 保留基準為本命令的 SEXY_GAME_CODES（sexy 系列代碼快照，離線比對、不打三方）；
 * 廠商上新遊戲後執行前需先確認該快照已補列，否則新遊戲會被視為待刪。
 *
 * # 乾跑模式（只顯示將刪除的資料，不寫入）
 * php artisan vendor:prune-sexy-slot-cq9-games --dry-run
 *
 * # 正式執行
 * php artisan vendor:prune-sexy-slot-cq9-games
 */
class PruneSexySlotCq9SWGames extends Command
{
    protected $signature = 'vendor:prune-sexy-slot-cq9-games {--dry-run : 乾跑模式，不實際刪除資料}';

    protected $description = '移除 Sexy Slot CQ9 SW 不屬於 sexy 系列的遊戲';

    private const VENDOR_ID = 255;

    /**
     * sexy 系列遊戲代碼快照（DB 只存代碼、無 gametype 可離線比對）；
     * 同步收錄走 Driver::ALLOWED_VENDOR_GAME_TYPES，廠商上新遊戲後執行 prune 前需補列
     */
    private const array SEXY_GAME_CODES = [
        '6001', '6002', '6003', '6004', '6005', '6006', '6007', '6008', '6009', '6010',
        '6011', '6012', '6013', '6014', '6015', '6016', '6017', '6018', '6019', '6020',
        '6021', '6022', '6023', '6024', '6025', '6026', '6027', '6028', '6029', '6030',
        '6031', '6032', '6033', '6034', '6035', '6036', '6037', '6038', '6039', 'GO6901',
    ];

    public function handle(): int
    {
        $isDryRun = (bool) $this->option('dry-run');

        $games = Game::query()
            ->where('vendor_id', self::VENDOR_ID)
            ->whereNotIn('game_type', self::SEXY_GAME_CODES)
            ->get();

        if ($games->isEmpty()) {
            $this->info('沒有需要移除的遊戲');

            return self::SUCCESS;
        }

        $gameIds = $games->pluck('id')->all();

        $this->warn(sprintf(
            '%s vendor_id=%d 共 %d 款遊戲不在 sexy 系列代碼快照內：',
            $isDryRun ? '[乾跑]' : '[執行]',
            self::VENDOR_ID,
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

            Log::info('PruneSexySlotCq9SWGames 完成', [
                'vendor_id' => self::VENDOR_ID,
                'game_ids' => $gameIds,
                'games' => $gamesCount,
                'vendor_info_games' => $vendorInfoGamesCount,
                'agent_games' => $agentGamesCount,
                'game_names' => $gameNamesCount,
            ]);

            return self::SUCCESS;
        } catch (\Throwable $e) {
            DB::rollBack();
            $this->error('刪除失敗: ' . $e->getMessage());
            Log::error('PruneSexySlotCq9SWGames 錯誤', [
                'error' => $e->getMessage(),
                'trace' => Common::formatTrace($e),
            ]);

            return self::FAILURE;
        }
    }
}
