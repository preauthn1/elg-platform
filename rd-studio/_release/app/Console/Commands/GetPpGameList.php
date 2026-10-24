<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Helpers\VendorSend;
use App\Helpers\Common;
use App\Helpers\VendorPost;
use App\Models\Vendor;
use App\Models\AgentGame;
use App\Models\GameName;
use App\Models\VendorInfo;
use App\Models\Game;
use App\Services\TelegramService;

class GetPpGameList extends Command
{

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'get_pp_games';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '更新PragmaticPlay遊戲列表';

    protected $vendor_id = 54;
    protected $action = 'pp_get_game_list';

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
            echo $this->description . PHP_EOL;
            //遊戲列表只需要取任意一個遊戲商的就行
            $row = VendorInfo::where('vendor_id', $this->vendor_id)->where('status', 1)->first();
            if (is_object($row)) {
                //將遊戲商資訊解密
                $info = Common::decryptVendorInfo($row);
                if (is_object($info)) {
                    $url = $info->API_URL . 'http/CasinoGameAPI/getCasinoGames';

                    $data = array();
                    $data['secureLogin'] = $info->SECURELOGIN;
                    $data['hash'] = $this->hash($info, $data);

                    $vendorSend = new VendorSend();
                    $headers = array('Content-Type: application/x-www-form-urlencoded');
                    $response = $vendorSend->send($this->action, $url, 'POST', $data, $headers);
                    print_r($response);exit;
                    $result = json_decode($response, true);
                    if (is_array($result) && $result['error'] == 0) {
                        $vendor = Vendor::where('id', $this->vendor_id)->where('status', 1)->first();
                        $newGame = [];
                        if (is_object($vendor)) {
                            $sort = 0;
                            foreach ($result['gameList'] as $row) {
                                $data = array();
                                $data['name'] = $row['gameName'];  //游戏的名称
                                $data['info'] = json_encode($row);

                                $game = Game::where('vendor_id', $this->vendor_id)
                                    ->where('game_type', $row['gameID'])
                                    ->first();
                                if (!$game) {
                                    $data['vendor_id'] = $this->vendor_id;
                                    switch ($row['gameTypeID']) {  //游戏的唯一符号标识符。
                                        case 'vs':  //Video Slots
                                        case 'cs':  //Classic Slots
                                            $type = 2;
                                            break;
                                        case 'lg':  //Live games
                                            $type = 1;
                                            break;
                                        case 'bj':  //Blackjack
                                        case 'bn':  //Baccarat
                                        case 'bc':  //Baccarat New
                                            $type = 3;
                                            break;
                                        case 'sc':  //Scratchcard
                                            $type = 98;
                                            break;
                                        default :
                                            $type = 0;
                                            break;
                                    }
                                    $data['type_id'] = $type;
                                    $data['game_type'] = $row['gameID'];  //由Pragmatic Play 提供的游戏唯一符号标识符。
                                    $data['image'] = $info->IMAGE_URL . $row['gameID'] . '.jpg';
                                    $data['sort'] = $sort;
                                    $data['status'] = 0;
                                    $data['remark'] = '';
                                    //$data['info'] = json_encode($row);
                                    //$data['memo'] = $row['typeDescriptionString'];  //游戏类型的简要说明。
                                    $data['admin'] = 0;
                                    $data['valid_bet_rate'] = $vendor->valid_bet_rate;
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
                                    $game->update([
                                        'sort' => $sort,
                                        'info' => json_encode($row)
                                    ]);
                                    echo '---------' . $row['gameName'] . '---------';
                                }
                                $sort++;
                                echo PHP_EOL;
                            }
                            //如有新增遊戲，傳TG
                            if (count($newGame) > 0) {
                                $content = "【PragmaticPlay】新增遊戲 " . count($newGame) . " 個 " . PHP_EOL;
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

    private function hash($info, $data)
    {
        ksort($data);
        $str = '';
        foreach ($data as $key => $row) {
            $str .= $key . '=' . $row;
            if (end($data) != $row) {
                $str .= '&';
            }
        }
        $str .= $info->SECRETKEY;
        return md5($str);
    }

}