<?php

declare(strict_types=1);

namespace App\Console\Commands\Vendor;

use App\Models\Game;
use Illuminate\Console\Command;

/**
 * 匯入遊戲列表到 games 資料表
 */
class ImportGameList extends Command
{
    protected $signature = 'vendor:import_game_list {vendorId?}';

    protected $description = '匯入遊戲到 games 資料表';

    public function handle(): void
    {
        $vendorId = $this->argument('vendorId');
        if (!$vendorId) {
            $vendorId = $this->ask('請輸入 vendorId');
        }

        $filePath = database_path('data/game_list_json_' . $vendorId . '.json');

        $this->line('匯入路徑: ' . $filePath);

        if (!file_exists($filePath)) {
            $this->error('檔案不存在: ' . $filePath);

            return;
        }

        $games = json_decode(file_get_contents($filePath), true);

        if (!is_array($games)) {
            $this->error('JSON 解析失敗');

            return;
        }

        // 整理遊戲資料，移除不必要的欄位
        $newGames = collect($games)->map(function ($game) {
            unset($game['id'], $game['created_at'], $game['updated_at']);

            return $game;
        });

        if ($newGames->isEmpty()) {
            $this->warn('沒有可匯入的遊戲資料');

            return;
        }

        // 取得現有的遊戲資料
        $existGames = Game::where('vendor_id', $vendorId)->get();

        // 比較差集，過濾已存在的遊戲
        $diffGames = $newGames->reject(function ($item) use ($existGames) {
            return $existGames->contains('game_type', $item['game_type']);
        });

        if ($diffGames->isEmpty()) {
            $this->info('所有遊戲已存在於資料庫');

            return;
        }

        // 匯入新的遊戲資料
        $this->line('匯入遊戲列表: ' . $diffGames->count());
        Game::insert($diffGames->values()->all());
        $this->info('匯入完成');
    }
}
