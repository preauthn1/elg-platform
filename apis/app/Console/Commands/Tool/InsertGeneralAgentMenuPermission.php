<?php

declare(strict_types=1);

namespace App\Console\Commands\Tool;

use App\Enums\Agent\RoleEnum;
use App\Models\Agent;
use App\Models\AgentMenu;
use App\Models\AgentPermission;
use Illuminate\Console\Command;
use Throwable;

/**
 * 為所有 DB 連線的總代新增指定菜單權限（不含下線代理）。
 *
 * 試跑：
 *   php artisan tool:insert-general-agent-menu-permission agent_export_permission --dry-run
 *   php artisan tool:insert-general-agent-menu-permission member_export_permission --dry-run
 *
 * 正式執行：
 *   php artisan tool:insert-general-agent-menu-permission agent_export_permission
 *
 * 只跑指定連線：
 *   php artisan tool:insert-general-agent-menu-permission event_v2 --connection=VWorld
 */
class InsertGeneralAgentMenuPermission extends Command
{
    protected $signature = 'tool:insert-general-agent-menu-permission
                            {menuCode : agent_menus.code}
                            {--connection= : 只處理指定連線名稱}
                            {--dry-run : 只顯示將新增的權限，不寫入}';

    protected $description = '為所有 DB 連線的總代新增指定菜單權限（不含下線代理）';

    /** @var list<string> */
    private array $projectConnections = [
        'mysql',
        'VWorld',
        'TPower',
        'VPlus33',
        'Yes2Win',
        'Alfawin77',
        'Me77',
    ];

    public function handle(): int
    {
        $menuCode = (string) $this->argument('menuCode');
        $isDryRun = (bool) $this->option('dry-run');
        $onlyConnection = $this->option('connection');

        $connections = $this->resolveConnections(
            is_string($onlyConnection) && $onlyConnection !== '' ? $onlyConnection : null,
        );

        if ($connections === []) {
            $this->error('找不到可用的資料庫連線');

            return Command::FAILURE;
        }

        if ($isDryRun) {
            $this->warn('※ Dry-run 模式：只顯示統計，不寫入資料');
            $this->newLine();
        } elseif (!$this->confirmExecution($connections)) {
            $this->info('指令已取消');

            return Command::SUCCESS;
        }

        $totalInserted = 0;

        foreach ($connections as $connectionName) {
            $inserted = $this->processConnection(
                connectionName: $connectionName,
                menuCode: $menuCode,
                isDryRun: $isDryRun,
            );

            if ($inserted === null) {
                continue;
            }

            $totalInserted += $inserted;
        }

        $this->newLine();
        $this->info("完成，共新增 {$totalInserted} 筆總代菜單權限");

        return Command::SUCCESS;
    }

    /**
     * @return list<string>
     */
    private function resolveConnections(?string $only): array
    {
        $defined = array_keys(config('database.connections'));
        $project = array_values(array_intersect($this->projectConnections, $defined));

        if ($only !== null) {
            return in_array($only, $project, true) ? [$only] : [];
        }

        return $project;
    }

    /**
     * @param  list<string>  $connections
     */
    private function confirmExecution(array $connections): bool
    {
        $menuCode = (string) $this->argument('menuCode');

        return $this->confirm(
            '將為以下連線的所有總代新增菜單權限 [' . $menuCode . ']：' . implode(', ', $connections) . '。確定繼續？',
            false,
        );
    }

    private function processConnection(string $connectionName, string $menuCode, bool $isDryRun): ?int
    {
        $this->info("── 連線：{$connectionName}");

        try {
            $menu = AgentMenu::on($connectionName)
                ->where('code', $menuCode)
                ->where('status', 1)
                ->first();

            if ($menu === null) {
                $this->warn("找不到啟用中的菜單：{$menuCode}");

                return 0;
            }

            $generalAgentIds = Agent::on($connectionName)
                ->where('role_id', RoleEnum::GENERAL_AGENT->value)
                ->where('host_id', 0)
                ->pluck('id')
                ->all();

            if ($generalAgentIds === []) {
                $this->warn('找不到總代');

                return 0;
            }

            $existingAgentIds = AgentPermission::on($connectionName)
                ->where('agent_menu_id', $menu->id)
                ->whereIn('agent_id', $generalAgentIds)
                ->distinct()
                ->pluck('agent_id')
                ->all();

            $agentIdsToInsert = array_values(array_diff($generalAgentIds, $existingAgentIds));

            $this->line('menuId: ' . $menu->id);
            $this->line('總代數量: ' . count($generalAgentIds));
            $this->line('已存在權限數量: ' . count($existingAgentIds));
            $this->line('待新增權限數量: ' . count($agentIdsToInsert));

            if ($agentIdsToInsert === []) {
                return 0;
            }

            if ($isDryRun) {
                return count($agentIdsToInsert);
            }

            $inserted = 0;

            foreach (array_chunk($agentIdsToInsert, 1000) as $chunk) {
                $rows = array_map(
                    fn (int $agentId): array => [
                        'agent_id' => $agentId,
                        'agent_menu_id' => $menu->id,
                        'action' => '',
                        'admin' => 0,
                    ],
                    $chunk,
                );

                AgentPermission::on($connectionName)->insert($rows);
                $inserted += count($rows);
                $this->line('chunk 新增管理員權限數量: ' . count($rows));
            }

            $this->info("{$connectionName} 新增完成");

            return $inserted;
        } catch (Throwable $e) {
            $this->error("{$connectionName} 處理失敗：{$e->getMessage()}");

            return null;
        }
    }
}
