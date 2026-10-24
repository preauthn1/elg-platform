<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Helpers\VendorSend;
use App\Helpers\Common;
use App\Helpers\VendorPost;
use App\Enums\VendorEnum;
use App\Models\AgentGame;
use App\Models\Game;
use App\Models\GameName;
use App\Models\Vendor;
use App\Models\VendorInfo;
use App\Services\TelegramService;
use App\RSA;

class GetSt8GameList extends Command
{
    protected $tgService;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'get_st8_games {--vendor=}';

    protected $vendor_send;
    protected $vendor_id = VendorEnum::ST8;
    protected $action = 'st8_get_game_list';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '更新{VENDOR}遊戲列表';

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
            $vendor = $this->option('vendor') ? $this->option('vendor') : 0;

            #取得聚合所包含遊戲商
            $vendor_groups = Vendor::where('third_party_code', 'ST8')
                ->where('status', 1)
                ->pluck('id')
                ->toArray();

            if ($vendor == 0) {
                $vendors = $vendor_groups;
            } elseif (in_array($vendor, $vendor_groups)) {
                $vendors = [$vendor];
            } else {
                $vendors = [];
            }

            if (count($vendors) > 0) {
                foreach ($vendors as $vendor_id) {
                    $this->vendor_id = $vendor_id;
                    $vendor = Vendor::find($this->vendor_id);

                    echo str_replace('{VENDOR}',$vendor->name, $this->description) . PHP_EOL;

                    //遊戲列表只需要取任意一個遊戲商的就行
                    $row = VendorInfo::where('vendor_id', $this->vendor_id)->where('status', 1)->first();

                    if (is_object($row)) {
                        //將遊戲商資訊解密
                        $info = Common::decryptVendorInfo($row);
                        if (is_object($info)) {
                            //取得vendor_infos.info設定的開放遊戲商清單
                            $developer = $info->DEVELOPER;

                            $url = $info->API_URL . 'api/operator/v1/games';
                            $postData = array();
                            $postData['site'] = $info->SITE;
                            //GET
                            $sign = $this->getSign(http_build_query($postData), $info->PRIVATE_KEY);
                            $headers = array('x-st8-sign:' . $sign);
                            $response = $this->vendor_send->send($this->action, $url, 'GET', $postData, $headers);
                            $result = json_decode($response, true);
                            if (is_array($result) && isset($result['status'])) {
                                $newGame = [];
                                if (strtolower($result['status']) == 'ok') {
                                    //遊戲
                                    $games = $result['games'];
                                    //print_r($games);echo PHP_EOL;

                                    //開發商
                                    /*
                                     * png => Play'n GO
                                     * reg => Relax Gaming
                                     * nlc => Nolimit City
                                     * tog => 3 Oaks Gaming
                                     * sms => Smartsoft Gaming
                                     * ezg => Ezugi
                                     * tvb => TVBet
                                     * */
                                    $developers = $result['developers'];
                                    //print_r($developers);echo PHP_EOL;

                                    //類別
                                    $categories = $result['categories'];
                                    //print_r($categories);echo PHP_EOL;

                                    foreach ($games as $row) {
                                        /*
                                         * "fun_mode": true,
                                         * "name": "Snakebite",
                                         * "code": "png_snakebite",
                                         * "bonus_types": [
                                         *      "free_bets"
                                         * ],
                                         * "category": "Video Slots",
                                         * "deprecation_date": null,
                                         * "developer": "Play'n Go",
                                         * "enabled": true,
                                         * "features": [],
                                         * "release_date": null,
                                         * "rtp": null,
                                         * "themes": []
                                         * */

                                        //$key = substr($row['code'], 0, 3);
                                        $key = explode('_', $row['code'])[0];
                                        if ($key == $developer) {
                                            $data = array();
                                            $data['name'] = $row['name'];
                                            //$data['status'] = ($row['enabled'] == true)? 1 : 0;
                                            $data['status'] = 1;
                                            $data['trial_mode'] = ($row['fun_mode'] == true) ? 1 : 0;
                                            $data['info'] = json_encode($row);

                                            $game = Game::where('vendor_id', $this->vendor_id)
                                                ->where('game_type', $row['code'])
                                                ->first();
                                            if (!$game) {
                                                $data['vendor_id'] = $this->vendor_id;
                                                $data['type_id'] = $this->getTypeId($row['category']);
                                                $data['game_type'] = $row['code'];
                                                $data['image'] = str_replace('{GAME_CODE}', $row['code'], $info->GAME_IMG_URL);
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

                                                array_push($newGame, $row['name']);
                                                echo '+++++++++' . $key . '++' . $row['name'] . '+++++++++';
                                            } else {
                                                echo '---------' . $key . '--' . $row['name'] . '---------';
                                            }
                                            echo PHP_EOL;
                                        }
                                    }
                                    //如有新增遊戲，傳TG
                                    if (count($newGame) > 0) {
                                        $content = "【ST8】新增遊戲 " . count($newGame) . " 個 " . PHP_EOL;
                                        $content .= "遊戲名稱 : ";
                                        $tag = '';
                                        foreach ($newGame as $name) {
                                            $content .= $name . $tag;
                                            $tag = ', ';
                                        }
                                        $this->tgService->sendMessage($content, 'new_game', '', true);
                                    }
                                    echo 'success';
                                } else {
                                    echo 'fail ';
                                }
                                echo PHP_EOL;
                            } else {
                                echo 'fail' . PHP_EOL;
                                echo 'URL:' . $url . PHP_EOL;
                                echo 'Header:';
                                print_r($headers);
                                echo PHP_EOL;
                                echo 'Response:';
                                print_r($response);
                                echo PHP_EOL;
                            }
                        } else {
                            echo $row->name . '解密失敗' . PHP_EOL;
                        }
                    }
                }
            }
        } catch (\Illuminate\Database\QueryException $exception) {
            $info = $exception->errorInfo;
            echo $info[0] . ' : ' . $info[2];
        } catch (\Exception $exception) {
            echo $exception->getCode() . ' : ' . $exception->getMessage();
        }
    }

    private function getSign($string, $key)
    {
        openssl_sign($string, $signature, $key, OPENSSL_ALGO_SHA256);
        return base64_encode($signature);
    }

    private function getTypeId($category)
    {
        switch (strtolower($category)) {
            case 'live blackjack':
            case 'live lobby':
            case 'live roulette':
            case 'live poker':
            case 'live baccarat':
            case 'live dragon tiger':
            case 'live lottery':
            case 'live sic bo':
                $type_id = 1;  //Live Casino
                break;
            case 'video slots':
                $type_id = 2;  //Slots
                break;
            case 'blackjack':
                $type_id = 3;  //Poker
                break;
            case 'lottery':
                $type_id = 4;  //Lottery
                break;
            case 'roulette':
            case 'money wheel':
            case 'crash games':
                $type_id = 15;  //Arcade
                break;
            //case 'table games':
            //case 'unknown':
            default:
                $type_id = 98;  //Event and Others
                break;
        }
        return $type_id;
    }
}