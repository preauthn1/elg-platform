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
use App\Models\VendorInfo;
use App\Services\TelegramService;
use DB;

class GetPgsswGameList extends Command
{
    protected $tgService;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'get_pgssw_games {--update=} {--vendor=}';
    // php artisan get_pgssw_games --update=name,image

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '更新{VENDOR}遊戲列表';

    protected $vendor_send;
    protected $vendor_id = 50;

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
            $update_columns = $this->option('update')
                ? explode(',', $this->option('update'))
                : array();

            $vendor = $this->option('vendor') ? $this->option('vendor') : 0;

            #取得聚合所包含遊戲商
            $vendor_groups = Vendor::where('third_party_code', 'PGSSW')
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

                    echo str_replace('{VENDOR}', $vendor->name, $this->description) . PHP_EOL;

                    // 取新遊戲
                    //遊戲列表只需要取任意一個遊戲商的就行
                    $setting = VendorInfo::where('vendor_id', $this->vendor_id)->where('status', 1)->first();

                    if (is_object($setting)) {
                        $info = Common::decryptVendorInfo($setting);
                        if (is_object($info)) {
                            $url = $info->API_URL . 'games';
                            $newGame = [];
                            $post_data = array();
                            $post_data['merchantID'] = $info->API_APPID;
                            $post_data['MerchantSecretKey'] = $info->API_KEY;
                            $response = $this->vendor_send->send('pgssw_games', $url, 'GET', $post_data);
                            $result = json_decode($response, true);
                            if (is_array($result) && $result['code'] === 0) {
                                foreach ($result['data'] as $row) {
                                    $game = Game::where('vendor_id', $this->vendor_id)
                                        ->where('game_type', $row['gameID'])
                                        ->first();
                                    if ($row['gameID'] && !$game) {
                                        $data = array();
                                        $data['vendor_id'] = $this->vendor_id;
                                        $data['game_type'] = $row['gameID'];
                                        $data['type_id'] = $this->getTypeId($row['gameType']);
                                        $data['name'] = $row['gameName'];
                                        $data['image'] = $row['imageUrl'];
                                        $data['status'] = 1;
                                        $data['remark'] = $row['gameCode'];
                                        if (isset($row['isPrePaidMode']) && $row['isPrePaidMode'] == true) {
                                            $data['memo'] = 'isPrePaidMode';
                                            $data['pre_paid_mode'] = 1;
                                        }
                                        $data['admin'] = 51;
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
                                        $update_data = array();
                                        $update_data['info'] = json_encode($row);
                                        $update_data['remark'] = $row['gameCode'];
                                        if (in_array('name', $update_columns)) {
                                            $update_data['name'] = $row['gameName'];
                                        }
                                        if (in_array('image', $update_columns)) {
                                            $update_data['image'] = $row['imageUrl'];
                                        }
                                        $game->update($update_data);
                                        echo '---------' . $row['gameName'] . '---------';
                                    }
                                    echo PHP_EOL;

                                    if (isset($game->id)) {
                                        if (isset($row['gameNameMappings']['languageCode']) && count($row['gameNameMappings']['languageCode']) > 0) {
                                            foreach ($row['gameNameMappings']['languageCode'] as $MappingData) {
                                                if($MappingData['languageCode'] == 'zh-cn'){
                                                    $game_name = GameName::where('game_id', $game->id)
                                                        ->where('language_id', 2)
                                                        ->first();
                                                    if (!is_object($game_name)) {
                                                        GameName::create([
                                                            'language_id' => 2,
                                                            'game_id' => $game->id,
                                                            'name' => $MappingData['text'],
                                                            'admin' => 0
                                                        ]);
                                                    }

                                                    $game_name = GameName::where('game_id', $game->id)
                                                        ->where('language_id', 3)
                                                        ->first();
                                                    if (!is_object($game_name)) {
                                                        GameName::create([
                                                            'language_id' => 3,
                                                            'game_id' => $game->id,
                                                            'name' => $MappingData['text'],
                                                            'admin' => 0
                                                        ]);
                                                    }
                                                }
                                            }
                                        }
                                    }
                                }
                            } else {
                                echo 'NULL' . PHP_EOL;
                            }

                            //如有新增遊戲，傳TG
                            if (count($newGame) > 0) {
                                $content = "【" . $vendor->name . "】新增遊戲 " . count($newGame) . " 個 " . PHP_EOL;
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
                            echo ' End...' . PHP_EOL;
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

    private function getTypeId($game_type)
    {
        switch ($game_type) {
            case '2500':
                $type_id = 1;  //Live Casino
                break;
            case '1000':
                $type_id = 2;  //Slots
                break;
            case '2000':
                $type_id = 3;  //Poker
                break;
            case '7000':
            case '5000':
                $type_id = 4;  //Lottery
                break;
            case '3000':
            case '4000':
            case '6000':
                $type_id = 15;  //Arcade
                break;
            default:
                $type_id = 98;  //Event and Others
                break;
        }
        return $type_id;
    }
}
