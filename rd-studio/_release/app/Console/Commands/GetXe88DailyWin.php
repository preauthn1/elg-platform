<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Helpers\Common;
use App\Helpers\VendorSend;
use App\Models\VendorInfo;
use App\Models\Xe88DailyWin;
use DB;

class GetXe88DailyWin extends Command
{

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'xe88_daily_win {--date=}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '更新xe88每日贏分';

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
            $date = $this->option('date') ? $this->option('date') : date('Y-m-d', strtotime('+7hours30minute'));
            $settings = VendorInfo::where('vendor_id', 11)->where('status', 1)->get();
            $used = []; //已執行過的不再執行

            foreach ($settings as $row) {
                $info = json_decode($row->info);
                if (is_object($info) && !in_array($info->SIGNATURE_KEY, $used)) {
                    $url = $info->API_URL . 'customreport/playerreport';
                    $data = array();
                    $data['agentid'] = $info->AGENT_ID;
                    $data['startdate'] = $date . ' 00:00:00';
                    $data['enddate'] = $date . ' 23:59:59';
                    $data['perpage'] = 500;
                    $data['page'] = 1;
                    $requestbody = json_encode($data);
                    $hashdata = hash_hmac("sha256", $requestbody, $info->SIGNATURE_KEY, true);
                    $hash = base64_encode($hashdata);

                    $result = json_decode($this->vendorsend->send('xe88_get_daily_win', $url, 'POST', $data, ['hashkey: ' . $hash]), true);

//            $result = $this->post($data, $url);
                    if (is_array($result) && $result['code'] == 0) {
                        //先刪除再寫入，避免輸贏為0時，會沒有新資料傳入
                        Xe88DailyWin::where('date', $date)->delete();
                        foreach ($result['result'] as $row) {
                            $data = array();
                            $data['date'] = $date;
                            $data['username'] = $row['username'];
                            $data['bets'] = $row['bets'];
                            $data['wins'] = $row['wins'];
                            $data['turnover'] = $row['turnover'];
                            $data['houseearnings'] = $row['houseearnings'];
                            Xe88DailyWin::create($data);
                            echo '*****' . $row['username'] . '*****';
                        }
                        echo 'sucess';
                    } else {
                        echo 'fail';
                    }
                    $used[] = $info->SIGNATURE_KEY;
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

//    private function post($data, $url)
//    {
//        $requestbody = json_encode($data);
//        Common::log(0, $data, 'xe88_daily_win');
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
//        Common::log(0, $result, 'xe88_daily_win');
//        return json_decode($result, true);
//    }
}