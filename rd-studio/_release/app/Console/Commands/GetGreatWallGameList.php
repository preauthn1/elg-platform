<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Helpers\VendorSend;
use App\Helpers\Common;
use App\Helpers\VendorPost;
use App\Models\Vendor;
use App\Models\AgentGame;
use App\Models\VendorInfo;
use App\Models\Game;
use App\Services\TelegramService;

class GetGreatWallGameList extends Command
{
    protected $tgService;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'great_wall_game_list';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '更新GW99遊戲列表';

    protected $vendor_send;
    protected $vendor_id = 5;

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
        $this->vendor_send = new VendorSend(['json_post'=>true]);
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
            $url = 'Api/IGame/GameList';

            //遊戲列表只需要取任意一個遊戲商的就行
            $setting = VendorInfo::where('vendor_id', $this->vendor_id)->where('status', 1)->first();
            if (is_object($setting)) {
                //將遊戲商資訊解密
                $info = Common::decryptVendorInfo($setting);
                if (is_object($info)) {
                    $post_data = [];
                    $url = $info->API_URL . $url;
                    $header = array(
                        'merchantName:' . $info->API_MERCHANT_NAME,
                        'signature:' . $this->GreatWallSign($post_data, $info->API_KEY),
                        'Content-Type:application/json'
                    );
                    $result = json_decode($this->vendor_send->send('great_wall_get_game_list', $url, 'POST', $post_data, $header), true);
                    if (is_array($result) && $result['result']['code'] === '0') {
                        $vendor = Vendor::where('id', $this->vendor_id)->where('status', 1)->first();
                        $newGame = [];
                        foreach ($result['data'] as $row) {
                            $data = array();
                            $data['vendor_id'] = $this->vendor_id;
                            $data['type_id'] = 2; //先全放拉霸遊戲，gameCreditType [int] (4500 - Normal credit, 4501 - PVP credit)
                            $data['game_type'] = $row['gameID'];
                            $data['name'] = $row['gameName'];
                            $data['status'] = 1;
                            $data['remark'] = $row['gameTypeName'];
                            $data['admin'] = 0;
                            $data['valid_bet_rate'] = $vendor->valid_bet_rate;
                            $game = Game::where('vendor_id', $this->vendor_id)
                                ->where('game_type', $row['gameID'])
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
                            $content = "【GW99】新增遊戲 " . count($newGame) . " 個 " . PHP_EOL;
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
                        echo 'fail';
                    }
                } else {
                    echo $row->name . '解密失敗';
                }
            }
        } catch (\Illuminate\Database\QueryException $e) {
            $info = $e->errorInfo;
            echo $info[0] . ' : ' . $info[2];
        } catch (\Exception $e) {
            echo $e->getCode() . ' : ' . $e->getMessage();
        }
    }

    private function GreatWallSign($data, $apiKey)
    {
        $string = '';
        if (count($data) > 0) {
            foreach ($data as $key => $value) {
                $string .= $key . '=' . $value . '&';
            }
        }
        $string .= 'PrivateKey=' . $apiKey;
        $hash_hmac = hash('sha256', $string, true);
        return base64_encode($hash_hmac);
    }
}