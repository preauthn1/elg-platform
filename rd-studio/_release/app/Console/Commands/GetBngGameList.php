<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Helpers\VendorSend;
use App\Helpers\Common;
use App\Helpers\VendorPost;
use App\Models\AgentGame;
use App\Models\Game;
use App\Models\GameName;
use App\Models\Vendor;
use App\Models\VendorInfo;
use App\Services\TelegramService;

class GetBngGameList extends Command
{
    protected $tgService;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'get_bng_games {--provider_id=}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '更新Booongo遊戲列表';

    protected $vendor_send;
    protected $vendor_id = 49;
    protected $action = 'booongo_get_game_list';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
        $this->vendor_send = new VendorSend(['json_post' => true]);
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
            $provider_id = $this->option('provider_id') ? $this->option('start') : '';
            //遊戲列表只需要取任意一個遊戲商的就行
            $row = VendorInfo::where('vendor_id', $this->vendor_id)->where('status', 1)->first();
            if (is_object($row)) {
                //將遊戲商資訊解密
                $info = Common::decryptVendorInfo($row);
                if (is_object($info)) {
                    $url = $info->API_URL . $info->PROJECT_NAME . '/api/v1/game/list';
                    $postData = array();
                    $postData['api_token'] = $info->API_TOKEN;
                    $postData['provider_id'] = $provider_id;

                    $headers = array('Content-Type:application/json');
                    $response = $this->vendor_send->send($this->action, $url, 'POST', $postData, $headers);
                    $result = json_decode($response, true);
                    if (is_array($result) && !isset($result['error'])) {
                        $vendor = Vendor::where('id', $this->vendor_id)->where('status', 1)->first();
                        $newGame = [];
                        if (is_object($vendor)) {
                            foreach ($result['items'] as $row) {
                                $data = array();
                                $data['name'] = $row['i18n']['en']['title'];

                                $game = Game::where('vendor_id', $this->vendor_id)
                                    ->where('game_type', $row['game_id'])
                                    ->first();
                                if (!$game) {
                                    $data['vendor_id'] = $this->vendor_id;
                                    $data['type_id'] = ($row['type'] == 'SLOT') ? 2 : 0;
                                    $data['game_type'] = $row['game_id'];
                                    $data['image'] = $row['i18n']['en']['banner_path'];
                                    $data['status'] = 1;
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

                                    array_push($newGame, $row['i18n']['en']['title']);
                                    echo '+++++++++' . $row['i18n']['en']['title'] . '+++++++++';
                                } else {
                                    echo '---------' . $row['i18n']['en']['title'] . '---------';
                                }
                                echo PHP_EOL;

                                if (isset($game->id)) {
                                    if (isset($row['i18n']['en']['title']) && $row['i18n']['en']['title'] != '') {
                                        $game_name = GameName::where('game_id', $game->id)
                                            ->where('language_id', 1)
                                            ->first();
                                        if (!is_object($game_name)) {
                                            GameName::create([
                                                'language_id' => 1,
                                                'game_id' => $game->id,
                                                'name' => $row['i18n']['en']['title'],
                                                'admin' => 0
                                            ]);
                                        }
                                    }
                                    if (isset($row['i18n']['zh-hant']['title']) && $row['i18n']['zh-hant']['title'] != '') {
                                        $game_name = GameName::where('game_id', $game->id)
                                            ->where('language_id', 2)
                                            ->first();
                                        if (!is_object($game_name)) {
                                            GameName::create([
                                                'language_id' => 2,
                                                'game_id' => $game->id,
                                                'name' => $row['i18n']['zh-hant']['title'],
                                                'admin' => 0
                                            ]);
                                        }
                                    }
                                    if (isset($row['i18n']['zh']['title']) && $row['i18n']['zh']['title'] != '') {
                                        $game_name = GameName::where('game_id', $game->id)
                                            ->where('language_id', 3)
                                            ->first();
                                        if (!is_object($game_name)) {
                                            GameName::create([
                                                'language_id' => 3,
                                                'game_id' => $game->id,
                                                'name' => $row['i18n']['zh']['title'],
                                                'admin' => 0
                                            ]);
                                        }
                                    }
                                }
                            }
                            //如有新增遊戲，傳TG
                            if (count($newGame) > 0) {
                                $content = "【Booongo】新增遊戲 " . count($newGame) . " 個 " . PHP_EOL;
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
        } catch (\Illuminate\Database\QueryException $e) {
            $info = $e->errorInfo;
            echo $info[0] . ' : ' . $info[2];
        } catch (\Exception $e) {
            echo $e->getCode() . ' : ' . $e->getMessage();
        }
    }

    private function hash($info, $message)
    {
        $json_str = json_encode($message, JSON_UNESCAPED_UNICODE);
        return hash_hmac("SHA256", utf8_encode($json_str), $info->API_KEY);
    }
}