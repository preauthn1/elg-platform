<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Helpers\Common;
use App\Helpers\HttpClient;
use App\Helpers\VendorSend;
use App\Models\Vendor;
use App\Models\AgentGame;
use App\Models\Game;
use App\Models\VendorInfo;
use App\Services\TelegramService;

class GetPgsGames extends Command
{
    protected $tgService;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'get_pgs_games {--update=}';
    // php artisan get_pgs_games --update=name,image

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '更新PGS遊戲列表';

    protected $vendor_send;
    protected $vendor_id = 24;

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

            // 取新遊戲
            $setting = VendorInfo::where('vendor_id', $this->vendor_id)->where('status', 1)->first(); //遊戲記錄目前用同一個帳號撈就行了

            if (is_object($setting)) {
                $info = Common::decryptVendorInfo($setting);
                if (is_object($info)) {
                    $url = $info->API_URL . 'v1/games';
                    $newGame = [];
                    $post_data = array();
                    $post_data['merchantID'] = $info->API_APPID;
                    $post_data['MerchantSecretKey'] = $info->API_KEY;
                    $headers = array("locale:en-us");
                    $response = $this->vendor_send->send('pgs_games', $url, 'GET', $post_data, $headers);
                    $result = json_decode($response, true);
                    if (is_array($result) && $result['code'] === 0) {
                        $vendor = Vendor::where('id', $this->vendor_id)->where('status', 1)->first();
                        foreach ($result['data'] as $row) {
                            $data = array();
                            $data['vendor_id'] = $this->vendor_id;
                            $data['game_type'] = $row['gameID'];
                            $data['name'] = $row['gameName'];
                            $data['image'] = $row['imageUrl'];
                            $data['status'] = 1;
                            $data['remark'] = $row['gameCode'];
                            $data['admin'] = 0;
                            $data['valid_bet_rate'] = $vendor->valid_bet_rate;

                            $game = Game::where('vendor_id', $this->vendor_id)
                                ->where('game_type', $row['gameID'])
                                ->first();
                            if ($row['gameID'] && !$game) {
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
                                $update_data['remark'] = $row['gameCode'];
                                if (in_array('name', $update_columns)) {
                                    $update_data['name'] = $row['gameName'];
                                }
                                if (in_array('image', $update_columns)) {
                                    $update_data['image'] = $row['imageUrl'];
                                }
                                $game->update($update_data);
                                echo '---------' . $row['gameName'] . '---------' ;
                            }
                            echo PHP_EOL;
                        }
                    }

                    //如有新增遊戲，傳TG
                    if (count($newGame) > 0) {
                        $content = "【target】新增遊戲 " . count($newGame) . " 個 " . PHP_EOL;
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
        } catch (\Illuminate\Database\QueryException $e) {
            $info = $e->errorInfo;
            echo $info[0] . ' : ' . $info[2];
        } catch (\Exception $e) {
            echo $e->getCode() . ' : ' . $e->getMessage();
        }
    }
}
