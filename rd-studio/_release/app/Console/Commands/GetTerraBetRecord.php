<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Helpers\Common;
use App\Helpers\VendorSend;
use App\Models\VendorInfo;
use App\Models\TerraBetRecord;
use DB;

class GetTerraBetRecord extends Command
{

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'terra_bet_record {--start=} {--end=}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '更新Terra投注記錄';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    protected $vendor_send;
    protected $vendor_id = 34;
    protected $action = 'terra_get_bet_record';

    public function __construct()
    {
        parent::__construct();
        $this->vendor_send = new VendorSend();
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
                : date('Y-m-d H:i:s', strtotime('+7hour -5minute'));
            $end_time = $this->option('end')
                ? str_replace('@', ' ', $this->option('end')) . ':00:00'
                : date('Y-m-d H:i:s', strtotime('+8hour -5minute'));
            echo '時間範圍:' . $start_time . ' to ' . $end_time . PHP_EOL;

            $settings = VendorInfo::where('vendor_id', $this->vendor_id)
                ->where('status', 1)
                ->get();
            $used = []; //已執行過的不再執行

            if (count($settings) > 0) {
                foreach ($settings as $setting) {
                    //將遊戲商資訊解密
                    $info = Common::decryptVendorInfo($setting);
                    if (is_object($info) && !in_array($info->API_SECRET, $used)) {
                        $records = $this->getBetRecord($info, $start_time, $end_time);
                        if (count($records) > 0) {
                            $this->addRecord($records);
                        }
                        $used[] = $info->API_SECRET;
                    } else {
                        echo $setting->name . '解密失敗' . PHP_EOL;
                    }
                }
            }

            //total bet record
            echo '===== total bet record Start =====' . PHP_EOL;
            DB::select('call SP_transfer_bet_record_terra(null, null, null)');
            echo '===== total bet record End =====' . PHP_EOL;
        } catch (\Illuminate\Database\QueryException $e) {
            $info = $e->errorInfo;
            echo $info[0] . ' : ' . $info[2];
        } catch (\Exception $e) {
            echo $e->getCode() . ' : ' . $e->getMessage();
        }
    }

    //獲取目標時間段前一小時到後一小時
    private function getBetRecord($info, $sDate, $eDate)
    {
        $records = array();
        $pageIndex = 1;
        do {
            $nextLoop = false;
            $result = $this->getBetRecordByPage($info, $sDate, $eDate, $pageIndex);
            if (is_array($result) && $result['response']['error'] == 0) {
                array_push($records, ...$result['data']);
                if ($result['totalPage'] > $pageIndex) {
                    $nextLoop = true;
                    $pageIndex++;
                }
            }
        } while ($nextLoop);
        return $records;
    }

    private function getBetRecordByPage($info, $start_time, $end_time, $pageIndex)
    {
        $url = $info->API_URL . '/api_GetStatement_byTime';
        //API是時區UTC+0
        $sDate = date('Y-m-d H:i:s', strtotime($start_time . '-8hour'));
        $eDate = date('Y-m-d H:i:s', strtotime($end_time . '-8hour'));
        echo 'API執行時間 : ' . $sDate . ' to ' . $eDate . PHP_EOL;
        $data = array();
        $data['startTime'] = $sDate;
        $data['endTime'] = $eDate;
        $data['page'] = $pageIndex;
        $postData = array();
        $postData['Key'] = $this->get_key($data, $info->API_SECRET);
        $postData = array_merge($postData, $data);
        $response = $this->vendor_send->send($this->action, $url, 'GET', $postData);
        $result = json_decode($response, true);

        return $result;
    }

    private function addRecord($records)
    {
        $datas = array();
        foreach ($records as $row) {
            $data = array();
            $data['bet_id'] = $row['id'];
            $data['game_code'] = $row['gameId'];
            $data['username'] = $row['memberId'];
            $data['bet'] = -1 * $row['betAmount'];
            $data['win_lose'] = $row['winloseAmount'] + $row['betAmount'];
            $data['bet_time'] = $row['betTime'];
            $data['info'] = json_encode($row);
            $datas[] = $data;
        }
        $create_records = $this->filterRecordsNotExists($datas);
        //直接一次insert
        TerraBetRecord::insert($create_records);
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
                ...TerraBetRecord::whereIn($unique_field, $ids)
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

    private function get_key($data, $secretKey)
    {
//        echo http_build_query($data, null, '&', PHP_QUERY_RFC1738);
//        return '00000' . md5(http_build_query($data) . '&Key=' . $secretKey) . '00000';
        $str = "startTime=" . $data['startTime'] . "&endTime=" . $data['endTime'];
        $str .= "&page=" . $data['page'] . "&Key=" . $secretKey;
        return '00000' . md5($str) . '00000';
    }
}