<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Helpers\Common;
use App\Helpers\VendorSend;
use App\Models\VendorInfo;
use App\Models\NsBetRecord;
use DB;

class GetNsBetRecord extends Command
{

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'ns_bet_record {--start=} {--end=}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '更新NextSpin投注記錄';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    protected $vendor_send;
    protected $vendor_id = 48;
    protected $action = 'nextspin_get_bet_record';

    public function __construct()
    {
        parent::__construct();
        $this->vendor_send = new VendorSend(['json_post' => true]);
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
                ? str_replace('@', ' ', $this->option('start')) . ':00:00'
                : date('Y-m-d H:i:s', strtotime('+7hour'));
            $end_time = $this->option('end')
                ? str_replace('@', ' ', $this->option('end')) . ':00:00'
                : date('Y-m-d H:i:s', strtotime('+8hour'));
            echo '時間範圍:' . $start_time . ' to ' . $end_time . PHP_EOL;

            $settings = VendorInfo::where('vendor_id', $this->vendor_id)
                ->where('status', 1)
                ->get();
            $used = []; //已執行過的不再執行

            if (count($settings) > 0) {
                foreach ($settings as $setting) {
                    //將遊戲商資訊解密
                    $info = Common::decryptVendorInfo($setting);
                    if (is_object($info) && !in_array($info->Merchant_Code, $used)) {
                        $records = $this->getBetRecord($info, $start_time, $end_time);
                        if (count($records) > 0) {
                            $this->addRecord($records);
                        }
                        $used[] = $info->Merchant_Code;
                    } else {
                        echo $setting->name . '解密失敗' . PHP_EOL;
                    }
                }
            }

            //total bet record
            echo '===== total bet record Start =====' . PHP_EOL;
            DB::select('call SP_transfer_bet_record_ns(null, null, null)');
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
        do {
            $continue = false;
            $eTime = date('Y-m-d H:i:00', strtotime($sTime . '+2hours'));
            if ($eTime < $end_time) {
                $continue = true;
            } else {
                $eTime = $end_time;
            }
            do {
                $nextLoop = false;
                $result = $this->getBetRecordByPage($info, $sTime, $eTime, $pageIndex);
                if (is_array($result) && $result['msg'] == 'Success') {
                    array_push($records, ...$result['list']);
                    if ($result['pageCount'] > $pageIndex) {
                        $nextLoop = true;
                        $pageIndex++;
                    }
                }
            } while ($nextLoop);
            $sTime = $eTime;
        } while ($continue);

        return $records;
    }

    private function getBetRecordByPage($info, $start_time, $end_time, $pageIndex)
    {
        $url = $info->API_URL;
        //API是時區UTC+8 轉換成他們要的時間格式
        $sTime = str_replace(' ', 'T', date("Ymd His", strtotime($start_time)));
        $eTime = str_replace(' ', 'T', date("Ymd His", strtotime($end_time)));

        $postData = array();
        $postData['beginDate'] = $sTime;
        $postData['endDate'] = $eTime;
        $postData['pageIndex'] = $pageIndex;
        $postData['merchantCode'] = $info->Merchant_Code;
        $postData['serialNo'] = time() . rand(10, 99);

        $headers = array(
            'API:getBetHistory',
            'DataType:JSON',
            'Content-Type:application/json'
        );

        $response = $this->vendor_send->send($this->action, $url, 'POST', $postData, $headers);
        $result = json_decode($response, true);

        return $result;
    }

    private function addRecord($records)
    {
        $datas = array();
        foreach ($records as $row) {
            $data = array();
            $data['bet_id'] = $row['ticketId'];
            $data['game_code'] = $row['gameCode'];
            $data['username'] = $row['acctId'];
            $data['bet'] = $row['betAmount'];
            $data['win_lose'] = $row['winLoss'];
            //API回傳的是13位時間戳
            $data['bet_time'] = date("Y-m-d H:i:s", strtotime($row['ticketTime'] . '-8hours'));
            $data['info'] = json_encode($row);
            $datas[] = $data;
        }
        $create_records = $this->filterRecordsNotExists($datas);
        //直接一次insert
        NsBetRecord::insert($create_records);
        echo 'success: ';
        printf("*recordCount [%d] writeCount [%d] ", count($datas), count($create_records));
        echo PHP_EOL;

    }

    /**
     * 過濾掉己經存在的押注記錄
     * @param $datas
     * @param int $size
     * @return array
     */
    private function filterRecordsNotExists($datas, $size = 100)
    {
        $exists_ids = array();
        $collect = collect($datas);
        $chunks = $collect->chunk($size); // 分組，一組 $size  個
        $unique_field = 'bet_id';
        // 分組取出己經存在的押注記錄
        foreach ($chunks as $chunk) {
            $ids = $chunk->pluck($unique_field)->toArray();
            array_push(
                $exists_ids,
                ...NsBetRecord::whereIn($unique_field, $ids)
                ->select($unique_field)
                ->get()
                ->pluck($unique_field)
                ->toArray()
            );
        }

        // 過濾掉己經存在的押注記錄
        return $collect->filter(function ($item) use ($unique_field, $exists_ids) {
            return !in_array($item[$unique_field], $exists_ids);
        })
            ->all();
    }
}