<?php

declare(strict_types=1);

namespace App\Console\Commands\Vendor;

use App\Models\Agent;
use App\Models\AgentGame;
use App\Models\AgentVendor;
use App\Models\Game;
use App\Models\Vendor;
use App\Models\VendorCustomizedItem;
use App\Models\VendorInfo;
use App\Models\VendorInfoGame;
use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * php artisan vendor:check_vendor
 *
 * 以下為測試站的代理
 * tpower -- frank  -- 4249
 * vworld -- NeilGameTest -- 59562
 * vworld -- devmy -- 59497
 */
class CheckVendor extends Command
{
    protected $signature = 'vendor:check_vendor';

    protected $description = '檢查所有vendor配置, 通過後正常來說前台可以進入遊戲';

    protected int $vendorId;

    protected int $agentId;

    protected array $agentOptions = [
        '4249 - TPower (frank)',
        '59562 - VWorld (devmy)',
        '100074 - VN (devVietwin88)',
    ];

    /** @var list<array{type: 'sql'|'artisan', description: string, command: string}> */
    protected array $fixes = [];

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->vendorId = (int) $this->ask('請輸入遊戲商ID');

        if (!$this->vendorId) {
            $this->warn('請輸入遊戲商ID');

            return self::FAILURE;
        }

        if (config('database.default') === 'mysql') {
            // 開發環境
            $selectAgent = $this->choice('請選擇開發環境對應的代理商ID', $this->agentOptions, 0);
            $selectAgent = explode(' - ', $selectAgent);
            $this->agentId = (int) $selectAgent[0];
        } else {
            // 正式環境
            $this->agentId = (int) $this->ask('請輸入代理商ID');
        }

        if (!$this->agentId) {
            $this->warn('請輸入代理商ID');

            return self::FAILURE;
        }

        $hasFatalError = false;

        try {
            $agent = $this->checkAgent($this->agentId);
            $vendor = $this->checkVendor($this->vendorId);
            $vendorInfos = $this->checkVendorInfo($vendor);
            $games = $this->checkGame($vendor);

            $this->checkVendorInfoGame($vendorInfos, $games);

            $agentIds = array_reverse($agent->hierarchy_array); // 父在前
            $this->checkAgentGame($agentIds, $games);
            $this->checkAgentVendor($agentIds, $vendorInfos);
            $this->checkVendorCustomizedItem($vendor, $agent);
        } catch (Exception $e) {
            $hasFatalError = true;
        }

