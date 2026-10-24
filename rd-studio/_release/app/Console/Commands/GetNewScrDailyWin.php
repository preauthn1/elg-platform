<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Helpers\Common;
use App\Helpers\VendorSend;
use App\Models\ScrDailyWin;
use App\Models\VendorInfo;
use DB;

class GetNewScrDailyWin extends Command
{

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'new_scr_daily_win {--date=}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '更新918 kiss每日贏分';

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
            $date = $this->option('date') ? $this->option('date') : date('Y-m-d', strtotime('+7hours50minute'));
            echo $date;
            $settings = VendorInfo::where('vendor_id', 3)->where('status', 1)->get();
            $used = []; //已執行過的不再執行
            foreach ($settings as $row) {
                //將遊戲商資訊解密
                $info = Common::decryptVendorInfo($row);
                $used_key = $info->API_USER . "_" . $info->API_PASSWORD;
                if (is_object($info) && !in_array($used_key, $used)) {
                    $this->addRecord($info, $date, $row->id);
                    $used[] = $used_key;
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


    private function addRecord($info, $date, $vendor_info_id)
    {
        $url = $info->REPORT_HOST . 'AgentTotalReport.ashx';
        $data = array();
        $data['userName'] = $info->AGENT_NAME;
        $data['sDate'] = $date . ' 00:00:00';
        $data['eDate'] = $date . ' 23:59:59';
        $data['Type'] = 'ServerTotalReport';
        $data['time'] = date('U') . '000';
        $data['authcode'] = $info->AUTH_CODE;
        $data['sign'] = $this->sign($data['authcode'] . $data['userName'] . $data['time'] . $info->SECRET_KEY);
        $result = json_decode($this->vendorsend->send('new_scr_get_daily_win', $url, 'POST', $data), true);
//        $result = $this->post($info, $data, $url);
        if (is_array($result) && isset($result['results'])) {
            ScrDailyWin::where('date', $date)->where('vendor_info_id', $vendor_info_id)->delete();
            foreach ($result['results'] as $row) {
                $data = array();
                $data['vendor_info_id'] = $vendor_info_id;
                $data['date'] = $date;
                $data['player_id'] = $row['Account'];
                $data['win'] = $row['win'];
                ScrDailyWin::create($data);
                echo '*****' . $row['Account'] . '*****';
            }
            echo 'sucess';
        } else {
            echo 'fail';
        }
    }

    private function sign($string)
    {
        return  strtoupper(md5(strtolower($string)));
    }
    private function post($info, $data, $url)
    {
        Common::log(0, $data, 'scr_daily_win');
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $info->REPORT_HOST . $url);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        curl_setopt($ch, CURLOPT_POST, TRUE);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        $result = curl_exec($ch);
        curl_close($ch);
        Common::log(0, $result, 'scr_daily_win');
        return json_decode($result, true);
    }
}