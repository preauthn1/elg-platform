<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Helpers\VendorSend;
use App\Helpers\Common;
use App\Models\VendorInfo;
use App\Models\Vendor;
use App\Models\AgentGame;
use App\Models\Game;
use App\Models\GameName;

class GetSky1388Games extends Command
{

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'get_sky1388_games';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '更新sky1388遊戲列表';

    protected $vendor_send;
    protected $vendor_id = 28;

    /**
     * Create a new command instance.
     *
     * @return void
     */

    public function __construct()
    {
        parent::__construct();
        $this->vendor_send = new VendorSend();
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
                $url = $info->API_URL . '/user_game_list';

                $post_data = array();
                $post_data['agid'] = $info->ID;
                $post_data['signature'] = $this->sign($post_data, $info);
                $response = json_decode($this->vendor_send->send($this->signature, $url, 'GET', $post_data), true);
                if (is_array($response) && strtolower($response['status']) == 'ok') {
                    $vendor = Vendor::where('id', $this->vendor_id)
                        ->where('status', 1)
                        ->first();
                    foreach ($response['list'] as $list) {
                        $game_code = $list['game_code'];

                        $data = array();
                        $data['image'] = $list['game_image_url'];
                        $data['name'] = $list['game_name'];
                        $game = Game::where('vendor_id', $this->vendor_id)
                            ->where('game_type', $game_code)
                            ->first();
                        if (!$game) {
                            $data['vendor_id'] = $this->vendor_id;
                            $data['game_type'] = $game_code;
                            $data['type_id'] = 99;
                            $data['is_new'] = 1;
                            $data['is_hot'] = 0;
                            $data['status'] = 0;
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

                            echo '+++++++++' . $game_code . '+++++++++';
                        } else {
                            $game->update($data);
                            echo '---------' . $game_code . '---------';
                        }
                        echo PHP_EOL;

                        if (isset($game->id) && is_array($list['game_display_name'])) {
                            foreach ($list['game_display_name'] as $lang => $name) {
                                $language_id = 0;
                                if ($lang == 'english') {
                                    $language_id = 1;
                                } else if ($lang == 'chinese') {
                                    $language_id = 3;
                                }

                                if ($language_id > 0) {
                                    $game_name = GameName::where('game_id', $game->id)
                                        ->where('language_id', $language_id)
                                        ->first();
                                    if (!is_object($game_name)) {
                                        GameName::create([
                                            'language_id' => $language_id,
                                            'game_id' => $game->id,
                                            'name' => $name,
                                            'admin' => 0
                                        ]);
                                    }
                                }
                            }
                        }
                    }
                }
            }
        } catch (\Illuminate\Database\QueryException $e) {
            $info = $e->errorInfo;
            echo $info[0] . ' : ' . $info[2];
        } catch (\Exception $e) {
            echo $e->getCode() . ' : ' . $e->getMessage() . PHP_EOL;
        }
    }

    private function sign($data, $info)
    {
        ksort($data);
        $str = implode('', $data) . $info->PRIVATE_KEY;
        $sign = sha1($str);
        return $sign;
    }
}