        return $this->printFixSummary($hasFatalError);
    }

    protected function getParentAgents(Agent $agent)
    {
        $parentAgents = json_decode($agent->hierarchy, true);
        // 移除頭尾的
        $parentAgents = array_slice($parentAgents, 2, -1);

        return $parentAgents;
    }

    protected function collectSqlFix(string $description, string $sql): void
    {
        $this->fixes[] = ['type' => 'sql', 'description' => $description, 'command' => $sql];
        $this->error($description);
        $this->warn($sql);
    }

    protected function collectArtisanFix(string $description, string $command): void
    {
        $this->fixes[] = ['type' => 'artisan', 'description' => $description, 'command' => $command];
        $this->error($description);
        $this->warn($command);
    }

    protected function printFixSummary(bool $hasFatalError = false): int
    {
        $this->newLine(2);

        if ($this->fixes === []) {
            if ($hasFatalError) {
                $this->error('檢查中斷，請先修正上方致命錯誤');

                return self::FAILURE;
            }

            $this->info('✓ 全部檢查通過');

            return self::SUCCESS;
        }

        $sqls = array_values(array_filter($this->fixes, fn (array $f): bool => $f['type'] === 'sql'));
        $artisans = array_values(array_filter($this->fixes, fn (array $f): bool => $f['type'] === 'artisan'));

        $this->error(sprintf('檢查發現 %d 個問題需處理', count($this->fixes)));

        if ($sqls !== []) {
            $this->newLine();
            $this->line('=== SQL 修復清單 ===');
            foreach ($sqls as $i => $fix) {
                $this->line(sprintf('-- (%d) %s', $i + 1, $fix['description']));
                $this->warn($fix['command']);
            }
        }

        if ($artisans !== []) {
            $this->newLine();
            $this->line('=== Artisan 修復清單 ===');
            foreach ($artisans as $i => $fix) {
                $this->line(sprintf('# (%d) %s', $i + 1, $fix['description']));
                $this->warn($fix['command']);
            }
        }

        if ($hasFatalError) {
            $this->newLine();
            $this->error('檢查中斷，部分項目未完成驗證');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    public function checkAgent($agentId)
    {
        $this->line('1. 檢查 agent: agent_id=' . $agentId);

        $agent = Agent::query()->find($agentId);

        if (!$agent) {
            $this->error('agent 不存在');
            throw new Exception;
        }

        $this->info('通過');

        return $agent;
    }

    public function checkVendor(int $vendorId): Vendor
    {
        $this->line('2. 檢查 vendor: vendor_id=' . $vendorId);

        $vendor = Vendor::query()->find($vendorId);

        if (!$vendor) {
            $this->error('vendor 不存在');
            $this->warn('請將遊戲商配置在 database/data/vendors.php 後升級');
            throw new Exception;
        }

        if ($vendor->status != 1) {
            $sql = sprintf('UPDATE `vendor` SET `status` = 1 WHERE `id` = %s;', $vendorId);
            $this->collectSqlFix('vendor 狀態非啟用中', $sql);
        } else {
            $this->info('通過');
        }

        return $vendor;
    }

    public function checkVendorInfo(Vendor $vendor): Collection
    {
        $this->line('3. 檢查 vendor_info: vendor_id=' . $vendor->id);

        $vendorInfos = VendorInfo::where('vendor_id', $vendor->id)->get();

        if ($vendorInfos->isEmpty()) {
            $this->collectArtisanFix('vendor_info 不存在', 'php artisan vendor:insert_vendor_info');
            throw new Exception;
        }

        $count = $vendorInfos->count();
        $enableCount = $vendorInfos->where('status', 1)->count();

        $this->line(sprintf('vendor_info 共 %s 筆, 其中 %s 筆啟用中', $count, $enableCount));

        if ($enableCount === 0) {
            $sql = sprintf('UPDATE `vendor_infos` SET `status` = 1 WHERE `vendor_id` = %s;', $vendor->id);
            $this->collectSqlFix('vendor_info 狀態非啟用中', $sql);
        } else {
            $this->info('通過');
        }

        return $vendorInfos;
    }

    public function checkGame(Vendor $vendor): Collection
    {
        $this->line('4. 檢查 game');

        $games = Game::query()->where('vendor_id', $vendor->id)->get();

        $count = $games->count();

        if ($count === 0) {
            $this->error('game 不存在');
            throw new Exception;
        }

        $enableCount = $games->where('status', 1)->count();

        $this->line(sprintf('game 共 %s 筆, 其中 %s 筆啟用中', $count, $enableCount));

        if ($enableCount === 0) {
            $sql = sprintf('UPDATE `games` SET `status` = 1 WHERE `vendor_id` = %s;', $vendor->id);
            $this->collectSqlFix('game 狀態非啟用中', $sql);
        } else {
            $this->info('通過');
        }

        return $games;
    }

    /**
     * 查詢所有屬於該 vendor_info_id 的 VendorInfoGame 資料。
     * 若查無資料，收集 artisan 指令並中止本檢查（不影響後續檢查）。
     * 統計總數與啟用（status == 1）的數量；若啟用數為 0，收集 SQL 修復指令並繼續。
     * 比對 $games 與 vendor_info_game 的 game_id 差集，僅提示不收集。
     */
    public function checkVendorInfoGame(Collection $vendorInfos, Collection $games): ?Collection
    {
        $this->line('5. 檢查 vendor_info_game');

        $vendorInfoGames = null;
        $vendorInfo = null;
        foreach ($vendorInfos as $vendorInfo) {
            $this->line(sprintf('檢查 vendor_info_game, vendor_info_id: %s', $vendorInfo->id));

            $vendorInfoGames = VendorInfoGame::query()->where('vendor_info_id', $vendorInfo->id)->get();

            if ($vendorInfoGames->isEmpty()) {
                $this->line(sprintf('vendor_info_id %s 缺少 vendor_info_game', $vendorInfo->id));
            } else {
                // 找到一個 vendor_info_game 就跳出迴圈
                break;
            }
        }

        if (!$vendorInfoGames || $vendorInfoGames->isEmpty()) {
            $this->collectArtisanFix(
                'vendor_info_game 不存在',
                'php artisan vendor:insert_vendor_info_game ' . $this->vendorId,
            );

            return null;
        }

        $count = $vendorInfoGames->count();
        $enableCount = $vendorInfoGames->where('status', 1)->count();
        $this->line(sprintf('vendor_info_game 共 %s 筆, 其中 %s 筆啟用中', $count, $enableCount));

        $hasIssue = false;
        if ($enableCount === 0) {
            $sql = sprintf('UPDATE `vendor_info_games` SET `status` = 1 WHERE `vendor_info_id` = %s;', $vendorInfo->id);
            $this->collectSqlFix('vendor_info_game 狀態非啟用中', $sql);
            $hasIssue = true;
        }

        $gameIds = $games->pluck('id')->toArray();
        $vendorInfoGameIds = $vendorInfoGames->pluck('game_id')->toArray();

        $diff1 = array_diff($vendorInfoGameIds, $gameIds);
        if (!empty($diff1)) {
            $this->line(sprintf('vendor_info_game 多出 %s 筆 game: %s', count($diff1), implode(',', $diff1)));
        }

        $diff2 = array_diff($gameIds, $vendorInfoGameIds);
        if (!empty($diff2)) {
            $this->line(sprintf('vendor_info_game 缺少 %s 筆 game: %s', count($diff2), implode(',', $diff2)));
        }

        if (!$hasIssue) {
            $this->info('通過');
        }

        return $vendorInfoGames;
    }

    public function checkAgentGame(array $agentIds, Collection $games): void
    {
        $this->line('6. 檢查 agent_game');

        $gameIds = $games->pluck('id')->toArray();
        $agentGames = null;
        $agentId = null;
        foreach ($agentIds as $agentId) {
            $this->line(sprintf('檢查 agent_game, agent_id: %s', $agentId));
            $agentGames = AgentGame::query()->where('agent_id', $agentId)->whereIn('game_id', $gameIds)->get();

            if ($agentGames->isEmpty()) {
                $this->line(sprintf('agent_id %s 缺少 agent_game', $agentId));
            } else {
                // 找到一個 agent_game 就跳出迴圈
                break;
            }
        }

        // 所有 agent_id 都沒有 agent_game
        if (!$agentGames || $agentGames->isEmpty()) {
            $this->collectArtisanFix(
                'agent_game 不存在',
                sprintf('php artisan vendor:insert_agent_game %s %s', $this->vendorId, $agentId),
            );

            return;
        }

        $count = $agentGames->count();
        $enableCount = $agentGames->where('status', 1)->count();
        $this->line(sprintf('agent_game 共 %s 筆, 其中 %s 筆啟用中', $count, $enableCount));

        $hasIssue = false;
        if ($enableCount === 0) {
            $sql = sprintf(
                'UPDATE `agent_games` SET `status` = 1 WHERE `agent_id` = %s AND `game_id` IN (%s);',
                $agentId,
                implode(',', $gameIds),
            );
            $this->collectSqlFix(sprintf('agent_id %s 的 agent_game 狀態非啟用中', $agentId), $sql);
            $hasIssue = true;
        }

        $agentGameIds = $agentGames->pluck('game_id')->toArray();

        $diff1 = array_diff($agentGameIds, $gameIds);
        if (!empty($diff1)) {
            $this->line(sprintf('agent_game 多出 %s 筆 game: %s', count($diff1), implode(',', $diff1)));
        }

        $diff2 = array_diff($gameIds, $agentGameIds);
        if (!empty($diff2)) {
            $this->line(sprintf('agent_game 缺少 %s 筆 game: %s', count($diff2), implode(',', $diff2)));
        }

        if (!$hasIssue) {
            $this->info('通過');
        }
    }

    public function checkAgentVendor(array $agentIds, Collection $vendorInfos): ?Collection
    {
        $this->line('7. 檢查 agent_vendor');

        $vendorInfoIds = $vendorInfos->pluck('id')->toArray();

        $agentVendors = null;
        $agentId = null;
        foreach ($agentIds as $agentId) {
            $this->line(sprintf('檢查 agent_vendor, agent_id: %s', $agentId));
            $agentVendors = AgentVendor::query()
                ->where('agent_id', $agentId)
                ->where('vendor_id', $this->vendorId)
                ->whereIn('vendor_info_id', $vendorInfoIds)
                ->get();

            if ($agentVendors->isEmpty()) {
                $this->line(sprintf('agent_id %s 缺少 agent_vendor', $agentId));
            } else {
                // 找到一個 agent_vendor 就跳出迴圈
                break;
            }
        }

        // 所有 agent_id 都沒有 agent_vendor
        if (!$agentVendors || $agentVendors->isEmpty()) {
            $this->collectArtisanFix(
                'agent_vendor 不存在',
                sprintf('php artisan vendor:insert_agent_vendor %s %s %s', $this->vendorId, $agentId, $vendorInfoIds[0] ?? 0),
            );

            return null;
        }

        $count = $agentVendors->count();
        $enableCount = $agentVendors->filter(function ($vendor) use ($agentId) {
            if ($agentId !== $this->agentId) {
                return $vendor->status == 1;
            }

            return $vendor->status == 1 && $vendor->self_status == 1;
        })->count();
        $this->line(sprintf('agent_vendor 共 %s 筆, 其中 %s 筆啟用中', $count, $enableCount));

        if ($enableCount === 0) {
            $sql = sprintf(
                'UPDATE `agent_vendors` SET `status` = 1 WHERE `agent_id` = %s AND `vendor_id` = %s;',
                $agentId,
                $this->vendorId,
            );
            $this->collectSqlFix(sprintf('agent_id %s 的 agent_vendor 狀態非啟用中', $agentId), $sql);
        } else {
            $this->info('通過');
        }

        return $agentVendors;
    }

    public function checkVendorCustomizedItem(Vendor $vendor, Agent $agent): ?VendorCustomizedItem
    {
        $this->line(sprintf('8. 檢查 vendor_customizes_item, vendor_id: %s', $vendor->id));

        $vendorCustomizesItem = VendorCustomizedItem::query()
            ->where('vendor_id', $vendor->id)
            ->where('agent_group_id', $agent->vendor_customize_group_id)
            ->first();

        if (!$vendorCustomizesItem) {
            $this->collectArtisanFix(
                'vendor_customizes_item 不存在',
                sprintf('php artisan vendor:insert_vendor_customized_item %s %s', $vendor->id, $agent->id),
            );

            return null;
        }

        if ($vendorCustomizesItem->status != 1) {
            $sql = sprintf('UPDATE `vendor_customized_items` SET `status` = 1 WHERE `id` = %s;', $vendorCustomizesItem->id);
            $this->collectSqlFix('vendor_customized_items 狀態非啟用中', $sql);
        } else {
            $this->info('通過');
        }

        return $vendorCustomizesItem;
    }
}
