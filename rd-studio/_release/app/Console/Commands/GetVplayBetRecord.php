<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Helpers\Common;
use App\Helpers\VendorSend;
use App\Models\VendorInfo;
use App\Models\VplayBetRecord;
use DB;

class GetVplayBetRecord extends Command
{

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'vplay_bet_record {--start=} {--end=}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '更新vplay投注記錄';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    protected $vendorsend;

    public function __construct()
    {
        parent::__construct();
        $this->vendorsend = new VendorSend(['json_post' => true]);
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
                : date('Y-m-d H:i:s', strtotime('+7hour'));
            $end = $this->option('end')
                ? str_replace('@', ' ', $this->option('end'))
                : date('Y-m-d H:i:s', strtotime('+8hour'));
            echo $from . ' to ' . $end . PHP_EOL;
            $setting = VendorInfo::where('vendor_id', 8)->where('status', 1)->get();
            $used = []; //已執行過的不再執行
            foreach ($setting as $row) {
                $info = json_decode($row->info);
                if (is_object($info) && !in_array($info->SECRET, $used)) {
                    $this->getRecord($from, $end, $info);
                    $used[] = $info->SECRET;
                }
            }

            //total bet record
            echo '===== total bet record Start =====' . PHP_EOL;
            DB::select('call SP_transfer_bet_record_vplay(null, null, null)');
            echo '===== total bet record End =====' . PHP_EOL;
        } catch (\Illuminate\Database\QueryException $e) {
            $info = $e->errorInfo;
            echo $info[0] . ' : ' . $info[2];
        } catch (\Exception $e) {
            echo $e->getCode() . ' : ' . $e->getMessage();
        }
    }

    private function getRecord($from, $end, $info, $page = 1, $pageSize = 500)
    {
        try {
            $url = $info->API_URL . 'MobileApp/GameWinLoseRecord.ashx' . '?platform=' . $info->PLATFORM . '&timestamp=' . date('U');
//            $url = 'MobileApp/GameWinLoseRecord.ashx';
            // 投注記錄時間為+8時區，我們SERVER為+0
            $data = array();
            $data['GamePlatform'] = $info->GAME_PLATFORM;
            $data['StartDate'] = $from;
            $data['EndDate'] = $end;
            $data['PageIndex'] = $page;
            $data['PageSize'] = $pageSize;
            $header = array('Content-Type:application/json');
            $result = json_decode($this->vendorsend->send('vplay_bet_record', $url, 'POST', $data, $header), true);
//            $result = $this->post($url, $data);
            if (is_array($result) && $result['ResultCode'] == 1) {
                foreach ($result['ResultValue'] as $row) {
                    $data = [
                        'WinloseID' => $row['WinLoseID'],
                        'MemberAccount' => $row['MemberAccount'],
                        'AgentAccount' => $row['AgentAccount'],
                        'GameTypeID' => $row['GameTypeID'],
                        'GameTypeName' => $row['GameTypeName'],
                        'GameID' => $row['GameID'],
                        'GameENAME' => $row['GameEName'],
                        'GameNameZHCN' => $row['GameNameZHCN'],
                        'Bet' => $row['Bet'],
                        'WinLose' => $row['WinLose'],
                        'GongPoint' => $row['GongPoint'],
                        'BetLM' => $row['BetLM'],
                        'WinLoseLM' => $row['WinLoseLM'],
                        'GetJPMoney' => $row['GetJPMoney'],
                        'BetTime' => $row['BetTime'],
                        'utc_bet_time' => date('Y-m-d H:i:s', strtotime($row['BetTime'] . ' -8hour')),
                        'ReCalcuTime' => $row['ReCalcuTime'],
                        'utc_recalcu_time' => date('Y-m-d H:i:s', strtotime($row['ReCalcuTime'] . ' -8hour')),
                        'BetState' => $row['BetState'],
                        'Memo' => $row['Memo'],
                    ];
                    $record = VplayBetRecord::where('WinloseID', $row['WinLoseID'])->first();
                    if (!is_object($record)) {
                        VplayBetRecord::create($data);
                        echo '------' . $row['WinLoseID'] . '-----' . PHP_EOL;
                    }
                }
                //如果筆數超過一頁就call下一頁
                if ($result['TotalRecord'] > ($page * $pageSize)) {
                    $this->getRecord($from, $end, $info, ($page + 1));
                }
                echo 'success' . PHP_EOL;
            } else {
                echo 'fail' . PHP_EOL;
            }
        } catch (\Illuminate\Database\QueryException $e) {
            $info = $e->errorInfo;
            echo $info[0] . ' : ' . $info[2];
        } catch (\Exception $e) {
            echo $e->getCode() . ' : ' . $e->getMessage();
        }
    }

//    private function post($url, $data)
//    {
//        Common::log(0, $data, 'vplay_bet_record');
//        $ch = curl_init();
//        curl_setopt($ch, CURLOPT_URL, env('VPLAY_URL') . $url . '?platform=' . env('VPLAY_PLATFORM') . '&timestamp=' . date('U'));
//        curl_setopt($ch, CURLOPT_POST, TRUE);
//        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
//        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
//            'Content-Type:application/json',
//        ));
//        curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
//        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
//        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
//        $result = curl_exec($ch);
//        Common::log(0, $result, 'vplay_bet_record');
//        curl_close($ch);
//        return json_decode($result, true);
//    }
}