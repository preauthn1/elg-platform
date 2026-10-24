<?php

declare(strict_types=1);

namespace App\Console\Commands\Vendor;

use App\Models\Game;
use Illuminate\Console\Command;

/**
 * 匯出 dev 環境的遊戲列表到 JSON 文件
 * 再到 prod 環境匯入
 */
class ExportGameList extends Command
{
    protected $signature = 'vendor:export_game_list {vendorId?} ';

    protected $description = '匯出遊戲列表到 JSON 文件';

    public function handle(): void
    {
        $vendorId = $this->argument('vendorId');

        if (!$vendorId) {
            $vendorId = $this->ask('請輸入 vendorId');
        }

        $filePath = database_path('data/game_list_json_' . $vendorId . '.json');
        $this->line("匯出路徑: {$filePath}");

        $games = Game::where('vendor_id', $vendorId)->get();
        $this->line("匯出遊戲列表: {$games->count()}");

        // 整理遊戲資料，移除不必要的欄位
        $games = collect($games)->map(function ($game) {
            unset($game['id'], $game['created_at'], $game['updated_at']);

            return $game;
        })->toArray();

        file_put_contents($filePath, json_encode($games, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        $this->info('匯出完成');
    }
}
