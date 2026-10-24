<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Helpers\Common;
use App\Helpers\VendorSend;
use App\Models\VendorInfo;
use App\Models\Menang88BetRecord;
use DB;
use SebastianBergmann\CodeCoverage\Report\PHP;

class GetMenang88BetRecord extends Command
{

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'menang88_bet_record {--start=}'; // php artisan menang88_bet_record --start=

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '更新Menang88投注記錄';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    protected $vendor_send;
    protected $vendor_id = 40;
    protected $action = 'menang88_get_bet_record';
    /* 需要撈取API的時間
     * 一天有6场
     * 10:30，12:30，14:30，16:30，18:30，20:30
     * 建議在30分钟后捞數據 / 或者15分钟后也行
     */
    //protected $drawTime = [11, 13, 15, 17, 19, 21];
    /*
     * 一天有8场
     * 11:00，12:00，13:00，14:00，15:00，16:00，17:00，18:00
     * 建議在30分钟后捞數據 / 或者15分钟后也行
     */
    protected $drawTime = [11, 12, 13, 14, 15, 16, 17, 18];  //2021/06/23修改

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
                ? $this->option('start')
                : date('Y-m-d@H', strtotime('+8hour'));

            //把日期跟小時分開
            $searchTime = explode('@', $start_time);

            //如果是開彩的時間才去撈取api
            if (in_array($searchTime[1], $this->drawTime)) {
                //把時間轉換成撈api期數的時間
                $sTime = $this->getApiTime($searchTime);

                echo '撈取的開彩時間 : ' . $sTime . PHP_EOL;

                $settings = VendorInfo::where('vendor_id', $this->vendor_id)
                    ->where('status', 1)
                    ->get();
                $used = []; //已執行過的不再執行
                if (count($settings) > 0) {
                    foreach ($settings as $setting) {
                        //將遊戲商資訊解密
                        $info = Common::decryptVendorInfo($setting);
                        if (is_object($info) && !in_array($info->TOKEN, $used)) {
                            $records = $this->getBetRecord($info, $sTime);
                            if (count($records) > 0) {
                                $this->addRecord($records, $sTime);
                            }
                            $used[] = $info->TOKEN;
                        } else {
                            echo $setting->name . '解密失敗' . PHP_EOL;
                        }
                    }
                }
                //total bet record
                echo '===== total bet record Start =====' . PHP_EOL;
                DB::select('call SP_transfer_bet_record_menang88(null, null, null)');
                echo '===== total bet record End =====' . PHP_EOL;
            }
        } catch (\Illuminate\Database\QueryException $e) {
            $info = $e->errorInfo;
            echo $info[0] . ' : ' . $info[2];
        } catch (\Exception $e) {
            echo $e->getCode() . ' : ' . $e->getMessage();
        }
    }

    //獲取目標時間段前一小時到後一小時
    private function getBetRecord($info, $sTime)
    {
        $records = array();
        //API的頁數是從0開始
        $pageIndex = 0;
        do {
            $nextLoop = false;
            $result = $this->getBetRecordByPage($info, $sTime, $pageIndex);
            if (is_array($result) && $result['status'] == '1') {
                array_push($records, ...$result['winloses']);
                if (($result['totalpages'] - 1) > $pageIndex) {
                    $nextLoop = true;
                    $pageIndex++;
                }
            }
        } while ($nextLoop);
        return $records;
    }

    private function getBetRecordByPage($info, $sTime, $pageIndex)
    {
        $url = $info->API_URL . 'WinloseMax.aspx';

        $data = array();
        $data['token'] = $info->TOKEN;
        $data['fdte'] = $sTime;
        $data['tdte'] = $sTime;
        $data['pge'] = $pageIndex;

        //不使用http_build_query()是因為token內有些字會被換掉
        $url .= '?' . $this->params($data);

        $response = json_decode($this->vendor_send->send($this->action, $url, 'POST', $data), true);

        return $response;
    }

    private function addRecord($records, $sTime)
    {
        $datas = array();
        foreach ($records as $row) {
            if($row['drawdate'] == $sTime) {
                $data = array();
                $data['bet_id'] = $row['betid'];
                $data['game_code'] = 'menang88';
                $data['username'] = $row['username'];
                $data['bet'] = str_replace(',', '', $row['stake']);
                //跟豪龍一樣比較特，用win來顯示
                $data['win_lose'] = str_replace(',', '', $row['mmwin']);
                $data['created_time'] = date('Y-m-d H:i:s', strtotime($row['date'] . '-8hour'));
                $data['bet_time'] = date('Y-m-d H:i:s', strtotime($row['drawdate'] . '-8hour'));
                $data['info'] = json_encode($row);
                $datas[] = $data;
            }
        }
        $create_records = $this->filterRecordsNotExists($datas);
        //直接一次insert
        Menang88BetRecord::insert($create_records);
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
                ...Menang88BetRecord::whereIn($unique_field, $ids)
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

    private function getApiTime($searchTime)
    {
        $apiTime = $searchTime[0] . ' ' . $searchTime[1] . ':00:00';

        if (strtotime($apiTime) == strtotime(date("Y-m-d H:00:00") . '+8hour')) {
            //如果現在時間是API要撈取的開獎時間的半小時內,就撈前一個小時的資料
            if (strtotime(date('H:i:s')) < strtotime(date("H:30:00"))) {
                $apiTime = date('Y-m-d H:i:s', strtotime($apiTime . ' -1hour'));
            }
        }

        return $apiTime;
    }

    private function params($data)
    {
        $str = '';

        foreach ($data as $key => $val) {
            $str .= $key . '=' . $val;
            if ($val !== end($data)) {
                $str .= '&';
            }
        }

        //不把空白變 "%20" API會出錯
        return str_replace(' ', '%20', $str);
    }
}