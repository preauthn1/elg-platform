<?php

declare(strict_types=1);

namespace App\Console\Commands\Vendor;

use App\Models\Agent;
use App\Models\Vendor;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * php artisan vendor:update-agent-game-status 100006 54 --dry-run
 */
class UpdateAgentGameStatus extends Command
{
    protected $signature = 'vendor:update-agent-game-status {agent_id? : 代理 ID} {vendor_id? : 遊戲商 ID} {--dry-run : 只顯示將會更新的 game_id，不實際更新}';

    protected $description = '將指定 agent 與指定 vendor 的重複 game 關聯更新為啟用 (agent_games.status = 1)';

    public function handle(): int
    {
        $agentId = $this->argument('agent_id');
        if (!$agentId) {
            $agentId = (int) $this->ask('請輸入代理商ID');
        }

        $vendorId = $this->argument('vendor_id');
        if (!$vendorId) {
            $vendorId = (int) $this->ask('請輸入遊戲商ID');
        }

        $dryRun = $this->option('dry-run');

        // 驗證 agent
        try {
            $agent = Agent::findOrFail($agentId);
        } catch (Throwable $e) {
            $this->error('代理 agent_id=' . $agentId . ' 不存在');

            return 1;
        }

        // 驗證 vendor
        try {
            $vendor = Vendor::findOrFail($vendorId);
        } catch (Throwable $e) {
            $this->error('遊戲商 vendor_id=' . $vendorId . ' 不存在');

            return 1;
        }

        $this->info(sprintf('將檢查 agent_id=%d 與 vendor_id=%d 的遊戲關聯', $agentId, $vendorId));

        // 取得屬於該 vendor 的 game ids
        $gameIds = DB::table('games')->where('vendor_id', $vendorId)->pluck('id')->toArray();

        if (empty($gameIds)) {
            $this->warn('未在 games 表中找到 vendor_id=' . $vendorId . ' 的遊戲');

            return 0;
        }

        // 取得 agent_games 中同時屬於這些 game 的 game_id
        $existing = DB::table('agent_games')
            ->where('agent_id', $agentId)
            ->whereIn('game_id', $gameIds)
            ->pluck('game_id')
            ->toArray();

        if (empty($existing)) {
            $this->info('agent_id=' . $agentId . ' 在 agent_games 中沒有與該 vendor 關聯的遊戲');

            return 0;
        }

        $this->info('找到 ' . count($existing) . ' 筆已存在的 agent_games，game_id 列表: ' . implode(',', $existing));

        // 先取得這些 game_id 在 games 表對應的 status
        $gameStatuses = DB::table('games')
            ->whereIn('id', $existing)
            ->pluck('status', 'id')
            ->toArray();

        if (empty($gameStatuses)) {
            $this->warn('無法從 games 表中取得這些 game_id 的 status 資訊');

            return 0;
        }

        // 取得 agent_games 目前的 status，並比對出需要更新的項目（只列出 status 不同的）
        $agentStatuses = DB::table('agent_games')
            ->where('agent_id', $agentId)
            ->whereIn('game_id', $existing)
            ->pluck('status', 'game_id')
            ->toArray();

        $toUpdate = [];
        foreach ($gameStatuses as $gid => $desiredStatus) {
            $current = array_key_exists($gid, $agentStatuses) ? $agentStatuses[$gid] : null;
            // 當前與期望不同（強制比較 int），才需要更新
            if (!is_null($current) && (int) $current !== (int) $desiredStatus) {
                $toUpdate[$gid] = ['from' => $current, 'to' => $desiredStatus];
            }
        }

        if (empty($toUpdate)) {
            $this->info('目前沒有需要同步的 agent_games（所有 agent_games.status 已與 games.status 一致）');

            return 0;
        }

        // dry-run 顯示要同步的對應關係（僅列出需更新的）
        if ($dryRun) {
            $this->info('dry-run 模式：以下為將要同步到 agent_games 的 game_id => (from => to)：');
            foreach ($toUpdate as $gid => $pair) {
                $this->line(sprintf('  - game_id=%s => %s => %s', $gid, $pair['from'], $pair['to']));
            }

            $this->info('dry-run 模式，未執行更新（排除了不需更新的遊戲）');

            return 0;
        }

        // 執行更新：僅更新那些需要變更的 agent_games.status
        $updatedTotal = 0;

        DB::beginTransaction();
        try {
            foreach ($toUpdate as $gid => $pair) {
                $affected = DB::table('agent_games')
                    ->where('agent_id', $agentId)
                    ->where('game_id', $gid)
                    ->where('status', '<>', $pair['to'])
                    ->update(['status' => $pair['to']]);

                $updatedTotal += $affected;
            }

            DB::commit();
        } catch (Throwable $e) {
            DB::rollBack();
            $this->error('更新失敗: ' . $e->getMessage());

            return 1;
        }

        $this->info('已將 ' . $updatedTotal . ' 筆 agent_games 的 status 同步為對應的 games.status（agent_id=' . $agentId . '）');

        return 0;
    }
}
