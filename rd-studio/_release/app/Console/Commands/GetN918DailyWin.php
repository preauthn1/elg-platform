<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Helpers\Common;
use App\Helpers\VendorSend;
use App\Models\N918DailyWin;
use App\Models\VendorInfo;
use App\Models\TransactionLog;
use App\Services\TelegramService;
use DB;

class GetN918DailyWin extends Command
{
    protected $tgService;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'n918_daily_win {--start=} {--end=}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '更新N918投注記錄';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    protected $vendor_send;
    protected $vendor_id = 33;
    protected $action = 'n918_get_daily_win';

    public function __construct()
    {
        parent::__construct();
        $this->vendor_send = new VendorSend();
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
            $start_time = $this->option('start')
                ? $this->option('start')
                : date('Y-m-d', strtotime('-1day'));
            $end_time = $this->option('end')
                ? $this->option('end')
                : date('Y-m-d', strtotime('-1day'));
            echo '時間範圍:' . $start_time . ' to ' . $end_time . PHP_EOL;
            $settings = VendorInfo::where('vendor_id', $this->vendor_id)
                ->where('status', 1)
                ->get();
            $used = []; //已執行過的不再執行

            if (count($settings) > 0) {
                foreach ($settings as $setting) {
                    //將遊戲商資訊解密
                    $info = Common::decryptVendorInfo($setting);
                    if (is_object($info) && !in_array($info->SECRET_KEY, $used)) {
                        $this->addRecord($info, $start_time, $end_time);
                        $used[] = $info->SECRET_KEY;
                    } else {
                        echo $setting->name . '解密失敗' . PHP_EOL;
                    }
                }
            }
            //total bet record
//            echo '===== total bet record Start =====' . PHP_EOL;
//            DB::select('call SP_transfer_bet_record_n918(null, null, null)');
//            echo '===== total bet record End =====' . PHP_EOL;
        } catch (\Illuminate\Database\QueryException $e) {
            $info = $e->errorInfo;
            echo $info[0] . ' : ' . $info[2];
        } catch (\Exception $e) {
            echo $e->getCode() . ' : ' . $e->getMessage();
        }
    }

    private function addRecord($info, $sDate, $eDate)
    {
        $time = time() . '000';
        $sign = $this->getsign($info->AUTH_CODE . $info->AGENT_ID . $time . $info->SECRET_KEY);
        $data = array();
        $data['action'] = 'TotalReport';
        $data['userName'] = 'aaaa0001';
        $data['sDate'] = $sDate;
        $data['eDate'] = $eDate;
        $data['time'] = $time;
        $data['authcode'] = $info->AUTH_CODE;
        $data['sign'] = $sign;
        $result = $this->vendor_send->send('n918_get_daily_win', $info->API_URL, 'GET', $data);
        $records = json_decode($result, true);
        if (is_array($records) && $records['success'] == true) {
            $datas = array();
            if (count($records['data']) > 0) {
                foreach ($records['data'] as $row) {
                    $data = array();
                    $data['date'] = $sDate;
                    $data['player_id'] = $row['playerid'];
                    $data['win'] = $row['win'];
                    $datas[] = $data;
                }
                N918DailyWin::where('date', $sDate)->delete();
                //直接一次insert
                N918DailyWin::insert($datas);
                echo 'success: ';
                printf("*recordCount [%d] writeCount [%d] ", count($datas), count($datas));
                echo PHP_EOL;
                $content = "【N918DailyWin】寫入資料 ". count($datas) ." 筆 日期 : " . $sDate;
                $this->tgService->sendMessage($content, 'info', '', true);
            }
        }
    }


    private function getSign($string)
    {
        return strtoupper(md5(strtolower($string)));
    }
}