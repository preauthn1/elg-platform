<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Helpers\Common;
use App\Helpers\VendorPost;
use App\Models\Game;
use App\Models\GameType;
use App\Models\Vendor;
use App\Models\VendorInfo;
use App\Models\AgentGame;
use App\Services\TelegramService;

class GetWmGames extends Command
{
    protected $tgService;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'get_wm_games';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '更新wm遊戲列表';

    protected $vendor_id = 16;

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
        $this->tgService = New TelegramService();
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        try {

            $url = 'platform/feed/games/';

            $row = VendorInfo::where('vendor_id', $this->vendor_id)->where('status', 1)->first();
            if (is_object($row)) {
                //將遊戲商資訊解密
                $info = Common::decryptVendorInfo($row);
                if (is_object($info)) {
                    $result = VendorPost::WmPost($info, $url, [], false);
                    if (is_array($result)) {
                        $vendor = Vendor::where('id', $this->vendor_id)->where('status', 1)->first();
                        if (is_object($vendor)) {
                            $newGame = [];
                            foreach ($result as $row) {
                                $data = array();
                                $data['vendor_id'] = $this->vendor_id;
                                if ($row['GameType'] == 'Slot Machines') {
                                    $data['type_id'] = 2; //放拉霸遊戲
                                } else if ($row['GameType'] == 'Fishing Games') {
                                    $data['type_id'] = 5; //放捕魚
                                } else {
                                    $data['type_id'] = 15;//其他都放Arcade
                                }
                                $data['game_type'] = $row['Identity'];

                                $data['name'] = $row['Name'];
                                $data['image'] = $row['ThumbnailUrl'];
                                $data['info'] = json_encode($row);
                                $data['status'] = 1;
                                $data['remark'] = NULL;
                                $data['admin'] = 0;
                                $data['valid_bet_rate'] = $vendor->valid_bet_rate;

                                $game = Game::where('vendor_id', $this->vendor_id)
                                    ->where('game_type', $row['Identity'])
                                    ->first();
                                if (!$game) {
                                    $game = Game::create($data);

                                    $agent_game = array();
                                    $agent_game['agent_id'] = 1;
                                    $agent_game['game_id'] = $game->id;
                                    $agent_game['status'] = 0;
                                    $agent_game['admin'] = $data['admin'];
                                    AgentGame::create($agent_game);

                                    array_push($newGame, $row['Name']);
                                    echo '+++++++++' . $row['Name'] . '+++++++++';
                                } else {
                                    if ($game->info == '') {
                                        $game->info = $data['info'];
                                        $game->save();
                                    }
                                    echo '---------' . $row['Name'] . '---------';
                                }
                                echo PHP_EOL;
                            }
                            //如有新增遊戲，傳TG
                            if (count($newGame) > 0) {
                                $content = "【WM】新增遊戲 " . count($newGame) . " 個 " . PHP_EOL;
                                $content .= "遊戲名稱 : ";
                                foreach ($newGame as $name) {
                                    $content .= $name;
                                    if ($name !== end($newGame)) {
                                        $content .= ", ";
                                    }
                                }
                                $this->tgService->sendMessage($content, 'new_game', '', true);
                            }
                            echo 'success';
                        } else {
                            echo 'fail vendor not found';
                        }
                    } else {
                        echo 'fail';
                    }
                } else {
                    echo 'error';
                }
            }
        } catch (\Illuminate\Database\QueryException $e) {
            $info = $e->errorInfo;
            echo $info[0] . ' : ' . $info[2];
        } catch (\Exception $e) {
            echo $e->getCode() . ' : ' . $e->getMessage();
        }
    }
}