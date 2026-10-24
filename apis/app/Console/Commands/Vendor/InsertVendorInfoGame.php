<?php

declare(strict_types=1);

namespace App\Console\Commands\Vendor;

use App\Models\Game;
use App\Models\VendorInfo;
use App\Models\VendorInfoGame;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class InsertVendorInfoGame extends Command
{
    protected $signature = 'vendor:insert_vendor_info_game {vendorId?}';

    protected $description = '新增vendor_info_game';

    public function handle(): void
    {
        DB::transaction(function () {
            $vendorId = (int) $this->argument('vendorId');
            if (!$vendorId) {
                $vendorId = (int) $this->ask('請輸入遊戲商ID');
            }

            $this->createVendorInfoGames($vendorId);
        });
    }

    protected function createVendorInfoGames(int $vendorId): void
    {
        // vendorInfoGame 應該要在取得三方遊戲列表時做更新才對
        // 因為 games 包含所有 vendorInfo 的遊戲, 並不能確定是哪個 vendorInfo 的遊戲
        // 若 vendor 只有 1 個 vendorInfo 的話就沒有差別

        $gameIds = Game::query()
            ->where('vendor_id', $vendorId)
            ->pluck('id')
            ->toArray();

        $vendorInfoIds = VendorInfo::query()
            ->where('vendor_id', $vendorId)
            ->pluck('id')
            ->toArray();

        foreach ($vendorInfoIds as $vendorInfoId) {
            $existGameIds = VendorInfoGame::query()
                ->where('vendor_info_id', $vendorInfoId)
                ->whereIn('game_id', $gameIds)
                ->pluck('game_id')
                ->toArray();

            $addGameIds = array_diff($gameIds, $existGameIds);
            $insertArr = [];
            foreach ($addGameIds as $gameId) {
                $insertArr[] = [
                    'vendor_info_id' => $vendorInfoId,
                    'game_id' => $gameId,
                    'valid_bet_rate' => 1,
                    'status' => 1,
                    'is_new' => 0,
                    'admin' => 1,
                ];
            }
            VendorInfoGame::query()->insert($insertArr);
            $this->line(sprintf('新增 vendor_info_game, vendor_info_id: %s, 共 %s 筆', $vendorInfoId, count($insertArr)));
        }
    }
}
