<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Helpers\VendorSend;
use App\Helpers\Common;
use App\Models\Vendor;
use App\Models\VendorInfo;
use App\Models\Game;
use App\Models\GameType;
use App\Models\AgentGame;
use DB;
use App\Services\TelegramService;

class GetAce333Games extends Command
{
    protected $tgService;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'get_ace333_games';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '更新ace333遊戲列表';

    protected $vendor_send;
    protected $vendor_id = 6;

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
        $this->vendor_send = new VendorSend();
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
            $setting = VendorInfo::where('vendor_id', $this->vendor_id)->where('status', 1)->first();
            $info = json_decode($setting->info);
            // 取新遊戲
            $url = 'api/gamelist';
            $url = $info->API_URL . $url;
            $header = array('token:' . $info->TOKEN);
            $result = json_decode($this->vendor_send->send('ace333_get_game_list', $url, 'GET', [], $header), true);
            if (is_array($result) && isset($result['gameList'])) {
                $vendor = Vendor::where('id', $this->vendor_id)->where('status', 1)->first();
                $newGame = [];
                foreach ($result['gameList'] as $row) {
                    $data = array();
                    $data['vendor_id'] = $this->vendor_id;
                    $data['type_id'] = 2; //先全放拉霸遊戲，gameCreditType [int] (4500 - Normal credit, 4501 - PVP credit)
                    $data['game_type'] = $row['gameId'];
                    $data['name'] = $row['gameName'];
                    $data['status'] = 1;
                    $data['remark'] = $row['gameCreditType'];
                    $data['admin'] = 0;
                    $data['valid_bet_rate'] = $vendor->valid_bet_rate;
                    $game = Game::where('vendor_id', $this->vendor_id)
                        ->where('game_type', $row['gameId'])
                        ->first();
                    if (!$game) {
                        $game = Game::create($data);

                        $agent_game = array();
                        $agent_game['agent_id'] = 1;
                        $agent_game['game_id'] = $game->id;
                        $agent_game['status'] = 0;
                        $agent_game['admin'] = $data['admin'];
                        AgentGame::create($agent_game);

                        array_push($newGame, $row['gameName']);
                        echo '+++++++++' . $row['gameName'] . '+++++++++';
                    } else {
                        echo '---------' . $row['gameName'] . '---------';
                    }
                    echo PHP_EOL;
                }
                //如有新增遊戲，傳TG
                if (count($newGame) > 0) {
                    $content = "【Ace333】新增遊戲 " . count($newGame) . " 個 " . PHP_EOL;
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
                echo $result['error'];
            }
        } catch (\Illuminate\Database\QueryException $e) {
            $info = $e->errorInfo;
            echo $info[0] . ' : ' . $info[2];
        } catch (\Exception $e) {
            echo $e->getCode() . ' : ' . $e->getMessage();
        }
    }
}