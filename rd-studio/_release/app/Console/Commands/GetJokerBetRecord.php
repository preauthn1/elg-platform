<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Helpers\Common;
use App\Helpers\VendorSend;
use App\Models\VendorInfo;
use App\Models\JokerBetRecord;
use DB;

class GetJokerBetRecord extends Command
{

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'joker_bet_record {--start=} {--end=}'; //joker_bet_record --start=2019-10-01@00:00 --end=2019-10-01@01:00

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '更新Joker投注記錄';

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
            $from = $this->option('start')
                ? str_replace('@', ' ', $this->option('start'))
                : date('Y-m-d H:00', strtotime('+7hour'));
            $end = $this->option('end')
                ? str_replace('@', ' ', $this->option('end'))
                : date('Y-m-d H:00', strtotime('+9hour'));
            echo $from . ' to ' . $end . PHP_EOL;
            // 時間的分是沒用的
            $data = array();
            $data['Method'] = 'TS';
            $data['StartDate'] = $from;
            $data['EndDate'] = $end;
            $data['Timestamp'] = date('U');

            $setting = VendorInfo::where('vendor_id', 2)->where('status', 1)->get();
            $used = []; //已執行過的不再執行
            foreach ($setting as $row) {
                $info = json_decode($row->info);
                if (is_object($info) && !in_array($info->APP_ID, $used)) {
                    echo '======' . $row->name . '======' . PHP_EOL;
                    $this->addRecord($data, $info);
                    $used[] = $info->APP_ID;
                }
            }
            //total bet record
            echo '===== total bet record Start =====' . PHP_EOL;
            DB::select('call SP_transfer_bet_record_joker(null, null, null)');
            echo '===== total bet record End =====' . PHP_EOL;
        } catch (\Illuminate\Database\QueryException $e) {
            $info = $e->errorInfo;
            echo $info[0] . ' : ' . $info[2];
        } catch (\Exception $e) {
            echo $e->getCode() . ' : ' . $e->getMessage();
        }
    }

    private function addRecord($postData, $info)
    {
        ksort($postData);
        $url = $info->API_URL . '?AppID=' . $info->APP_ID . '&Signature=' . $this->getSign($postData, $info);
        $result = json_decode($this->vendorsend->send('joker_get_bet_record', $url, 'POST', $postData), true);
//        $result = $this->post($postData);
        if (is_array($result) && isset($result['data'])) {
            foreach ($result['data'] as $key => $value) {
                $data = array();
                $data['key'] = $key;
                echo '****' . $key . '****' . PHP_EOL;
                foreach ($value as $row) {
                    $data['code'] = $row['OCode'];
                    $data['username'] = $row['Username'];
                    $data['game_code'] = $row['GameCode'];
                    $data['description'] = $row['Description'];
                    $data['type'] = $row['Type'];
                    $data['amount'] = $row['Amount'];
                    $data['result'] = $row['Result'];
                    $data['bet_time'] = date('Y-m-d H:i:s', strtotime($row['Time']));
                    $data['app_id'] = $row['AppID'];
                    $record = JokerBetRecord::where('code', $row['OCode'])->first();
                    if (!is_object($record)) {
                        JokerBetRecord::create($data);
                        echo '****' . $row['OCode'] . '****' . PHP_EOL;
                    }
                }
            }
            if ($result['nextId'] != '') {
                $postData['NextId'] = $result['nextId'];
                $postData['Timestamp'] = date('U');
                ksort($postData);
                $this->addRecord($postData, $info);
            }
        } else {
            if (date('U') - $postData['Timestamp'] < 25) {
                echo $result['Message'] . PHP_EOL;
                sleep(1);
                $postData['Timestamp'] = date('U');
                ksort($postData);
                $this->addRecord($postData, $info);
            }
        }
    }

    /**
     * -取簽名
     */
    private function getSign($data, $info)
    {
        $string = '';
        foreach ($data as $key => $value) {
            $string .= $key . '=' . $value . '&';
        }
        $string = substr($string, 0, -1);
        $hash_hmac = hash_hmac("sha1", $string, $info->SECRET, true);
        return base64_encode($hash_hmac);
    }

//    private function post($data)
//    {
//        Common::log(0, $data, 'joker_bet_record');
//        $ch = curl_init();
//        curl_setopt($ch, CURLOPT_URL, env('JOKER_URL') . '?AppID=' . env('JOKER_APP_ID') . '&Signature=' . $this->getSign($data));
//        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
//        curl_setopt($ch, CURLOPT_POST, TRUE);
//        curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
//        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
//        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
//        $result = curl_exec($ch);
//        curl_close($ch);
//        Common::log(0, $result, 'joker_bet_record');
//        return json_decode($result, true);
//    }
}