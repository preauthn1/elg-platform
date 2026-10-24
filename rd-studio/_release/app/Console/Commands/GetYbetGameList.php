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
use App\Models\GameName;
use App\Services\TelegramService;

class GetYbetGameList extends Command
{
    protected $tgService;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'get_ybet_games';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '更新yeebet遊戲列表';

    protected $vendor_send;
    protected $vendor_id = 52;
    protected $action = 'ybet_get_game_list';

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
            echo $this->description . PHP_EOL;
            //遊戲列表只需要取任意一個遊戲商的就行
            $row = VendorInfo::where('vendor_id', $this->vendor_id)->where('status', 1)->first();
            if (is_object($row)) {
                //將遊戲商資訊解密
                $info = Common::decryptVendorInfo($row);
                if (is_object($info)) {
                    $url = $info->API_URL . '/data/getgames';

                    $data = array();
                    $data['appid'] = $info->APP_ID;
                    $data['notifyid'] = date('U');
                    $data['sign'] = $this->sign($info, $data);

                    $url .= '?' . $this->getParam($data);

                    $response = $this->vendor_send->send($this->action, $url, 'POST', []);
                    $result = json_decode($response, true);
                    if (is_array($result) && $result['result'] == 0) {
                        $vendor = Vendor::where('id', $this->vendor_id)->where('status', 1)->first();
                        $newGame = [];
                        if (is_object($vendor)) {
                            foreach ($result['array'] as $row) {
                                $data = array();
                                $data['name'] = $row['title'];

                                $game = Game::where('vendor_id', $this->vendor_id)
                                    ->where('game_type', $row['id'])
                                    ->first();
                                if (!$game) {
                                    $data['vendor_id'] = $this->vendor_id;
                                    $data['type_id'] = 0; //遊戲商沒特別標註,都先寫0
                                    $data['game_type'] = $row['id'];
                                    $data['image'] = '';
                                    $data['status'] = 0;//他是由大廳進去,只是拿來對帳的
                                    $data['remark'] = '';
                                    $data['admin'] = 0;
                                    $data['valid_bet_rate'] = $vendor->valid_bet_rate;
                                    $game = Game::create($data);

                                    $agent_game = array();
                                    $agent_game['agent_id'] = 1;
                                    $agent_game['game_id'] = $game->id;
                                    $agent_game['status'] = 0;
                                    $agent_game['admin'] = $data['admin'];
                                    AgentGame::create($agent_game);

                                    array_push($newGame, $row['title']);
                                    echo '+++++++++' . $row['title'] . '+++++++++';
                                } else {
                                    echo '---------' .$row['title'] . '---------';
                                }
                                echo PHP_EOL;
                            }
                            //如有新增遊戲，傳TG
                            if (count($newGame) > 0) {
                                $content = "【Ybet】新增遊戲 " . count($newGame) . " 個 " . PHP_EOL;
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
                            echo 'fail: not Vendor';
                        }
                        echo PHP_EOL;
                    } else {
                        echo 'fail' . PHP_EOL;
                        echo 'URL:' . $url . PHP_EOL;
                        echo 'Header:';
                        echo PHP_EOL;
                        echo 'Response:';
                        print_r($response);
                        echo PHP_EOL;
                    }
                } else {
                    echo $row->name . '解密失敗' . PHP_EOL;
                }
            }
        } catch (\Illuminate\Database\QueryException $e) {
            $info = $e->errorInfo;
            echo $info[0] . ' : ' . $info[2];
        } catch (\Exception $e) {
            echo $e->getCode() . ' : ' . $e->getMessage();
        }
    }

    private function sign($info, $param)
    {
        ksort($param);
        $msg = $this->getParam($param);
        $msg .= '&key=' . $info->API_KEY;
        return strtolower(md5($msg));
    }

    private function getParam($param)
    {
        $msg = '';
        foreach ($param as $key => $val) {
            $msg .= $key . '=' . $val;
            if ($val != end($param)) {
                $msg .= '&';
            }
        }
        return $msg;
    }
}