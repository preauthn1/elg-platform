<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Helpers\VendorSend;
use App\Helpers\Common;
use App\Models\VendorInfo;
use App\Models\Game;
use App\Models\GameType;
use App\Models\Vendor;
use App\Models\AgentGame;
use App\Services\TelegramService;
use DB;

class GetJokerGames extends Command
{
    protected $tgService;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'get_joker_games';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '更新Joker遊戲列表';

    protected $vendor_send;
    protected $vendor_id = 2;
    
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
            // 取新遊
            $post_data = array();
            $post_data['Method'] = 'ListGames';
            $post_data['Timestamp'] = date('U');
            ksort($post_data);
            $url = $info->API_URL . '?AppID=' . $info->APP_ID . '&Signature=' . $this->getSign($post_data, $info);
            $result = json_decode($this->vendor_send->send('joker_get_game_list', $url, 'POST', $post_data), true);
            if (is_array($result) && isset($result['ListGames'])) {
                $type = GameType::where('status', 1)->pluck('id', 'name')->toArray();
                $vendor = Vendor::where('id', $this->vendor_id)->where('status', 1)->first();
                $newGame = [];
                foreach ($result['ListGames'] as $row) {
                    $data = array();
                    $data['vendor_id'] = $this->vendor_id;
                    $data['game_type'] = $row['GameOCode'];
                    $data['name'] = $row['GameName'];
                    $data['image'] = $row['Image1'];
                    $data['remark'] = $row['Code'];
                    // if (strpos($row['Specials'], 'new') !== false) {
                    //     $data['is_new'] = 1;
                    // }
                    // if (strpos($row['Specials'], 'hot') !== false) {
                    //     $data['is_hot'] = 1;
                    // }
                    $game = Game::where('vendor_id', $this->vendor_id)
                        ->where('game_type', $row['GameOCode'])
                        ->first();
                    if (!$game) {
                        $data['type_id'] = (isset($type[$row['GameType']])) ? $type[$row['GameType']] : 0;
                        $data['status'] = 1;
                        $data['admin'] = 0;
                        $data['valid_bet_rate'] = $vendor->valid_bet_rate;
                        $game = Game::create($data);

                        $agent_game = array();
                        $agent_game['agent_id'] = 1;
                        $agent_game['game_id'] = $game->id;
                        $agent_game['status'] = 0;
                        $agent_game['admin'] = $data['admin'];
                        AgentGame::create($agent_game);

                        array_push($newGame, $row['GameName']);
                        echo '+++++++++' . $row['GameName'] . '+++++++++';
                    } else {
                        $game->update($data);
                        echo '---------' . $row['GameName'] . '---------';
                    }
                    echo PHP_EOL;
                }
                //如有新增遊戲，傳TG
                if (count($newGame) > 0) {
                    $content = "【Joker】新增遊戲 " . count($newGame) . " 個 " . PHP_EOL;
                    $content .= "遊戲名稱 : ";
                    foreach ($newGame as $name) {
                        $content .= $name;
                        if ($name !== end($newGame)) {
                            $content .= ", ";
                        }
                    }
                    $this->tgService->sendMessage($content, 'new_game', '', true);
                }
            } else {
                echo $result['Message'];
            }
        } catch (\Illuminate\Database\QueryException $e) {
            $info = $e->errorInfo;
            echo $info[0] . ' : ' . $info[2];
        } catch (\Exception $e) {
            echo $e->getCode() . ' : ' . $e->getMessage();
        }
    }

    /**
     * -取簽名
     */
    private function getSign($data, $info)
    {
        $string = '';
        foreach ($data as $key => $value) {
            $string .= $key . '=' . $value . '&';
        }
        $string = substr($string, 0, -1);
        $hash_hmac = hash_hmac("sha1", $string, $info->SECRET, true);
        return base64_encode($hash_hmac);
    }
}