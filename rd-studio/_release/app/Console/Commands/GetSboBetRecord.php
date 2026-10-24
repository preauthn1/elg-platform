<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Helpers\VendorSend;
use App\Helpers\Common;
use App\Models\SboBetRecord;
use App\Models\VendorInfo;
use DB;

class GetSboBetRecord extends Command
{

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'sbo_bet_record {--start=}{--end=}'; //每次跑一個小時 php artisan sbo_bet_record --start=2020-03-01@00

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '更新Sbo投注紀錄';

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
            $start_time = $this->option('start')
                ? str_replace('@', ' ', $this->option('start')) . ':00:00'
                : date('Y-m-d H:i:00', strtotime('-1hour'));
            $end_time = $this->option('end')
                ? str_replace('@', ' ', $this->option('end')) . ':00:00'
                : date('Y-m-d H:i:00', strtotime($start_time . '+59minute'));
            echo $start_time . ' to ' . $end_time . PHP_EOL;

            $settings = VendorInfo::where('vendor_id', 18)->where('status', 1)->get();
            $used = []; //已執行過的不再執行
            foreach ($settings as $row) {
                //將遊戲商資訊解密
                $info = Common::decryptVendorInfo($row);
                $rate = isset($row->rate) ? $row->rate : 1;
                if (is_object($info) && !in_array($info->OPERATOR_TOKEN, $used)) {
                    echo '======' . $row->name . '======' . PHP_EOL;
                    $records = $this->getBetRecord($info, $start_time, $end_time);
                    if (count($records) > 0) {
                        $this->addRecord($records, $rate);
                    } else {
                        echo 'no records';
                    }
                    $used[] = $info->OPERATOR_TOKEN;
                } else {
                    echo $row->name . '解密失敗' . PHP_EOL;
                }
            }

            //total bet record
            echo '===== total bet record Start =====' . PHP_EOL;
            DB::select('call SP_transfer_bet_record_sbo(null, null, null)');
            echo '===== total bet record End =====' . PHP_EOL;
        } catch (\Illuminate\Database\QueryException $e) {
            $info = $e->errorInfo;
            echo $info[0] . ' : ' . $info[2];
        } catch (\Exception $e) {
            echo $e->getCode() . ' : ' . $e->getMessage();
        }
    }

    //獲取目標時間段前一小時到後一小時
    private function getBetRecord($info, $start_time, $end_time)
    {
        $records = array();
        $pageIndex = 1;
        $sTime = $start_time;
        //API的sTime跟eTime最長間隔時間是5分鐘，所以把時間拆成5分鐘打一次API
        do {
            $continue = false;
            $eTime = date('Y-m-d H:i:00', strtotime($sTime . '+5minute'));
            if ($eTime < $end_time) {
                $continue = true;
            } else {
                $eTime = $end_time;
            }
            do {
                $nextLoop = false;
                $pageSize = 2000;  //page_size 的范围为 1000 ~ 5000。
                $result = $this->getBetRecordByPage($info, $sTime, $eTime, $pageIndex, $pageSize);
                if (is_array($result) && $result['data']['action_result'] == "Success") {
                    array_push($records, ...$result['data']['betlogs']);
                    if ($result['data']['page_count'] > $pageIndex) {
                        $nextLoop = true;
                        $pageIndex++;
                    }
                } else {
                    echo 'error_time : ' . $sTime . ' to ' . $eTime . PHP_EOL;
                    echo 'message : ' . $result['error']['message'] . PHP_EOL;
                }
            } while ($nextLoop);
            $sTime = $eTime;
        } while ($continue);

        return $records;
    }

    private function getBetRecordByPage($info, $sTime, $eTime, $pageIndex, $pageSize)
    {
        $url = $info->BET_URL . 'v3/Bet/Record/Get';

        //API要使用13位時間戳
        $sTime = strtotime($sTime) . '000';
        $eTime = strtotime($eTime) . '000';
        $data = array();
        $data['secret_key'] = $info->SECRET_KEY;
        $data['operator_token'] = $info->OPERATOR_TOKEN;
        $data['vendor_code'] = 'SBO';
        $data['start_time'] = $sTime;
        $data['end_time'] = $eTime;
        $data['page'] = $pageIndex;
        $data['page_size'] = $pageSize;

        $response = $this->vendorsend->send('sbo_get_bet_record', $url, 'POST', $data);
        $result = json_decode($response, true);

        return $result;
    }

    private function addRecord($records, $rate = 1)
    {
        foreach ($records as $row) {
            $data = array();
            $data['player_name'] = $row['player_name'];
            $data['vendor_code'] = $row['vendor_code'];
            $data['game_code'] = $row['game_code'];
            $data['parent_bet_id'] = $row['parent_bet_id'];
            $data['bet_id'] = $row['bet_id'];
            $data['trans_type'] = $row['trans_type'];
            $data['currency'] = $row['currency'];
            $data['wallet_code'] = $row['wallet_code'];
            $data['bet_amount'] = floatval($row['bet_amount'] / $rate);
            $data['win_amount'] = floatval($row['win_amount'] / $rate);
            $data['traceId'] = $row['traceId'];
            $data['create_time'] = date("Y-m-d H:i:s", ($row['created_at'] / 1000));

            $record = SboBetRecord::where('bet_id', $row['bet_id'])
                ->where('trans_type', $row['trans_type'])
                ->where('traceId', $row['traceId'])
                ->first();
            if (!is_object($record)) {
                SboBetRecord::create($data);
                echo '****' . $row['bet_id'] . '****' . PHP_EOL;
            }
        }

        echo 'success' . PHP_EOL;
    }

}