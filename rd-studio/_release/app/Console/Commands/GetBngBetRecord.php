<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Helpers\Common;
use App\Helpers\VendorSend;
use App\Models\VendorInfo;
use App\Models\BngBetRecord;
use DB;

class GetBngBetRecord extends Command
{

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'bng_bet_record {--start=} {--end=}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '更新Booongo投注記錄';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    protected $vendor_send;
    protected $vendor_id = 49;
    protected $action = 'bng_get_bet_record';

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
                : date('Y-m-d H:i:s', strtotime('+9hour'));
            echo '時間範圍:' . $start_time . ' to ' . $end_time . PHP_EOL;

            $settings = VendorInfo::where('vendor_id', $this->vendor_id)
                ->where('status', 1)
                ->get();
            $used = []; //已執行過的不再執行

            if (count($settings) > 0) {
                foreach ($settings as $setting) {
                    //將遊戲商資訊解密
                    $info = Common::decryptVendorInfo($setting);
                    if (is_object($info) && !in_array($info->API_TOKEN, $used)) {
                        $records = $this->getBetRecord($info, $start_time, $end_time);

                        if (count($records) > 0) {
                            $this->addRecord($records);
                        }
                        $used[] = $info->API_TOKEN;
                    } else {
                        echo $setting->name . '解密失敗' . PHP_EOL;
                    }
                }
            }

            //total bet record
            echo '===== total bet record Start =====' . PHP_EOL;
            DB::select('call SP_transfer_bet_record_bng(null, null, null)');
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
        //API換頁用
        $fetch_state = "";

        do {
            $nextLoop = false;
            $result = $this->getBetRecordByPage($info, $start_time, $end_time, $fetch_state);
            if (is_array($result) && isset($result['items'])) {
                array_push($records, ...$result['items']);
                if (!is_null($result['fetch_state'])) {
                    $nextLoop = true;
                    $fetch_state = $result['fetch_state'];
                }
            }
        } while ($nextLoop);

        return $records;
    }

    private function getBetRecordByPage($info, $start_time, $end_time, $fetch_state = "")
    {
        $url = $info->API_URL . $info->PROJECT_NAME . '/api/v1/transaction/list';
        //API是時區UTC+8 轉換成他們要的時間格式
        $sTime = str_replace(' ', 'T', date("Y-m-d H", strtotime($start_time))) . ':00:00+08:00';
        $eTime = str_replace(' ', 'T', date("Y-m-d H", strtotime($end_time))) . ':00:00+08:00';

        $postData = array();
        $postData['api_token'] = $info->API_TOKEN;
        $postData['start_date'] = $sTime;
        $postData['end_date'] = $eTime;
        $postData['fetch_size'] = 1000;
        $postData['fetch_state'] = $fetch_state;

        $headers = array('Content-Type:application/json');
        $response = $this->vendor_send->send($this->action, $url, 'POST', $postData, $headers);
        $result = json_decode($response, true);

        return $result;
    }

    private function addRecord($records)
    {
        $datas = array();
        foreach ($records as $row) {
            //要是自動派彩的才寫入bet_record
            if($row['auto_paid'] == true) {
                $data = array();
                $data['bet_id'] = $row['transaction_id'];
                $data['game_code'] = $row['game_id'];
                $data['username'] = $row['player_id'];
                $data['bet'] = $row['bet'];
                $data['win_lose'] = ($row['win'] - $row['bet']);
                //API回傳的是13位時間戳
                $data['bet_time'] = str_replace(
                    '+00:00',
                    '',
                    str_replace('T', ' ', $row['c_at'])
                );
                $data['bonus_id'] = $row['bonus_id'];
                $data['info'] = json_encode($row);
                $datas[] = $data;
            }
        }
        $create_records = $this->filterRecordsNotExists($datas);
        //批次塞入資料
        $collect = collect($create_records);
        $chunks = $collect->chunk(1000)->toArray(); // 分組，一組 $size  個
        foreach ($chunks as $chunk) {
            BngBetRecord::insert($chunk);
        }
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
                ...BngBetRecord::whereIn($unique_field, $ids)
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