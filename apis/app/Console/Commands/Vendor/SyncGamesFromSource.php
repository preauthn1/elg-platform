<?php

declare(strict_types=1);

namespace App\Console\Commands\Vendor;

use App\Models\AgentGame;
use App\Models\Game;
use App\Models\Vendor;
use App\Models\VendorInfoGame;
use Exception;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * 流程
 * 1. 比對 source connection 與當前環境 vendor 的 games（依 game_type 為自然鍵）
 * 2. 將缺少的 game 從 source 複製到當前環境 games 表
 * 3. 為當前環境既有的 agent_id 集合補上 agent_games 關聯
 * 4. 為當前環境既有的 vendor_info_id 集合補上 vendor_info_games 關聯
 *
 * 使用情境：測試環境 PPSW (vendor_id=83) 缺少正式環境 (VWorld) 上架的遊戲，需同步補齊。
 *
 * 與 vendor:add_vendor_info 的差異：
 * - AddVendorInfo 為「首次建立 vendor_info」流程（會詢問幣別、prefix 等）
 * - 本命令僅同步「既有設定下缺少的遊戲」，不建立新 vendor_info、不建立新 agent_vendor
 */
class SyncGamesFromSource extends Command
{
    protected $signature = 'vendor:sync_games_from_source
        {vendorId? : 遊戲商 ID}
        {--source=VWorld : 來源資料庫連線（VWorld / TPower 等）}
        {--dry-run : 僅預覽不寫入}';

    protected $description = '從來源環境（VWorld 等）同步缺少的遊戲與關聯到當前環境';

    private const PREVIEW_LIMIT = 30;

