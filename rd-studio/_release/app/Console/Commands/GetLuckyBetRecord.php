<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Helpers\Common;
use App\Helpers\VendorSend;
use App\Models\VendorInfo;
use App\Models\LuckyBetRecord;
use DB;
use UUID;

class GetLuckyBetRecord extends Command
{

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'lucky_bet_record {--date_hour=}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '取得LUCKY遊戲記錄';

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
            $date = $this->option('date_hour')
                ? (str_replace('@', ' ', $this->option('date_hour')) . ':00:00')
                : date('Y-m-d H:i:s', strtotime('-1hour'));
            echo $date . PHP_EOL;
            $settings = VendorInfo::where('vendor_id', 12)->where('status', 1)->get();
            $used = []; //已執行過的不再執行
            foreach ($settings as $row) {
                $info = json_decode($row->info);
                if (is_object($info) && !in_array($info->CHANNEL, $used)) {
                    echo '======' . $row->name . '======' . PHP_EOL;
                    $url = $info->API_URL;
                    $data = array();
                    $data['cmd'] = 'getBetDetails';
                    $data['channel'] = $info->CHANNEL;
                    $data['key'] = $info->API_KEY;
                    $data['startTime'] = strtotime($date);
                    $data['endTime'] = strtotime($date . ' +1hour');
                    $result = json_decode($this->vendorsend->send('lucky_get_bet_record', $url, 'POST', $data), true);
                    if (is_array($result) && $result['code'] === 0) {
                        foreach ($result['datas'] as $row) {
                            //[id,userId,account,cur,mid,gid,tid,bet,win,left,isGamble,isFreeSpin,freeSpinNum,time]
                            $data = array();
                            $data = [
                                'round_id' => $row[0],
                                'userId' => $row[1],
                                'account' => str_replace('"', '', $row[2]),
                                'cur' => str_replace('"', '', $row[3]),
                                'mid' => $row[4],
                                'gid' => str_replace('"', '', $row[5]),
                                'tid' => str_replace('"', '', $row[6]),
                                'bet' => $row[7],
                                'win' => $row[8],
                                'left' => $row[9],
                                'isGamble' => $row[10],
                                'isFreeSpin' => $row[11],
                                'freeSpinNum' => $row[12],
                                'time' => $row[13],
                                'bet_time' => date('Y-m-d H:i:s', $row[13]),
                            ];
                            $record = LuckyBetRecord::where('tid', str_replace('"', '', $row[6]))->first();
                            if (!is_object($record)) {
                                LuckyBetRecord::create($data);
                                echo '******' . str_replace('"', '', $row[6]) . '******' . PHP_EOL;
                            }
                            echo '------' . str_replace('"', '', $row[6]) . '-----' . PHP_EOL;
                        }
                    } else {
                        return [
                            'code' => 9996,
                            'message' => $result
                        ];
                    }
                    $used[] = $info->CHANNEL;
                }
            }
            //total bet record
            echo '===== total bet record Start =====' . PHP_EOL;
            DB::select('call SP_transfer_bet_record_lucky(null, null, null)');
            echo '===== total bet record End =====' . PHP_EOL;
        } catch (\Illuminate\Database\QueryException $e) {
            $info = $e->errorInfo;
            return [
                'code' => $info[0],
                'message' => $info[2]
            ];
        } catch (\Exception $e) {
            return [
                'code' => $e->getCode(),
                'message' => $e->getMessage()
            ];
        }
    }

//    private function post($data)
//    {
//        Common::log(0, $data, 'get_lucky_bet_record');
//        $ch = curl_init();
//        curl_setopt($ch, CURLOPT_URL, env('LUCKY_API_URL'));
//        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
//        curl_setopt($ch, CURLOPT_POST, TRUE);
//        curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
//        curl_setopt($ch, CURLOPT_TIMEOUT, 60);
//        $result = curl_exec($ch);
//        curl_close($ch);
//        Common::log(0, $result, 'get_lucky_bet_record');
//        return json_decode($result, true);
//    }
}