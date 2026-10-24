<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Helpers\Common;
use App\Helpers\VendorSend;
use App\Models\IaBetRecord;
use App\Models\VendorInfo;
use DB;

class GetIaBetRecord extends Command
{

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'ia_bet_record {--start=}'; //每次跑一個小時 php artisan ia_bet_record --start=2020-03-01@00

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '更新小艾投注紀錄';

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
            $date = $this->option('start')
                ? str_replace('@', ' ', $this->option('start')) . ':00:00'
                : date('Y-m-d H:i:00', strtotime('+7hours+30minutes'));
            echo $date . PHP_EOL;
            $data = array();
            $data['end_time'] = strtotime($date . '-7hour');
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
                    echo $row->name . '解密失敗' . PHP_EOL;
                }
            }

            //total bet record
            echo '===== total bet record Start =====' . PHP_EOL;
            DB::select('call SP_transfer_bet_record_ia(null, null, null)');
            echo '===== total bet record End =====' . PHP_EOL;
        } catch (\Illuminate\Database\QueryException $e) {
            $info = $e->errorInfo;
            echo $info[0] . ' : ' . $info[2];
        } catch (\Exception $e) {
            echo $e->getCode() . ' : ' . $e->getMessage();
        }
    }

    private function addRecord($info, $postData)
    {
        $url = $info->API_URL . '/wager/getactivitylog';
        $result = $this->decrypt($info, $this->vendorsend->send('ia_get_bet_record', $url, 'POST', $postData, ['pch:' . $info->API_PCH]));
//        $result = $this->post($info, $postData, $url);
        if (is_object($result) && $result->status === 1) {
            foreach ($result->data->list as $row) {
                $data = array();
                $data['rowID'] = $row->id;
                $data['activity_id'] = $row->activity_id;
                if ($row->amount < 0) {
                    $data['betamount'] = (0 - $row->amount);
                    $data['winamount'] = 0;
                } else {
                    $data['betamount'] = 0;
                    $data['winamount'] = $row->amount;
                }
                $data['money_type'] = $row->money_type;
                $data['currency_id'] = $row->currency_id;
                $data['exchange_rate'] = $row->exchange_rate;
                $data['show_scale'] = $row->show_scale;
                $data['desc'] = $row->desc;
                $data['create_time'] = $row->create_time;
                $data['username'] = $row->username;
                $data['userip'] = $row->userip;
                $data['cdnip'] = $row->cdnip;
                $data['client_type'] = $row->client_type;
                $data['order_id'] = $row->order_id;
                $data['activity_name'] = $row->activity_name;
                $gamedate = $row->create_time;
                $data['utc_create_time'] = date("Y-m-d H:i:s", strtotime("$gamedate -8hours"));

                $record = IaBetRecord::where('rowID', $row->id)->first();
                if (!is_object($record)) {
                    IaBetRecord::create($data);
                    echo '****' . $row->id . '****' . PHP_EOL;
                } 
            }
            echo 'sucess' . PHP_EOL;
        } else {
            echo 'fail' . PHP_EOL;
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
        Common::log(0, $data, 'ia_bet_record');
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
        Common::log(0, $result, 'ia_bet_record');
        curl_close($ch);
        return $this->decrypt($info, $result);
    }
}
