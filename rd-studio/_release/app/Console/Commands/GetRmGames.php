<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Helpers\VendorSend;
use App\Helpers\Common;
use App\Models\VendorInfo;
use App\Models\Vendor;
use App\Models\AgentGame;
use App\Models\Game;
use App\Services\TelegramService;

class GetRmGames extends Command
{
    protected $tgService;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'get_rm_games';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '更新rm遊戲列表';

    protected $vendor_send;
    protected $vendor_id = 32;

    /**
     * Create a new command instance.
     *
     * @return void
     */
    protected $action = 'rm_get_game_list';

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
            //取得vendor info資訊
            $setting = VendorInfo::where('vendor_id', $this->vendor_id)
                ->where('status', 1)
                ->first();
            if (is_object($setting)) {
                //將遊戲商資訊解密
                $info = Common::decryptVendorInfo($setting);
                $url = $info->API_URL . 'getGames';
                $time = date('U');

                $post_data = array();
                $post_data['api_id'] = $info->API_ID;
                $post_data['timestamp'] = $time;
                $post_data['sign'] = $this->sign($info, $time);

                $header = array('Content-Type:application/x-www-form-urlencoded');
                $response = $this->vendor_send->send($this->action, $url, 'POST', $post_data, $header);
                $result = json_decode($response, true);
                if (is_array($result) && $result['code'] == '0') {
                    $vendor = Vendor::where('id', $this->vendor_id)
                        ->where('status', 1)
                        ->first();
                    $newGame = [];
                    foreach ($result['gameList'] as $list) {
                        $game_code = $list['gameCode'];

                        $data = array();
                        $data['image'] = $info->API_URL . 'images/thumb/square/' . $game_code . '.jpg';
                        $data['name'] = $list['gameName'];

                        $game = Game::where('vendor_id', $this->vendor_id)
                            ->where('game_type', $game_code)
                            ->first();
                        if (!$game) {
                            $data['vendor_id'] = $this->vendor_id;
                            $data['game_type'] = $game_code;
                            if ($list['gameType'] == 'SLOT') {
                                $data['type_id'] = 2;
                            } elseif ($list['gameType'] == 'CASINO') {
                                $data['type_id'] = 1;
                            } elseif ($list['gameType'] == 'ARCADE') {
                                $data['type_id'] = 15;
                            } elseif ($list['gameType'] == 'FISHING') {
                                $data['type_id'] = 5;
                            } else {
                                $data['type_id'] = 98;
                            }
                            $data['is_new'] = 1;
                            $data['is_hot'] = 0;
                            $data['status'] = 1;
                            //$data['remark'] = '';
                            $data['admin'] = 0;
                            $data['valid_bet_rate'] = $vendor['valid_bet_rate'];
                            $game = Game::create($data);

                            $agent_game = array();
                            $agent_game['agent_id'] = 1;
                            $agent_game['game_id'] = $game->id;
                            $agent_game['status'] = 0; //預設關閉
                            $agent_game['admin'] = $data['admin'];
                            AgentGame::create($agent_game);

                            array_push($newGame, $list['gameName']);
                            echo '+++++++++' . $game_code . '+++++++++';
                        } else {
                            $game->update($data);
                            echo '---------' . $game_code . '---------';
                        }
                        echo PHP_EOL;
                    }
                    //如有新增遊戲，傳TG
                    if (count($newGame) > 0) {
                        $content = "【RM】新增遊戲 " . count($newGame) . " 個 " . PHP_EOL;
                        $content .= "遊戲名稱 : ";
                        foreach ($newGame as $name) {
                            $content .= $name;
                            if ($name !== end($newGame)) {
                                $content .= ", ";
                            }
                        }
                        $this->tgService->sendMessage($content, 'new_game', '', true);
                    }
                    echo 'success' . PHP_EOL;
                }
            }
        } catch (\Illuminate\Database\QueryException $e) {
            $info = $e->errorInfo;
            echo $info[0] . ' : ' . $info[2];
        } catch (\Exception $e) {
            echo $e->getCode() . ' : ' . $e->getMessage() . PHP_EOL;
        }
    }

    private function sign($info, $time)
    {
        return md5(($info->API_ID . $time . $info->API_KEY));
    }
}