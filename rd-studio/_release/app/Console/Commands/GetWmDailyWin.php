<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Helpers\Common;
use App\Helpers\VendorSend;
use App\Helpers\VendorPost;
use App\Models\VendorInfo;
use App\Models\WmDailyWin;

class GetWmDailyWin extends Command
{

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'wm_daily_win {--date=}'; //wm_daily_win --date=2020-03-18

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '更新Wm每日投注紀錄';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    protected $vendorsend;

    public function __construct()
    {
        parent::__construct();
        $this->vendorsend = new VendorSend();
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        try {
            //WM 服务器时间为美东时间UTC-4，每天中午12點03撈
            $date = $this->option('date') ? $this->option('date') : date('Y-m-d', strtotime('-250 minutes'));
            // $date = date('Y-m-d', strtotime('+7hours50minute'));
            echo $date . PHP_EOL;

            $url = 'platform/feed/users/';

            $data = array();
            $data['date'] = str_replace('-', '', $date);
            $settings = VendorInfo::where('vendor_id', 16)->where('status', 1)->get();
            $used = []; //已執行過的不再執行
            foreach ($settings as $row) {
                //將遊戲商資訊解密
                $info = Common::decryptVendorInfo($row);
                if (is_object($info) && !in_array($info->LICENSEE_TOKEN, $used)) {
                    echo '======' . $row->name . '======' . PHP_EOL;
                    $post_url = $info->SERVER_NAME . $url . $info->LICENSEE_TOKEN;
                    foreach ($data as $row) {
                        $post_url .= '/' . $row;
                    }
                    $result = json_decode($this->vendorsend->send('wm_get_daily_win', $post_url, 'GET', [], ['Content-Type:application/json']), true);
                    if (is_array($result) && count($result) > 0 && !isset($result['Message'])) {
                        WmDailyWin::where('date', $date)->delete();
                        foreach ($result as $row) {
                            $data = array();
                            $data['player_name'] = $row['Identity'];
                            $data['player_token'] = $row['Token'];
                            $data['skin'] = $row['Skin'];
                            $data['currency'] = $row['Currency'];
                            $data['cash_bet'] = $row['CashBet'];
                            $data['cash_win'] = $row['CashWin'];
                            $data['bonus_bet'] = $row['BonusBet'];
                            $data['bonus_win'] = $row['BonusWin'];
                            $data['free_round_bet'] = $row['FreeRoundBet'];
                            $data['free_round'] = $row['FreeRoundWin'];
                            $data['jack_pot_bet'] = $row['JackpotBet'];
                            $data['jack_pot_win'] = $row['JackpotWin'];
                            $data['gamerounds'] = $row['GameRounds'];
                            $data['freerounds'] = $row['FreeRounds'];
                            $data['date'] = $date;

                            WmDailyWin::create($data);
                            echo '*****' . $row['Token'] . '*****';
                        }
                        echo 'sucess' . PHP_EOL;
                    } else if (is_array($result) && !isset($result['Message'])) {
                        echo 'no data' . PHP_EOL;
                    } else {
                        echo isset($result['Message']) ? $result['Message'] : 'fail';
                        echo PHP_EOL;
                    }
                    $used[] = $info->LICENSEE_TOKEN;
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
