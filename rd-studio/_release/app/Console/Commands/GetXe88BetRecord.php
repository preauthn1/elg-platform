<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Helpers\Common;
use App\Helpers\VendorSend;
use App\Models\VendorInfo;
use App\Models\Xe88BetRecord;
use DB;

class GetXe88BetRecord extends Command
{

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'xe88_bet_record {--start_minute=}'; //xe88_bet_record --start_minute=2019-10-01@00:00

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '更新Xe88投注記錄';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    protected $vendorsend;
    public function __construct()
    {
        parent::__construct();
        $this->vendorsend = new VendorSend(['json_post'=>true]);
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        try {
            //數據會延遲10分鐘，一次只能抓半小時
            $from = $this->option('start_minute')
                ? str_replace('@', ' ', $this->option('start_minute'))
                : date('Y-m-d H:i:s', strtotime('+7hour 20minute'));
            echo $from . PHP_EOL;
            $settings = VendorInfo::where('vendor_id', 11)->where('status', 1)->get();
            $used = []; //已執行過的不再執行

            foreach ($settings as $row) {
                $info = json_decode($row->info);
                if (is_object($info) && !in_array($info->SIGNATURE_KEY, $used)) {
                    $data = array();
                    $data['agentid'] = $info->AGENT_ID;
                    $data['reportname'] = 'PlayerGameLog';
                    // 不能跨日
                    if (date('Y-m-d', strtotime($from)) == date('Y-m-d', strtotime($from . ' +29minute'))) {
                        $data['date'] = date('Y-m-d', strtotime($from));
                        $data['starttime'] = date('H:i:00', strtotime($from));
                        $data['endtime'] = date('H:i:59', strtotime($from . ' +29minute'));
                        $this->addRecord($data, $info, 1);
                    } else {
                        $data['date'] = date('Y-m-d', strtotime($from));
                        $data['starttime'] = '23:30:00';
                        $data['endtime'] = '23:59:59';
                        $this->addRecord($data, $info, 1);

                        $data['date'] = date('Y-m-d', strtotime($from . ' +29minute'));
                        $data['starttime'] = '00:00:00';
                        $data['endtime'] = '00:29:59';
                        $this->addRecord($data, $info, 1);
                    }
                    $used[] = $info->SIGNATURE_KEY;
                } else {
                    echo $row->name . '解密失敗' . PHP_EOL;
                }
            }
            //total bet record
            echo '===== total bet record Start =====' . PHP_EOL;
            DB::select('call SP_transfer_bet_record_xe88(null, null, null)');
            echo '===== total bet record End =====' . PHP_EOL;
        } catch (\Illuminate\Database\QueryException $e) {
            $info = $e->errorInfo;
            echo $info[0] . ' : ' . $info[2];
        } catch (\Exception $e) {
            echo $e->getCode() . ' : ' . $e->getMessage();
        }
    }

    private function addRecord($postData, $info, $page)
    {
        $postData['page'] = $page;
        $postData['perpage'] = 500;
        $url = $info->API_URL . 'customreport/playergamelog';

        $requestbody = json_encode($postData);
        $hashdata = hash_hmac("sha256", $requestbody, $info->SIGNATURE_KEY, true);
        $hash = base64_encode($hashdata);
        $result = json_decode($this->vendorsend->send('xe88_get_bet_record', $url, 'POST', $postData, ['hashkey: ' . $hash]), true);
//        $result = $this->post($postData, $url);
        if (is_array($result) && $result['code'] == '0') {
            foreach ($result['result'] as $row) {
                $data = array();
                $data['username'] = $row['username'];
                $data['game_id'] = $row['gameid'];
                $data['game_name'] = $row['gamename'];
                $data['bet'] = $row['bet'];
                $data['win'] = $row['win'];
                $data['begin_money'] = $row['beginmoney'];
                $data['end_money'] = $row['endmoney'];
                $data['game_log'] = $row['gamelog'];
                $data['log_time'] = $row['logtime'];
                $data['utc_log_time'] = date('Y-m-d H:i:s', strtotime($row['logtime'] . ' -8hour'));
                $record = Xe88BetRecord::where('username', $row['username'])
                    ->where('log_time', $row['logtime'])
                    ->where('begin_money', $row['beginmoney'])
                    ->where('game_log', $row['gamelog'])
                    ->first();
                if (!is_object($record)) {
                    Xe88BetRecord::create($data);
                    echo  '****' . $row['username'] . '****' . $row['logtime'] . '****' . PHP_EOL;
                }
            }
            if ($result['pagination']['totalpages'] > $page) {
                $this->addRecord($postData, $info, ($page + 1));
            }
        } else {
            echo $result . PHP_EOL;
        }
    }

//    private function post($data, $url)
//    {
//        $requestbody = json_encode($data);
//        Common::log(0, $data, 'xe88_bet_record');
//        $hashdata = hash_hmac("sha256", $requestbody, env('XE88_SIGNATURE_KEY'), true);
//        $hash = base64_encode($hashdata);
//        $ch = curl_init();
//        curl_setopt($ch, CURLOPT_URL, env('XE88_URL') . $url);
//        curl_setopt($ch, CURLOPT_POSTFIELDS, $requestbody);
//        curl_setopt($ch, CURLOPT_POST, TRUE);
//        curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
//        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
//        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
//            'hashkey: ' . $hash,
//        ));
//        $result = curl_exec($ch);
//
//        curl_close($ch);
//        Common::log(0, $result, 'xe88_bet_record');
//        return json_decode($result, true);
//    }
}