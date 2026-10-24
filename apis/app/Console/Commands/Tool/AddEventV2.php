<?php

declare(strict_types=1);

namespace App\Console\Commands\Tool;

use App\Enums\Vendor\VendorInfoTypeEnum;
use App\Models\AgentGame;
use App\Models\AgentVendor;
use App\Models\Game;
use App\Models\VendorInfo;
use App\Models\VendorInfoGame;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

class AddEventV2 extends Command
{
    /**
     * php artisan app:add_event_v2 --agentId=100006,100007 --vendorId=67,76 --onlyEnabledGame
     *
     * @var string
     */
    protected $signature = 'app:add_event_v2 {--agentId=} {--vendorId=} {--onlyEnabledGame : 只加入啟用中(status=1)的遊戲}';

    protected $description = '加入活動 v2 的 vendor_info';

    public function handle(): void
    {
        $vendorId = $this->option('vendorId');
        if (!$vendorId) {
            $vendorId = $this->ask('請輸入遊戲商ID');
        }

        $agentId = $this->option('agentId');
        if (!$agentId) {
            $agentId = $this->ask('請輸入代理ID');
        }

        $vendorIdArr = explode(',', $vendorId);
        $agentIdArr = explode(',', $agentId);

        foreach ($vendorIdArr as $vendorId) {
            $vendorInfoV2 = $this->createVendorInfoV2((int) $vendorId);
            if ($vendorInfoV2 === null) {
                continue;
            }

            $games = Game::select('games.*')
                ->where('vendor_id', $vendorId)
                ->when($this->option('onlyEnabledGame'), fn ($query) => $query->where('status', 1))
                ->get();
            $this->createVendorInfoGame($vendorInfoV2, $games);

            $this->createAgentVendor($vendorInfoV2, $agentIdArr);

            $this->createAgentGame($games, $agentIdArr);
        }

        $this->info('新增完畢，請清除cache再重整畫面');
    }

    private function createVendorInfoV2(int $vendorId): ?VendorInfo
    {
        $vendorInfoV2 = VendorInfo::select('vendor_infos.*')
            ->where('vendor_id', $vendorId)
            ->where('type', VendorInfoTypeEnum::EVENT_V2->value)
            ->first();

        if ($vendorInfoV2 === null) {
            $vendorInfo = VendorInfo::select('vendor_infos.*')
                ->where('vendor_id', $vendorId)
                ->whereIn('type', [0, 1])
                ->where('status', 1)
                ->first();

            if ($vendorInfo === null) {
                $this->warn('vendor_info 不存在, vendor_id:' . $vendorId);

                return null;
            }

            $vendorInfoV2 = $vendorInfo->replicate();
            $vendorInfoV2->name = $vendorInfo->name . '(E2)';
            $vendorInfoV2->type = VendorInfoTypeEnum::EVENT_V2->value;
            $vendorInfoV2->account_prefix = 'E2' . $vendorInfo->account_prefix;
            $vendorInfoV2->rolling_chip_status = 0;
            $vendorInfoV2->save();

            $this->info('成功新增 vendor id:' . $vendorId . ', vendor_info, vendor_info_id:' . $vendorInfoV2->id);
        } else {
            $this->warn('vendor_info 已存在, vendor_id:' . $vendorId);
        }

        return $vendorInfoV2;
    }

    private function createVendorInfoGame(VendorInfo $vendorInfoV2, Collection $games): void
    {
        $existingGameIds = VendorInfoGame::where('vendor_info_id', $vendorInfoV2->id)
            ->whereIn('game_id', $games->pluck('id'))
            ->pluck('game_id')
            ->all();

        $now = now();
        $rows = [];
        $createdGameIds = [];

        foreach ($games as $game) {
            if (in_array($game->id, $existingGameIds, true)) {
                continue;
            }

            $rows[] = [
                'vendor_info_id' => $vendorInfoV2->id,
                'game_id' => $game->id,
                'status' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ];
            $createdGameIds[] = $game->id;
        }

        foreach (array_chunk($rows, 1000) as $chunk) {
            VendorInfoGame::insert($chunk);
        }

        $this->info('vendor_info_game 已新增, vendor_info_id:' . $vendorInfoV2->id . ', game_id:' . (implode(',', $createdGameIds) ?: '無'));
        $this->warn('vendor_info_game 已存在, vendor_info_id:' . $vendorInfoV2->id . ', game_id:' . (implode(',', $existingGameIds) ?: '無'));
    }

    private function createAgentVendor(VendorInfo $vendorInfoV2, array $agentIdArr)
    {
        foreach ($agentIdArr as $agentId) {
            $exists = AgentVendor::where([
                'agent_id' => $agentId,
                'vendor_id' => $vendorInfoV2->vendor_id,
                'vendor_info_id' => $vendorInfoV2->id,
            ])->exists();

            if ($exists) {
                $this->warn('agent_vendor 已存在, agent_id:' . $agentId . ', vendor_info_id:' . $vendorInfoV2->id);

                continue;
            }

            AgentVendor::create([
                'agent_id' => $agentId,
                'vendor_id' => $vendorInfoV2->vendor_id,
                'vendor_info_id' => $vendorInfoV2->id,
                'status' => 1,
                'self_status' => 1,
                'backend_show' => 1,
                'admin' => 0,
            ]);

            $this->info('成功新增 agent_vendor, agent_id:' . $agentId . ', vendor_info_id:' . $vendorInfoV2->id);
        }
    }

    private function createAgentGame(Collection $games, array $agentIdArr): void
    {
        $existingPairs = AgentGame::whereIn('agent_id', $agentIdArr)
            ->whereIn('game_id', $games->pluck('id'))
            ->get(['agent_id', 'game_id'])
            ->map(fn (AgentGame $agentGame) => $agentGame->agent_id . '-' . $agentGame->game_id)
            ->all();

        $now = now();
        $rows = [];
        $createdByAgent = [];
        $existingByAgent = [];

        foreach ($games as $game) {
            foreach ($agentIdArr as $agentId) {
                $agentId = (int) $agentId;

                if (in_array($agentId . '-' . $game->id, $existingPairs, true)) {
                    $existingByAgent[$agentId][] = $game->id;

                    continue;
                }

                $rows[] = [
                    'agent_id' => $agentId,
                    'game_id' => $game->id,
                    'status' => 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
                $createdByAgent[$agentId][] = $game->id;
            }
        }

        foreach (array_chunk($rows, 1000) as $chunk) {
            AgentGame::insert($chunk);
        }

        foreach ($agentIdArr as $agentId) {
            $agentId = (int) $agentId;
            $this->info('agent_game 已新增, agent_id:' . $agentId . ', game_id:' . (implode(',', $createdByAgent[$agentId] ?? []) ?: '無'));
            $this->warn('agent_game 已存在, agent_id:' . $agentId . ', game_id:' . (implode(',', $existingByAgent[$agentId] ?? []) ?: '無'));
        }
    }
}
