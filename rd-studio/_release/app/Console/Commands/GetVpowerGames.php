<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Helpers\Common;
use App\Helpers\VendorSend;
use App\Models\Vendor;
use App\Models\AgentGame;
use App\Models\Game;
use App\Models\VendorInfo;
use App\Services\TelegramService;

class GetVpowerGames extends Command
{
    protected $tgService;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'get_vpower_games';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '更新Vpower遊戲列表';

    protected $vendor_send;
    protected $vendor_id = 22;

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
            // 取新遊戲
            $setting = VendorInfo::where('vendor_id', $this->vendor_id)->where('status', 1)->first(); //遊戲記錄目前用同一個帳號撈就行了

            if (is_object($setting)) {
                $info = Common::decryptVendorInfo($setting);
                if (is_object($info)) {
                    $url = $info->API_URL;
                    $AppID = $info->API_APPID;
                    $time = time();
                    $Req = "Act=newgamelist&Timestamp=$time&AppId=$AppID";
                    $Signature = rawurlencode($this->hmac_sha1($Req, $info->API_KEY));
                    $url = $url . "?" . $Req . "&Signature=" . $Signature;

                    $header = array('Content-Type:application/json');
                    echo $response = $this->vendor_send->send('vpower_get_gamelist', $url, 'GET', [], $header);
                    $result = json_decode($response, true);
                    if (is_array($result) && $result['ErrorCode'] === 0) {
                        // 先判斷有沒有新遊戲，有就新增
                        $this->insertGame($result['GameList']);
                        // 判斷有沒有遊戲，廠商已關閉，game沒有關閉
                        if (count($result['GameList']) > 0) {
                            $this->closeGame($result['GameList']);
                        }
                    }
                }
            }
        } catch (\Illuminate\Database\QueryException $e) {
            $info = $e->errorInfo;
            echo $info[0] . ' : ' . $info[2];
        } catch (\Exception $e) {
            echo $e->getCode() . ' : ' . $e->getMessage();
        }
    }

    private function insertGame($gameList)
    {
        $open_games = [];
        $vendor = Vendor::where('id', $this->vendor_id)->where('status', 1)->first();
        $newGame = [];
        foreach ($gameList as $row) {
            $data = array();
            $data['vendor_id'] = $this->vendor_id;
            if (!$row['GameOCode']) {
                echo 'GameOCode is null ' . $row['GameName'] . ' (' . $row['GameID'] . ') ';
                continue;
            }
            if ($row['GameType'] == 'fishing') {
                $data['type_id'] = '5'; //捕魚機
            } else if ($row['GameType'] == 'multiplayer') {
                $data['type_id'] = '15'; //街機遊戲
            } else if ($row['GameType'] == 'slot') {
                $data['type_id'] = '2'; //拉霸遊戲
            }
            $data['game_type'] = $row['GameOCode'];
            $data['name'] = $row['GameName_EN'];
            $data['image'] = $row['Image1'];
            $data['status'] = 1;
            $data['remark'] = $row['GameID'];
            $data['admin'] = 0;
            $data['valid_bet_rate'] = $vendor->valid_bet_rate;
            $game = Game::where('vendor_id', $this->vendor_id)
                ->where('game_type', $row['GameOCode'])
                ->where('remark', $row['GameID'])
                ->first();
            if ($row['GameID'] && !$game) {
                $game = Game::create($data);

                $agent_game = array();
                $agent_game['agent_id'] = 1;
                $agent_game['game_id'] = $game->id;
                $agent_game['status'] = 0;
                $agent_game['admin'] = $data['admin'];
                AgentGame::create($agent_game);

                array_push($newGame, $row['GameName_EN']);
                echo '+++++++++' . $row['GameName'] . '+++++++++';
            } else {
                if (!$game->status) {
                    $open_games[] = $row['GameName'] . '(' . $row['GameName_EN'] . ')-' . $row['GameOCode'];
                    echo '*********' . $row['GameName'] . '*********';
                } else {
                    $game->update([
                        'image' => $row['Image1']
                    ]);
                    echo '######' . $game->name . $row['Image1'] . '######';
                }
                echo '---------' . $row['GameName'] . '---------';
            }
            echo PHP_EOL;
        }
        //如有新增遊戲，傳TG
        if (count($newGame) > 0) {
            $content = "【Vpower】新增遊戲 " . count($newGame) . " 個 " . PHP_EOL;
            $content .= "遊戲名稱 : ";
            foreach ($newGame as $name) {
                $content .= $name;
                if ($name !== end($newGame)) {
                    $content .= ", ";
                }
            }
            $this->tgService->sendMessage($content, 'new_game', '', true);
        }
        echo PHP_EOL . 'Open Game List : ' . PHP_EOL . join(PHP_EOL, $open_games);
    }

    private function closeGame($result)
    {
        $opengame = Game::where('vendor_id', 22)->where('status', 1)->get();
        foreach ($opengame as $dbrow) {
            $exists = false;
            foreach ($result as $apirow) {
                if ($apirow['GameOCode'] == $dbrow['game_type']) {
                    $exists = true;
                    break;
                }
            }
            if (!$exists) {
                Game::where('game_type', $dbrow['game_type'])->where('remark', $dbrow['remark'])->where('vendor_id', 22)->update(['status' => 0]);
                echo '/////////' . $apirow['GameName'] . '/////////' . PHP_EOL;
            }
        }
    }

    private function hmac_sha1($str, $key)
    {
        $signature = "";
        if (function_exists('hash_hmac')) {
            $signature = base64_encode(hash_hmac("sha1", $str, $key, true));
        } else {
            dump('000000');
            $blocksize = 64;
            $hashfunc = 'sha1';
            if (strlen($key) > $blocksize) {
                $key = pack('H*', $hashfunc($key));
            }
            $key = str_pad($key, $blocksize, chr(0x00));
            $ipad = str_repeat(chr(0x36), $blocksize);
            $opad = str_repeat(chr(0x5c), $blocksize);
            $hmac = pack(
                'H*', $hashfunc(
                    ($key ^ $opad) . pack(
                        'H*', $hashfunc(
                            ($key ^ $ipad) . $str
                        )
                    )
                )
            );
            $signature = base64_encode($hmac);
        }
        return $signature;
    }
}
