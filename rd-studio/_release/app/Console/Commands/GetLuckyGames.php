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

class GetLuckyGames extends Command
{
    protected $tgService;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'get_lucky_games';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '更新Lucky遊戲列表';

    protected $vendor_send;
    protected $vendor_id = 12;

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
            $settings = VendorInfo::where('vendor_id', $this->vendor_id)->where('status', 1)->first();
            $info = json_decode($settings->info);
            $url = $info->API_URL;
            // 取新遊戲
            $post_data = array();
            $post_data['cmd'] = 'getGames';
            $post_data['channel'] = $info->CHANNEL;
            $post_data['key'] = $info->API_KEY;
            $post_data['language'] = 'en';
            $post_data['cur'] = $info->CURRENCY;
            $result = json_decode($this->vendor_send->send('lucky_get_game', $url, 'POST', $post_data), true);
            if (is_array($result) && $result['code'] === 0) {
                $vendor = Vendor::where('id', $this->vendor_id)->where('status', 1)->first();
                $newGame = [];
                foreach ($result['datas'] as $row) {
                    //遊戲商有開啟的遊戲才寫入
                    if ($row['4'] == 1) {
                        $data = array();
                        $data['vendor_id'] = $this->vendor_id;
                        $data['type_id'] = 2;
                        $data['game_type'] = $row['0'];  //游戏 id
                        $data['name'] = $row['1'];  //游戏名字
                        $data['image'] = $row['2'];  //Icon 地址(284*186)
                        $data['is_new'] = 1;
                        $data['is_hot'] = 0;
                        $data['status'] = 1;
                        $data['remark'] =  $row['5'];
                        $data['admin'] = 0;
                        $data['valid_bet_rate'] = $vendor->valid_bet_rate;

                        $game = Game::where('vendor_id', $this->vendor_id)
                            ->where('game_type', $row['0'])
                            ->first();
                        if (!$game) {
                            $game = Game::create($data);

                            $agent_game = array();
                            $agent_game['agent_id'] = 1;
                            $agent_game['game_id'] = $game->id;
                            $agent_game['status'] = 0; //預設關閉
                            $agent_game['admin'] = $data['admin'];
                            AgentGame::create($agent_game);

                            array_push($newGame, $row['1']);
                            echo '+++++++++' . $row['1'] . '+++++++++';
                        } else {
                            echo '---------' . $row['1'] . '---------';
                        }
                        echo PHP_EOL;
                    }
                }
                //如有新增遊戲，傳TG
                if (count($newGame) > 0) {
                    $content = "【Lucky】新增遊戲 " . count($newGame) . " 個 " . PHP_EOL;
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
                echo $result['msg'];
            }
        } catch (\Illuminate\Database\QueryException $e) {
            $info = $e->errorInfo;
            echo $info[0] . ' : ' . $info[2];
        } catch (\Exception $e) {
            echo $e->getCode() . ' : ' . $e->getMessage();
        }
    }
}