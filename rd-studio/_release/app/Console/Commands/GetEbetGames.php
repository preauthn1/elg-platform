<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Game;
use App\Helpers\Common;
use App\Helpers\HttpClient;
use App\Helpers\VendorSend;
use App\Models\GameName;
use App\Models\Vendor;
use App\Models\AgentGame;
use App\RSA;
use App\Models\VendorInfo;
use App\Services\TelegramService;

class GetEbetGames extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'get_ebet_games';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '更新Ebet遊戲列表';

    protected $vendor_send;
    protected $vendor_id = 13;
    protected $tg_service;

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
        $this->vendor_send = new VendorSend(['json_post' => true]);
        $this->tg_service = New TelegramService();
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
                    $url = $info->API_URL . 'slotgamelist';

                    $newGame = [];
                    $timestamp = time();

                    $post_data = array();
                    $post_data['channelId'] = $info->CHANNEL_ID;
                    $post_data['timestamp'] = $timestamp;
                    $post_data['signature'] = $this->getSign($info->CHANNEL_ID . $timestamp, $info->PRIVATE_KEY);

                    $header = array('Content-Type:application/json');
                    $response = $this->vendor_send->send('ebet_games', $url, 'POST', $post_data, $header);
                    $result = json_decode($response, true);

                    if (is_array($result) && $result['status'] == 200) {
                        $vendor = Vendor::where('id', $this->vendor_id)->where('status', 1)->first();
                        foreach ($result['slotGames'] as $row) {
                            $data = array();
                            $data['vendor_id'] = $this->vendor_id;
                            $data['game_type'] = $row['gameID'];
                            $data['name'] = $row['english'];
                            $data['type_id'] = 2;
                            $data['is_new'] = 1;
                            $data['is_hot'] = 0;
                            $data['status'] = 0;
                            $data['remark'] = $row['providerId'];
                            $data['admin'] = 0;
                            $data['valid_bet_rate'] = $vendor['valid_bet_rate'];
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

                                array_push($newGame, $row['english']);
                                echo '+++++++++' . $row['gameID'] . '+++++++++';
                            } else {
                                echo '---------' . $row['gameID'] . '---------';

                            }
                            echo PHP_EOL;

                            if (isset($game->id)) {
                                if (isset($row['english']) && $row['english'] != '') {
                                    $game_name = GameName::where('game_id', $game->id)
                                        ->where('language_id', 1)
                                        ->first();
                                    if (!is_object($game_name)) {
                                        GameName::create([
                                            'language_id' => 1,
                                            'game_id' => $game->id,
                                            'name' => $row['english'],
                                            'admin' => 0
                                        ]);
                                    }
                                }
                                if (isset($row['chinese']) && $row['chinese'] != '') {
                                    $game_name = GameName::where('game_id', $game->id)
                                        ->where('language_id', 3)
                                        ->first();
                                    if (!is_object($game_name)) {
                                        GameName::create([
                                            'language_id' => 3,
                                            'game_id' => $game->id,
                                            'name' => $row['chinese'],
                                            'admin' => 0
                                        ]);
                                    }
                                }
                            }
                        }
                    }
                    //如有新增遊戲，傳TG
                    if (count($newGame) > 0) {
                        $content = "【Ebet】新增遊戲 " . count($newGame) . " 個 " . PHP_EOL;
                        $content .= "遊戲名稱 : ";
                        foreach ($newGame as $name) {
                            $content .= $name;
                            if ($name !== end($newGame)) {
                                $content .= ", ";
                            }
                        }
                        $this->tg_service->sendMessage($content, 'new_game', '', true);
                    }
                    echo 'success';
                }
            }
        } catch (\Illuminate\Database\QueryException $e) {
            $info = $e->errorInfo;
            echo $info[0] . ' : ' . $info[2];
        } catch (\Exception $e) {
            echo $e->getCode() . ' : ' . $e->getMessage();
        }
    }

    private function getSign($string, $key)
    {
        $rsa = new RSA('', str_replace('\\n', "\n", $key));
        return $rsa->sign($string);
    }
}
