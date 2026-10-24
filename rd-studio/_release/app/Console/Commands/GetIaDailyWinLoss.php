<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Helpers\Common;
use App\Helpers\VendorSend;
use App\Models\IaDailyWinLoss;
use App\Models\VendorInfo;

class GetIaDailyWinLoss extends Command
{

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'ia_daily_win_loss {--start=}'; //每次跑一個小時 php artisan ia_daily_win_loss --start=2020-03-01@00

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '取小艾輸贏記錄';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    protected $vendorsend;
    public function __construct()
    {
        parent::__construct();
        $this->vendorsend = new VendorSend(['json_post' => 'array']);
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        try {
            $date = $this->option('start') ? str_replace('@', ' ', $this->option('start')) . ':00:00' : date('Y-m-d H:i:00', strtotime('+8hour'));
            echo $date;
            $data = array();
            $data['end_time'] = strtotime($date . '-7hour');
            $data['is_settle'] = '-1';
            $data['start_time'] = strtotime($date . '-8hour');
            $md5_str = $this->getmd5str($data);
            $data['auth_key'] = $md5_str;
            $settings = VendorInfo::where('vendor_id', 21)->where('status', 1)->get();
            $used = []; //已執行過的不再執行

            foreach ($settings as $row) {
                //將遊戲商資訊解密
                $info = Common::decryptVendorInfo($row);
                if (is_object($info) && !in_array($info->API_PCH, $used)) {
                    $params = $this->encrypt($info, $data);
                    $this->addRecord($info, $params);
                    $used[] = $info->API_PCH;
                } else {
                    echo $row->name . '解密失敗';
                }
            }
        } catch (\Illuminate\Database\QueryException $e) {
            $info = $e->errorInfo;
            echo $info[0] . ' : ' . $info[2];
        } catch (\Exception $e) {
            echo $e->getCode() . ' : ' . $e->getMessage();
        }
    }

    private function addRecord($info, $postData)
    {
        $url = $info->API_URL . '/wager/getproject';
        $result = $this->decrypt($info, $this->vendorsend->send('ia_get_bet_record', $url, 'POST', $postData, ['pch:' . $info->API_PCH]));
//        $result = $this->post($info, $postData, $url);
        if (is_object($result) && $result->status === 1) {
            foreach ($result->data->list as $row) {
                $data = array();
                $data['rowID'] = $row->id;
                $data['game_id'] = $row->game_id;
                $data['points_id'] = $row->points_id;
                $data['bonus'] = $row->bonus;
                $data['winner'] = $row->winner;
                $data['amount'] = $row->amount;
                $data['create_time'] = $row->create_time;
                $data['update_time'] = $row->update_time;
                $data['bonus_time'] = $row->bonus_time;
                $data['cancel_time'] = $row->cancel_time;
                $data['is_cancel'] = $row->is_cancel;
                $data['is_getprize'] = $row->is_getprize;
                $data['prize_status'] = $row->prize_status;
                $data['userip'] = $row->userip;
                $data['cdnip'] = $row->cdnip;
                $data['money_type'] = $row->money_type;
                $data['is_change'] = $row->is_change;
                $data['game_type_id'] = $row->game_type_id;
                $data['odds'] = $row->odds;
                $data['client_type'] = $row->client_type;
                $data['category_id'] = $row->category_id;
                $data['desc'] = $row->desc;
                $data['cancel_user'] = $row->cancel_user;
                $data['currency_id'] = $row->currency_id;
                $data['exchange_rate'] = $row->exchange_rate;
                $data['show_scale'] = $row->show_scale;
                $data['settlement_odds'] = $row->settlement_odds;
                $data['prize_winner'] = $row->prize_winner;
                $data['third_mark_id'] = $row->third_mark_id;
                $data['receive_status'] = $row->receive_status;
                $data['is_champion'] = $row->is_champion;
                $data['game_name'] = $row->game_name;
                $data['team_name_1'] = $row->team_name_1;
                $data['team_name_2'] = $row->team_name_2;
                $data['team_info_desc'] = $row->team_info_desc;
                $data['category_type'] = $row->category_type;
                $data['username'] = $row->username;
                $data['team_points'] = $row->team_points;
                $data['team_name'] = $row->team_name;
                $data['event_id'] = $row->event_id;
                //user_points要在派獎後才會有
                if (isset($row->user_points)) {
                    $data['user_points'] = $row->user_points;
                } else {
                    $data['user_points'] = 0;
                }
                $data['win_lose'] = $row->win_lose;
                $data['order_id'] = $row->order_id;
                $data['game_start_time'] = $row->game_start_time;
                $data['point_start_time'] = $row->point_start_time;
                $data['event_name'] = $row->event_name;
                $gamedate = $row->update_time;
                $data['utc_update_time'] = date("Y-m-d H:i:s", strtotime("$gamedate -8hours"));

                $record = IaDailyWinLoss::where('rowID', $row->id)->first();
                if (!is_object($record)) {
                    IaDailyWinLoss::create($data);
                    echo '****' . $row->id . '****';
                } else {
                    IaDailyWinLoss::where('rowID', $row->id)->update($data);
                    echo '****' . $row->id . '****';
                }
            }
            echo 'sucess';
        } else {
            echo 'fail';
        }
    }

    private function getmd5str($data)
    {
        $str = '';
        $time = 0;
        foreach ($data as $key => $row) {
            if ($time == 0) {
                $str .= $key . '=' . $row;
            } else {
                $str .= ',' . $key . '=' . $row;
            }
            $time++;
        }
        $str = md5($str);
        return $str;
    }

    private function encrypt($info, $params)
    {
        $params = json_encode($params);
        $cryptText = openssl_encrypt($params, "aes-256-cbc", $info->API_KEY, OPENSSL_RAW_DATA, $info->API_IV);
        $data = base64_encode($cryptText);
        return $data;
    }

    private function decrypt($info, $params)
    {
        $params = base64_decode($params);
        $cryptText = openssl_decrypt($params, "aes-256-cbc", $info->API_KEY, OPENSSL_RAW_DATA, $info->API_IV);
        return json_decode($cryptText);
    }

    private function post($info, $data, $url)
    {
        Common::log(0, $data, 'ia_daily_win_loss');
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_URL, $info->API_URL . $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('pch:' . $info->API_PCH));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        $result = curl_exec($ch);
        Common::log(0, $result, 'ia_daily_win_loss');
        curl_close($ch);
        return $this->decrypt($info, $result);
    }
}
