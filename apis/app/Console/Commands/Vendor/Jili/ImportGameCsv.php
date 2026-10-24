<?php

declare(strict_types=1);

namespace App\Console\Commands\Vendor\Jili;

use App\Data\GameInfoData;
use App\Enums\Game\GameTypeEnum;
use App\Models\AgentGame;
use App\Models\Game;
use App\Models\GameName;
use App\Models\Vendor;
use App\Models\VendorInfo;
use App\Models\VendorInfoGame;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * 匯入 CSV 遊戲列表到 games 資料表
 */
class ImportGameCsv extends Command
{
    protected $signature = 'jili:import_game_csv';

    protected $description = '匯入 JILI 遊戲 CSV 到 games 資料表';

    protected int $vendorId = 61;

    public function handle(): void
    {
        $games = $this->getGameData();

        $this->importGamesToVendorInfo($games);
    }

    protected function getGameData(): array
    {
        $filePath = __DIR__ . '/export_games_182805.csv';

        if (!file_exists($filePath)) {
            $this->error('檔案不存在: ' . $filePath);
            exit(1);
        }

        $handle = fopen($filePath, 'r');
        if (!$handle) {
            $this->error('無法開啟檔案');
            exit(1);
        }

        $games = [];

        $header = fgetcsv($handle);
        if (isset($header[0])) {
            $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]);
        }
        while (($row = fgetcsv($handle)) !== false) {
            $data = array_combine($header, $row);
            if ($data['GameType'] == 'Slots') {
                $games[] = GameInfoData::from([
                    'gameCode' => $data['GameID'],
                    'gameName' => $data['GameName(EN)'],
                    'imageUrl' => $data['ImageUrl'],
                    'gameType' => GameTypeEnum::SLOTS->value, // 假設所有遊戲都是 SLOTS 類型
                    'status' => 1,
                ]);
            }
        }
        fclose($handle);

        return $games;
    }

    protected function importGamesToVendorInfo(array $gameInfos): void
    {
        DB::transaction(function () use ($gameInfos) {
            // 先將目前所有 JILI 遊戲關閉
            Game::where('vendor_id', $this->vendorId)->update(['status' => 0]);

            $vendor = Vendor::where('id', $this->vendorId)
                ->select('id', 'valid_bet_rate')
                ->first();

            $vendorInfos = VendorInfo::where('vendor_id', $this->vendorId)
                ->where('status', 1)
                ->get();

            foreach ($vendorInfos as $vendorInfo) {
                $this->createGames($gameInfos, $vendor, $vendorInfo);
            }
        });
    }

    protected function createGames(array $gameInfos, Vendor $vendor, VendorInfo $vendorInfo)
    {

        // 新增 games
        $gameMap = Game::query()
            ->where('vendor_id', $vendor->id)
            ->get()
            ->keyBy('game_type');

        $existGameCount = 0;
        $newGameCount = 0;
        $disabledGameCount = 0;
        $gameIds = [];

        foreach ($gameInfos as $gameInfo) {
            /** @var GameInfoData $gameInfo */
            if (isset($gameMap[$gameInfo->gameCode])) {
                $game = $gameMap[$gameInfo->gameCode];
                $existGameCount++;
            } else {
                $game = new Game;
                $newGameCount++;
            }
            $game->vendor_id = $vendor->id;
            $game->game_type = $gameInfo->gameCode;
            $game->name = $gameInfo->gameName;
            $game->type_id = $gameInfo->gameType;
            $game->image = $gameInfo->imageUrl;
            $game->valid_bet_rate = $vendor->valid_bet_rate;
            $game->info = $gameInfo->info;
            $game->is_new = 0;
            $game->is_hot = 0;
            $game->status = $gameInfo->status;
            $game->open_window = $gameInfo->openWindow;
            $game->admin = 1;
            $game->save();
            $gameIds[] = $game->id;
            $gameInfo->dbId = $game->id;
        }
        // 停用 $gameInfos 以外的遊戲
        $gameIdMap = array_flip($gameIds);
        foreach ($gameMap as $game) {
            /** @var Game $game */
            if (!isset($gameIdMap[$game->id])) {
                $disabledGameCount++;
                $game->status = 0;
                $game->save();
            }
        }

        $this->line("games 新增{$newGameCount}款遊戲, 更新{$existGameCount}款遊戲, 停用{$disabledGameCount}款遊戲");

        // 新增 agent_games;
        $existGameCount = 0;
        $newGameCount = 0;
        $agentGameMap = AgentGame::query()
            ->where('agent_id', 1)
            ->whereIn('game_id', $gameIds)
            ->get()
            ->keyBy('game_id');

        foreach ($gameIds as $gameId) {
            if (isset($agentGameMap[$gameId])) {
                $agentGame = $agentGameMap[$gameId];
                $existGameCount++;
            } else {
                $agentGame = new AgentGame;
                $newGameCount++;
            }
            $agentGame->agent_id = 1;
            $agentGame->game_id = $gameId;
            $agentGame->status = 0;
            $agentGame->admin = 1;
            $agentGame->save();
        }
        $this->line("agent_games 新增{$newGameCount}款遊戲, 更新{$existGameCount}款遊戲");

        // 新增 vendor_info_game
        $existGameCount = 0;
        $newGameCount = 0;
        $vendorInfoGameMap = VendorInfoGame::query()
            ->where('vendor_info_id', $vendorInfo->id)
            ->whereIn('game_id', $gameIds)
            ->get()
            ->keyBy('game_id');

        foreach ($gameIds as $gameId) {
            if (isset($vendorInfoGameMap[$gameId])) {
                $vendorInfoGame = $vendorInfoGameMap[$gameId];
                $existGameCount++;
            } else {
                $vendorInfoGame = new VendorInfoGame;
                $newGameCount++;
            }
            $vendorInfoGame->vendor_info_id = $vendorInfo->id;
            $vendorInfoGame->game_id = $gameId;
            $vendorInfoGame->valid_bet_rate = $vendor->valid_bet_rate;
            $vendorInfoGame->status = 1;
            $vendorInfoGame->is_new = 0;
            $vendorInfoGame->admin = 1;
            $vendorInfoGame->save();
        }
        $this->line("vendor_info_game 新增{$newGameCount}款遊戲, 更新{$existGameCount}款遊戲");

        // 新增 game_names
        $existGameCount = 0;
        $newGameCount = 0;
        $gameNameMap = GameName::query()
            ->whereIn('game_id', $gameIds)
            ->get()
            ->keyBy(function ($item) {
                return $item->language_id . '_' . $item->game_id;
            });

        foreach ($gameInfos as $gameInfo) {
            foreach ($gameInfo->gameNames as $langId => $name) {
                if (isset($gameNameMap[$langId . '_' . $gameInfo->dbId])) {
                    $gameName = $gameNameMap[$langId . '_' . $gameInfo->dbId];
                    $existGameCount++;
                } else {
                    $gameName = new GameName;
                    $newGameCount++;
                }
                $gameName->language_id = $langId;
                $gameName->game_id = $gameInfo->dbId;
                $gameName->name = $name;
                $gameName->admin = 1;
                $gameName->save();
            }
        }
        $this->line("game_names 新增{$newGameCount}款遊戲名稱, 更新{$existGameCount}款遊戲名稱");
    }
}
