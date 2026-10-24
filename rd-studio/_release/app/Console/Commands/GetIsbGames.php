<?php

namespace App\Console\Commands;

use App\Models\Vendor;
use Illuminate\Console\Command;
use App\Helpers\Common;
use App\Models\Game;
use App\Models\VendorInfo;
use App\Models\AgentGame;
use DB;
use App\Services\TelegramService;

class GetIsbGames extends Command
{
    protected $tgService;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'get_isb_games';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '更新iSB遊戲列表';

    protected $vendor_id = 14;

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
            // 取新遊戲
            $setting = VendorInfo::where('vendor_id', $this->vendor_id)->where('status', 1)->first(); //遊戲記錄目前用同一個帳號撈就行了

            if (is_object($setting)) {
                $info = Common::decryptVendorInfo($setting);
                if (is_object($info)) {
                    $url = $info->GAME_LIST_URL;
                    $result = simplexml_load_file($url);
                    if (is_object($result) && isset($result->games)) {
                        $image_url = (string) $result->images->attributes()->url;
                        $newGame = [];
                        $vendor = Vendor::where('id', $this->vendor_id)->where('status', 1)->first();
                        foreach ($result->games->c->g as $row) {
                            $data = array();
                            $data['vendor_id'] = $this->vendor_id;
                            $row = $row->attributes();
                            $type = (string) $row['type'];
                            $data['type_id'] = ($type == 'slot') ? 2 : 0; //如果不是SLOT拉霸遊戲，就放0
                            $data['game_type'] = (string) $row['id'];
                            $data['name'] = (string) $row['n'];
                            $data['image'] =  $image_url . (string) $row['img'];
                            $data['status'] = 1;
                            $data['remark'] = (string) $row['i'];
                            $data['admin'] = 0;
                            $data['valid_bet_rate'] = $vendor->valid_bet_rate;

                            $game = Game::where('vendor_id', $this->vendor_id)
                                ->where('game_type', (string)$row['id'])
                                ->first();
                            if (!$game) {
                                if ((string)$row['img'] != '') {
                                    $game = Game::create($data);

                                    $agent_game = array();
                                    $agent_game['agent_id'] = 1;
                                    $agent_game['game_id'] = $game->id;
                                    $agent_game['status'] = 0;
                                    $agent_game['admin'] = $data['admin'];
                                    AgentGame::create($agent_game);

                                    array_push($newGame, (string)$row['n']);
                                    echo '+++++++++' . (string)$row['n'] . '+++++++++';
                                }
                            } else {
                                $game->update(['image' => $image_url . (string)$row['img']]);
                                echo '---------' . (string)$row['n'] . '---------';
                            }
                            echo PHP_EOL;
                        }
                        //如有新增遊戲，傳TG
                        if (count($newGame) > 0) {
                            $content = "【Isb】新增遊戲 " . count($newGame) . " 個 " . PHP_EOL;
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
                        echo $result['error'];
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
}