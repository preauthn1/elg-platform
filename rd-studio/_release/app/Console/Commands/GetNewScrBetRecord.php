<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use function GuzzleHttp\Psr7\build_query;
use App\Helpers\Common;
use App\Helpers\HttpClient;
use App\Helpers\VendorSend;
use App\Models\ScrBetRecord;
use App\Models\VendorInfo;
use http\Client;

class GetNewScrBetRecord extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'new_scr_bet_record {--hour=} {--date=}'; //new_scr_bet_record --hour=2020-03-18@00

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '更新 918kiss 投注紀錄';

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
     * 利用當日輸贏總表有數據的用戶來搜集用戶的注單
     * @return mixed
     */
    public function handle()
    {
        try {
            $startTime = strtotime('+7 hours'); // +8 -1 前一個小時
            $endTime = strtotime('+8 hours'); // +8 到現在
            $sDate = date('Y-m-d H:00:00', $startTime);
            $eDate = date('Y-m-d H:59:59', $endTime);
            if ($this->option('hour')) { // input timezone UTC
                $d =  str_replace('@', ' ', $this->option('hour')) . ':00:00';
                $startTime = strtotime($d . '+8 hours');
                $sDate = date('Y-m-d H:00:00', $startTime);
                $eDate = date('Y-m-d H:59:59', $startTime);
            }
            if ($this->option('date')) { // input timezone GMT+8 because it is date format
                $sDate = $this->option('date') . " 00:00:00";
                $eDate = $this->option('date') . " 23:59:59";
            }
            //printf("獲取時間段 $sDate ~ $eDate ...\n");
            echo PHP_EOL;
            $settings = VendorInfo::where('vendor_id', 3)->where('status', 1)->get();
            $used = []; //已執行過的不再執行
            $recordCount = $userCount = $writeCount = 0;
            foreach ($settings as $row) {
                $runStartTime = microtime(true);
                printf("獲取時間段 $row->name $sDate ~ $eDate ...\n");
                //將遊戲商資訊解密
                $info = Common::decryptVendorInfo($row);
                $used_key = $info->API_USER . "_" . $info->API_PASSWORD;
                if (is_object($info) && !in_array($used_key, $used)) {
                    $used[] = $used_key;
                    // 先獲取輸贏總表
                    $dailyWinResult = $this->getDailyWin($info, $sDate, $eDate);
                    $userCount = $recordCount = $writeCount = 0;
                    if (is_array($dailyWinResult) && isset($dailyWinResult['results'])) {
                        foreach($dailyWinResult['results'] as $n => $row) {
                            $userCount++;
                            //echo json_encode($row)."\n";
                            // 逐一獲取用戶注單
                            $records = $this->getAccountBetRecord($info, $row["Account"], $sDate, $eDate);
                            $datas = array();
                            foreach($records as $r) {
                                // GameID = -1是轉帳紀錄
                                if($r['GameID'] != -1) {
                                    $recordCount++;
                                    $datas[] = array(
                                        'class_id' => $r['ClassID'],
                                        'account' => $row['Account'],
                                        'bet_time' => $r['CreateTime'],
                                        'bet_time_utc' => date('Y-m-d H:i:s', strtotime($r['CreateTime'] . "-8 hours")),
                                        'begin_balance' => $r['BeginBlance'],
                                        'end_balance' => $r['EndBlance'],
                                        'game_id' => $r['GameID'],
                                        'game_name' => $r['GameName'],
                                        'line_num' => $r['LineNum'],
                                        'log_data_str' => $r['LogDataStr'],
                                        'log_data_type' => $r['LogDataType'],
                                        'round_no' => $r['RoundNO'],
                                        'row_num' => $r['Rownum'],
                                        'table_id' => $r['TableID'],
                                        'win' => $r['Win'],
                                        'bet' => $r['bet'],
                                        'cday' => $r['cday'],
                                        'cno' => $r['cno'],
                                        'uuid' => $r['uuid'],
                                    );
                                }
                            }
                            $writeCount += $this->insertIgnoreBatch($datas);
                            printf("*%d/%d* account=[%s] [%d/%d], ", $n, sizeof($dailyWinResult['results']), $row["Account"], $recordCount, $writeCount);
                            echo PHP_EOL;
                        }
                    }
                    printf("獲取時間段 $sDate ~ $eDate, 共 [%d] 用戶 [%d] 注單, 本次寫入 [%d] 注單, 耗時 %d 秒\n", $userCount, $recordCount, $writeCount, (microtime(true) - $runStartTime));
                    echo PHP_EOL;
                    //$this->addRecord($info, $date);
                } else {
                    echo $row->name . '解密失敗' . PHP_EOL;
                }
            }
            //total bet record
            echo '===== total bet record Start =====' . PHP_EOL;
            DB::select('call SP_transfer_bet_record_scr(null, null, null)');
            echo '===== total bet record End =====' . PHP_EOL;

        } catch (\Illuminate\Database\QueryException $e) {
            $info = $e->errorInfo;
            echo $info[0] . ' : ' . $info[2];
        } catch (\Exception $e) {
            dd($e);
            echo $e->getCode() . ' : ' . $e->getMessage();
        }
    }

    private function insertIgnore($datas) {
        $fields = array_keys($datas[0]);
        $sql = "INSERT IGNORE INTO scr_bet_records (".join(',', $fields).")";
        $values = array();
        $bindings = array();
        foreach($datas as $row) {
            foreach($row as $v) {
                $bindings[] = $v;
            }
            $values[] = '('.implode(', ', array_map(function(){return "?";}, $row)).')';
        }
        $sql .= " VALUES " . implode(', ', $values);
        $affectedRows = DB::affectingStatement($sql, $bindings);
        //printf("dataCount=[%d], affectedRows=[%d]\n", sizeof($datas), $affectedRows);
        return $affectedRows;

    }

    private function insertIgnoreBatch($datas, $batchSize=1000) {
        $affectedRows = 0;
        $total = sizeof($datas);
        $batchTotal = ceil($total / $batchSize);
        for($i = 1; $i <= $batchTotal; $i ++) {
            $index = ($i - 1) * $batchSize;
            $batchData = array_slice($datas, $index, $batchSize);
            $affectedRows += $this->insertIgnore($batchData);
        }
        return $affectedRows;
    }

    private function sign($string) {
        return  strtoupper(md5(strtolower($string)));
    }

    //輸贏總表
    public function getDailyWin($info, $sDate, $eDate) {

        $url = $info->REPORT_HOST.'AgentTotalReport.ashx';
        $data = array();
        $data['userName'] = $info->AGENT_NAME;
        //總表取單個小時的會少資料
        $data['sDate'] = date('Y-m-d 00:00:00', strtotime($sDate));
        $data['eDate'] = date('Y-m-d 23:59:59', strtotime($sDate));
        //$data['sDate'] = $sDate;
        //$data['eDate'] = $eDate;
        $data['Type'] = 'ServerTotalReport';
        $data['time'] = date('U') . '000';
        $data['authcode'] = $info->AUTH_CODE;
        $data['sign'] = $this->sign($data['authcode'] . $data['userName'] . $data['time'] . $info->SECRET_KEY);
        $result = $this->vendorsend->send('new_scr_getDailyWin', $url, 'POST', $data);
//        Common::log(0, $data, 'new_scr_getDailyWin');
//        $result = HttpClient::post($url, $data);
//        Common::log(0, $result, 'new_scr_getDailyWin');
        return json_decode($result, true);
    }

    //獲取目標時間段前一小時到後一小時
    public function getAccountBetRecord($info, $userName, $sDate, $eDate) {
        $records = array();
        $pageIndex = 1;
        do {
            $nextLoop = false;
            $pageSize = 1000;
            $result = $this->getAccountBetRecordByPage($info, $userName, $sDate, $eDate, $pageIndex, $pageSize);
            if (is_array($result) && isset($result['results'])) {
                array_push($records, ...$result['results']);
                if (intval($result['total']) > ($pageIndex * $pageSize)) {
                    $nextLoop = true;
                    $pageIndex++;
                }
            }
        } while($nextLoop);

        return $records;
    }

    // call from $this->getAccountBetRecord
    private function getAccountBetRecordByPage($info, $userName, $sDate, $eDate, $pageIndex, $pageSize) {
        $url = $info->REPORT_HOST.'GameLog.ashx';
        $data = array();
        $data['pageIndex'] = $pageIndex;
        $data['pageSize'] = $pageSize;
        $data['userName'] = $userName;
        $data['sDate'] = $sDate;
        $data['eDate'] = $eDate;
        $data['time'] = date('U') . '000';
        $data['authcode'] = $info->AUTH_CODE;
        $data['sign'] = $this->sign($data['authcode'] . $data['userName'] . $data['time'] . $info->SECRET_KEY);
        $result = $this->vendorsend->send('new_scr_get_bet_record', $url, 'POST', $data);
//        Common::log(0, $data, 'new_scr_getAccountBetRecordByPage');
//        $result = HttpClient::post($url, $data);
//        Common::log(0, $result, 'new_scr_getAccountBetRecordByPage');
        return json_decode($result, true);
    }
}
