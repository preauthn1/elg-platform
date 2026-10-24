<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Helpers\Common;
use App\Helpers\VendorSend;
use App\Models\VendorInfo;
use App\Models\Plus100BetRecord;
use DB;

class GetPlus100BetRecord extends Command
{

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = '100plus_bet_record {--start=} {--end=}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '更新100Plus投注記錄';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    protected $vendor_send;
    protected $vendor_id = 46;
    protected $action = '100plus_get_bet_record';

    public function __construct()
    {
        parent::__construct();
        $this->vendor_send = new VendorSend(['json_post'=>true]);
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
                    if (is_object($info) && !in_array($info->API_KEY, $used)) {
                        $records = $this->getBetRecord($info, $start_time, $end_time);
                        if (count($records) > 0) {
                            $this->addRecord($records);
                        }
                        $used[] = $info->API_KEY;
                    } else {
                        echo $setting->name . '解密失敗' . PHP_EOL;
                    }
                }
            }

            //total bet record
            echo '===== total bet record Start =====' . PHP_EOL;
            DB::select('call SP_transfer_bet_record_plus100(null, null, null)');
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
        $pageSize = 2000;  //page_size 的范围为 1 ~ 2000。
        do {
            $nextLoop = false;
            $result = $this->getBetRecordByPage($info, $start_time, $end_time, $pageIndex, $pageSize);
            if (is_array($result) && $result['status'] == 'success') {
                array_push($records, ...$result['data']);
                if ($result['totalPage'] > $pageIndex) {
                    $nextLoop = true;
                    $pageIndex++;
                }
            }
        } while ($nextLoop);
        return $records;
    }

    private function getBetRecordByPage($info, $start_time, $end_time, $pageIndex, $pageSize)
    {
        $url = $info->API_URL . '/m/roundRecordByTime';
        //API是時區UTC+0 參數是毫秒時間戳
        $sTime = strtotime($start_time . '-8hour') . '000';
        $eTime = strtotime($end_time . '-8hour') . '000';

        $postData = array();
        $postData['merch'] = $info->MERCH;
        //roundStatus => 1 : 撈出已完成的注單 2 : 撈出已取消的注單
        $postData['roundStatus'] = "1";
        $postData['start'] = $sTime;
        $postData['end'] = $eTime;
        $postData['page'] = $pageIndex;
        $postData['pageSize'] = $pageSize;

        $hash = $this->hash($info, $postData);
        $url .= '?hash=' . $hash;

        $header = array('Content-Type:application/json');
        $response = $this->vendor_send->send($this->action, $url, 'POST', $postData, $header);
        $result = json_decode($response, true);

        return $result;
    }

    private function addRecord($records)
    {
        $datas = array();
        foreach ($records as $row) {
            $data = array();
            $data['bet_id'] = $row['roundId'];
            $data['game_code'] = $row['gameId'];
            $data['username'] = $row['playerId'];
            $data['bet'] = $row['validBet'];
            $data['win_lose'] = $row['netWin'];
            //API回傳的是13位時間戳
            $data['bet_time'] = date("Y-m-d H:i:s", ($row['betTime'] / 1000));
            $data['wp_time'] = date("Y-m-d H:i:s", ($row['wpTime'] / 1000));
            $data['info'] = json_encode($row);
            $datas[] = $data;
        }
        $create_records = $this->filterRecordsNotExists($datas);
        //直接一次insert
        Plus100BetRecord::insert($create_records);
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
                ...Plus100BetRecord::whereIn($unique_field, $ids)
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

    private function hash($info, $message)
    {
        $json_str = json_encode($message, JSON_UNESCAPED_UNICODE);
        return hash_hmac("SHA256", utf8_encode($json_str), $info->API_KEY);
    }
}