<?php

declare(strict_types=1);

namespace App\Console\Commands\Vendor;

use App\Models\AgentGame;
use App\Models\Game;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class InsertAgentGame extends Command
{
    protected $signature = 'vendor:insert_agent_game {vendorId?}';

    protected $description = '新增指定代理 agent_games';

    protected array $typeOptions = [
        4249 => 'tpower',
        59562 => 'vworld',
        100074 => 'VN',
    ];

    public function handle(): void
    {
        DB::transaction(function () {
            $vendorId = (int) $this->argument('vendorId');
            if (!$vendorId) {
                $vendorId = (int) $this->ask('請輸入遊戲商ID');
            }

            $agentId = $this->choice('請選擇站點對應的測試代理', $this->typeOptions, 0);
            $agentId = (int) array_search($agentId, $this->typeOptions);

            $this->createAgentGames($agentId, $vendorId);
        });
    }

    protected function createAgentGames(int $agentId, int $vendorId): void
    {
        $gameIds = Game::query()
            ->where('vendor_id', $vendorId)
            ->where('status', 1)
            ->pluck('id')
            ->toArray();

        $this->line('遊戲共有 ' . count($gameIds) . ' 筆');

        $agentGameIds = AgentGame::query()
            ->where('agent_id', $agentId)
            ->whereIn('game_id', $gameIds)
            ->pluck('game_id')
            ->toArray();

        $this->line('代理遊戲共有 ' . count($agentGameIds) . ' 筆');

        $addGameIds = array_diff($gameIds, $agentGameIds);

        // 啟用代理遊戲狀態
        $updateCount = AgentGame::query()
            ->where('agent_id', $agentId)
            ->whereIn('game_id', $gameIds)
            ->where('status', 0)
            ->update(['status' => 1]);

        // 新增代理遊戲
        $insertArr = [];
        foreach ($addGameIds as $gameId) {
            $insertArr[] = [
                'agent_id' => $agentId,
                'game_id' => $gameId,
                'status' => 1,
            ];
        }
        AgentGame::query()->insert($insertArr);

        $this->info(sprintf('vendor_id: %s, agent_id: %s, 新增 agent_game %s 筆, 更新 agent_game %s 筆', $vendorId, $agentId, count($insertArr), $updateCount));
    }
}
