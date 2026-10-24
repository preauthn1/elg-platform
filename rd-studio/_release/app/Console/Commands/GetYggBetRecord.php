<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Helpers\VendorSend;
use App\Helpers\Common;
use App\Models\VendorInfo;
use App\Models\YggBetRecord;
use DB;

class GetYggBetRecord extends Command
{

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'ygg_bet_record {--start=} {--end=}'; //ygg_bet_record --start=2019-10-01@00:00:00 --end=2019-10-01@23:59:59

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '更新Ygg投注記錄';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    protected $vendorsend;

    public function __construct()
    {
        parent::__construct();
        $this->vendorsend = new VendorSend(["timeout"=>60]);
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        try {
            $start = $this->option('start')
                ? str_replace('@', ' ', $this->option('start'))
                : date('Y-m-d H:i:s', strtotime('-1hour'));
            $end = $this->option('end')
                ? str_replace('@', ' ', $this->option('end'))
                : date('Y-m-d H:i:s');
            echo $start . ' to ' . $end . PHP_EOL;

            $settings = VendorInfo::where('vendor_id', 15)->where('status', 1)->get();
            $used = []; //已執行過的不再執行
            foreach ($settings as $row) {
                $runStartTime = microtime(true);
                $info = Common::decryptVendorInfo($row);
                if (is_object($info) && !in_array($info->ORG, $used)) {
                    echo '======' . $row->name . '======' . PHP_EOL;
                    //每十分鐘獲取一次
                    $runTime = $start;
                    $increase = "+10 Minutes";
                    $insert_rows = [];
                    while (strtotime($runTime) < strtotime($end)) { //runTime 小於結束時間則繼續
                        $endTime = date("Y-m-d H:i:s", strtotime($runTime . " " . $increase));
                        $records = $this->getRecords($info, $runTime, $endTime);
                        foreach($records as $item) {
                            $item['record_id'] = $item['id'];
                            unset($item['id']);
                            unset($item['subreference']);
                            $insert_rows[] = $item;
                        }
                        printf("*%s ~ %s* [%d] records \n", $runTime, $endTime, count($records));
                        $runTime = $endTime;
                    }

                    $writeCount = $this->insertIgnoreBatch($insert_rows);
                    printf("獲取時間段 $start ~ $end, 共 [%d] 注單, 本次寫入 [%d] 注單, 耗時 %d 秒\n", count($insert_rows), $writeCount, (microtime(true) - $runStartTime));

                    $used[] = $info->ORG;
                }
            }

            //total bet record
            echo '===== total bet record Start =====' . PHP_EOL;
            DB::select('call SP_transfer_bet_record_ygg(null, null, null)');
            echo '===== total bet record End =====' . PHP_EOL;
        } catch (\Illuminate\Database\QueryException $e) {
            $info = $e->errorInfo;
            echo $info[0] . ' : ' . $info[2];
        } catch (\Exception $e) {
            echo $e->getTraceAsString();
            echo $e->getCode() . ' : ' . $e->getMessage();
        }
    }


    private function getRecords($info, $startTime, $endTime) {
        // init post data
        $data = array();
        $data['topOrg'] = $info->TOP_ORG;
        $data['org'] = $info->ORG;
        $data['currency'] = $info->CURRENCY;
        $data['sign'] = $this->sign(($info->TOP_ORG . $info->ORG), $info->KEY);
        $data['startTime'] = $startTime;
        $data['endTime'] = $endTime;
        // initial
        $url = $info->HOST . 'yggGame/getUsersBetDataV2';
        $header = array('Content-Type:application/x-www-form-urlencoded');
        $records = [];
        $lastId = 0; // lastId 同一個時間段的分頁使用 lastId.
        do { //取到沒有資料為止
            $data['lastId'] = intval($lastId);
            $response = $this->vendorsend->send('ygg_get_bet_record', $url, 'POST', $data, $header);
            $result = json_decode($response, true);
            if (is_array($result) && $result['code'] == '0' && count($result['data']) > 0) {
                foreach($result['data'] as $item) {
                    $records[] = $item;
                }
                $collection = collect($records);
                $maxRow = $collection->sortByDesc('id')->first();
                $lastId = $maxRow['id'];
            }
        } while ($result['code'] != 1 && $result['data'] && count($result['data']) > 0);

        return $records;
    }


    private function insertIgnore($datas)
    {
        $fields = array_keys($datas[0]);
        $sql = "INSERT IGNORE INTO ygg_bet_records (`" . join('`,`', $fields) . "`)";
        $values = array();
        $bindings = array();
        foreach ($datas as $row) {
            foreach ($row as $v) {
                $bindings[] = $v;
            }
            $values[] = '(' . implode(', ', array_map(function () {
                    return "?";
                }, $row)) . ')';
        }
        $sql .= " VALUES " . implode(', ', $values);
        $affectedRows = DB::affectingStatement($sql, $bindings);
        //printf("dataCount=[%d], affectedRows=[%d]\n", sizeof($datas), $affectedRows);
        return $affectedRows;
    }

    private function insertIgnoreBatch($datas, $batchSize = 1000)
    {
        $affectedRows = 0;
        $total = sizeof($datas);
        $batchTotal = ceil($total / $batchSize);
        for ($i = 1; $i <= $batchTotal; $i++) {
            $index = ($i - 1) * $batchSize;
            $batchData = array_slice($datas, $index, $batchSize);
            $affectedRows += $this->insertIgnore($batchData);
        }
        return $affectedRows;
    }

    private function sign($string, $key)
    {
        return md5($string . $key);
    }
//
//    private function post($info, $data)
//    {
//        Common::log(0, $data, 'ygg_bet_record');
//        $url = 'yggGame/getUsersBetDataV2';
//        $ch = curl_init();
//        curl_setopt($ch, CURLOPT_URL, $info->HOST . $url);
//        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
//        curl_setopt($ch, CURLOPT_POST, TRUE);
//        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
//            'Content-Type:application/x-www-form-urlencoded',
//        ));
//        curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
//        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
//        curl_setopt($ch, CURLOPT_TIMEOUT, 60);
//        $result = curl_exec($ch);
//        $obj = json_decode($result, true);
//        if (!$obj) {
//            echo '======================' . PHP_EOL;
//            var_dump(curl_error($ch));
//        }
//        curl_close($ch);
//        Common::log(0, $result, 'ygg_bet_record');
//        return $obj;
//    }
}