    public function handle(): int
    {
        $sourceConn = (string) $this->option('source');
        $destConn = (string) config('database.default');
        $isDryRun = (bool) $this->option('dry-run');

        if ($sourceConn === $destConn) {
            $this->error("來源連線 ({$sourceConn}) 與當前連線 ({$destConn}) 相同，無法同步");

            return self::FAILURE;
        }

        $vendorId = $this->resolveVendorId();

        try {
            $vendor = $this->checkVendor($sourceConn, $vendorId);

            $missing = $this->findMissingGames($sourceConn, $vendorId);

            if ($missing->isEmpty()) {
                $this->info('當前環境已包含來源所有遊戲，無需同步。');

                return self::SUCCESS;
            }

            $agentIds = $this->collectExistingAgentIds($vendorId);
            $vendorInfoIds = $this->collectExistingVendorInfoIds($vendorId);

            $this->renderPreview($vendor, $sourceConn, $destConn, $missing, $agentIds, $vendorInfoIds);

            if ($isDryRun) {
                $this->info('Dry-run 結束，未寫入資料。');

                return self::SUCCESS;
            }

            if (!$this->confirm('確認寫入？', false)) {
                $this->info('已取消寫入。');

                return self::SUCCESS;
            }

            DB::beginTransaction();

            try {
                $newGames = $this->copyGames($vendorId, $missing);
                $this->insertAgentGames($newGames, $agentIds);
                $this->insertVendorInfoGames($newGames, $vendorInfoIds);

                DB::commit();

                $this->info('同步完成。');
            } catch (Throwable $e) {
                DB::rollBack();
                throw $e;
            }
        } catch (Exception $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function resolveVendorId(): int
    {
        $vendorId = $this->argument('vendorId');

        if ($vendorId === null) {
            $vendorId = $this->ask('請輸入遊戲商 ID');
        }

        return (int) $vendorId;
    }

    private function checkVendor(string $sourceConn, int $vendorId): Vendor
    {
        $vendor = Vendor::on($sourceConn)->find($vendorId);

        if (!$vendor) {
            throw new Exception("來源 ({$sourceConn}) 找不到 vendor_id={$vendorId}");
        }

        return $vendor;
    }

    /**
     * 找出來源有、當前環境沒有的 game（依 game_type 去重）
     *
     * @return Collection<int, Game>
     */
    private function findMissingGames(string $sourceConn, int $vendorId): Collection
    {
        $localGameTypes = Game::query()
            ->where('vendor_id', $vendorId)
            ->pluck('game_type')
            ->all();

        return Game::on($sourceConn)
            ->where('vendor_id', $vendorId)
            ->whereNotIn('game_type', $localGameTypes)
            ->orderBy('game_type')
            ->get();
    }

    /**
     * 取得當前環境此 vendor 既有的 agent_id 集合
     *
     * @return array<int, int>
     */
    private function collectExistingAgentIds(int $vendorId): array
    {
        $localGameIds = Game::query()->where('vendor_id', $vendorId)->pluck('id');

        return AgentGame::query()
            ->whereIn('game_id', $localGameIds)
            ->distinct()
            ->pluck('agent_id')
            ->map(fn ($v) => (int) $v)
            ->sort()
            ->values()
            ->all();
    }

    /**
     * 取得當前環境此 vendor 既有的 vendor_info_id 集合
     *
     * @return array<int, int>
     */
    private function collectExistingVendorInfoIds(int $vendorId): array
    {
        $localGameIds = Game::query()->where('vendor_id', $vendorId)->pluck('id');

        return VendorInfoGame::query()
            ->whereIn('game_id', $localGameIds)
            ->distinct()
            ->pluck('vendor_info_id')
            ->map(fn ($v) => (int) $v)
            ->sort()
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, Game>  $missing
     * @param  array<int, int>  $agentIds
     * @param  array<int, int>  $vendorInfoIds
     */
    private function renderPreview(
        Vendor $vendor,
        string $sourceConn,
        string $destConn,
        Collection $missing,
        array $agentIds,
        array $vendorInfoIds,
    ): void {
        $this->line('');
        $this->line("vendor_id={$vendor->id} name={$vendor->name}");
        $this->line("source={$sourceConn}  dest={$destConn}");
        $this->line('');

        $this->info("即將寫入 games {$missing->count()} 筆（依 game_type 去重）");
        $previewRows = $missing->take(self::PREVIEW_LIMIT)->map(fn (Game $g) => [
            'game_type' => $g->game_type,
            'name' => $g->name,
            'status' => (string) $g->status,
            'sort' => (string) $g->sort,
        ])->all();
        $this->table(['game_type', 'name', 'status', 'sort'], $previewRows);

        if ($missing->count() > self::PREVIEW_LIMIT) {
            $this->line('（僅顯示前 ' . self::PREVIEW_LIMIT . ' 筆）');
        }

        $this->line('');
        $this->info(sprintf(
            '即將寫入 agent_games %d 筆（agent_ids = [%s]）',
            count($agentIds) * $missing->count(),
            implode(', ', $agentIds),
        ));
        $this->info(sprintf(
            '即將寫入 vendor_info_games %d 筆（vendor_info_ids = [%s]）',
            count($vendorInfoIds) * $missing->count(),
            implode(', ', $vendorInfoIds),
        ));
        $this->line('');
    }

    /**
     * @param  Collection<int, Game>  $missing
     * @return Collection<int, Game> 當前環境插入後重新讀回的 games
     */
    private function copyGames(int $vendorId, Collection $missing): Collection
    {
        $rows = $missing->map(fn (Game $g) => [
            'vendor_id' => $vendorId,
            'type_id' => $g->type_id,
            'join_bet_event' => $g->join_bet_event,
            'join_win_event' => $g->join_win_event,
            'game_type' => $g->game_type,
            'name' => $g->name,
            'lottery_pool' => $g->lottery_pool,
            'image' => $g->image,
            'is_new' => $g->is_new,
            'is_hot' => $g->is_hot,
            'open_window' => $g->open_window,
            'status' => $g->status,
            'rtp' => $g->rtp,
            'sort' => $g->sort,
            'info' => $g->info,
            'remark' => $g->remark,
            'memo' => $g->memo,
            'admin' => 0,
            'valid_bet_rate' => $g->valid_bet_rate,
            'calc_flow_limit' => $g->calc_flow_limit,
        ])->all();

        DB::table('games')->insert($rows);

        $this->info('inserted games=' . count($rows));

        return Game::query()
            ->where('vendor_id', $vendorId)
            ->whereIn('game_type', $missing->pluck('game_type')->all())
            ->get();
    }

    /**
     * @param  Collection<int, Game>  $newGames
     * @param  array<int, int>  $agentIds
     */
    private function insertAgentGames(Collection $newGames, array $agentIds): void
    {
        if ($agentIds === []) {
            $this->warn('當前環境無既有 agent_games 資料，略過 agent_games 補齊。');

            return;
        }

        $rows = [];
        foreach ($agentIds as $agentId) {
            foreach ($newGames as $game) {
                $rows[] = [
                    'agent_id' => $agentId,
                    'game_id' => $game->id,
                    'status' => $game->status,
                    'admin' => 0,
                ];
            }
        }

        DB::table('agent_games')->insert($rows);

        $this->info('inserted agent_games=' . count($rows));
    }

    /**
     * @param  Collection<int, Game>  $newGames
     * @param  array<int, int>  $vendorInfoIds
     */
    private function insertVendorInfoGames(Collection $newGames, array $vendorInfoIds): void
    {
        if ($vendorInfoIds === []) {
            $this->warn('當前環境無既有 vendor_info_games 資料，略過 vendor_info_games 補齊。');

            return;
        }

        $rows = [];
        foreach ($vendorInfoIds as $vendorInfoId) {
            foreach ($newGames as $game) {
                $rows[] = [
                    'vendor_info_id' => $vendorInfoId,
                    'game_id' => $game->id,
                    'join_bet_event' => $game->join_bet_event,
                    'join_win_event' => $game->join_win_event,
                    'is_new' => $game->is_new,
                    'is_hot' => $game->is_hot,
                    'sort' => $game->sort,
                    'valid_bet_rate' => $game->valid_bet_rate,
                    'calc_flow_limit' => $game->calc_flow_limit,
                    'status' => $game->status,
                    'admin' => 0,
                ];
            }
        }

        DB::table('vendor_info_games')->insert($rows);

        $this->info('inserted vendor_info_games=' . count($rows));
    }
}
