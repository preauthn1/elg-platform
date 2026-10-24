<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use App\Helpers\Common;
use App\Helpers\VendorSend;
use App\Models\Pussy888DailyWin;
use App\Models\VendorInfo;


class GetPussy888MemberBetRecord extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'pussy888_member_bet_record {--hour=} {--date=} {--account=}'; //pussy888_member_bet_record --date=2020-03-18 --account=

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '更新 pussy888 玩家投注紀錄';

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
        ini_set('memory_limit','500M');
        try {
            $runStartTime = microtime(true);
            $startTime = strtotime('+7 hours'); // +8 -1 前一個小時
            $endTime = strtotime('+8 hours'); // +8 到現在
            $sDate = date('Y-m-d H:00:00', $startTime);
            $eDate = date('Y-m-d H:59:59', $endTime);
            if ($this->option('hour')) { // input timezone UTC
                $d = str_replace('@', ' ', $this->option('hour')) . ':00:00';
                $startTime = strtotime($d . '+8 hours');
                $sDate = date('Y-m-d H:00:00', $startTime);
                $eDate = date('Y-m-d H:59:59', $startTime);
            }
            if ($this->option('date')) { // input timezone GMT+8 because it is date format
                $sDate = $this->option('date') . " 00:00:00";
                $eDate = $this->option('date') . " 23:59:59";
            }

            if ($this->option('account')) {
                $account = $this->option('account');

            }
            printf("獲取時間段 $sDate ~ $eDate ...\n");
            echo PHP_EOL;
            $settings = VendorInfo::where('vendor_id', 23)->where('status', 1)->get();
            $used = []; //已執行過的不再執行

            $recordCount = $userCount = $writeCount = 0;
            foreach ($settings as $row) {
                //將遊戲商資訊解密
                $info = Common::decryptVendorInfo($row);
                if (is_object($info) && !in_array($info->API_SECRETKEY, $used)) {
                    // 逐一獲取用戶注單
                    $records = $this->getAccountBetRecord($info, $account, $sDate, $eDate);
                    $datas = array();
                    foreach ($records as $r) {
                        // GameID = -1是轉帳紀錄
                        if($r['GameID'] != -1){
                            $recordCount++;
                            $datas[] = array(
                                'class_id' => $r['ClassID'],
                                'account' => $account,
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
                    printf("account=[%s] [%d/%d], ", $account, $recordCount, $writeCount);
                    echo PHP_EOL;
                    printf("獲取時間段 $sDate ~ $eDate, 共 [%d] 用戶 [%d] 注單, 本次寫入 [%d] 注單, 耗時 %d 秒\n", $userCount, $recordCount, $writeCount, (microtime(true) - $runStartTime));
                    echo PHP_EOL;
                    $used[] = $info->API_SECRETKEY;
                    //$this->addRecord($info, $date);
                } else {
                    echo $row->name . '解密失敗' . PHP_EOL;
                }
            }

            //total bet record
            echo '===== total bet record Start =====' . PHP_EOL;
            DB::select('call SP_transfer_bet_record_pussy888(null, null, null)');
            echo '===== total bet record End =====' . PHP_EOL;

        } catch (\Illuminate\Database\QueryException $e) {
            $info = $e->errorInfo;
            echo $info[0] . ' : ' . $info[2];
        } catch (\Exception $e) {
            dd($e);
            echo $e->getCode() . ' : ' . $e->getMessage();
        }
    }

    private function insertIgnore($datas)
    {
        $fields = array_keys($datas[0]);
        $sql = "INSERT IGNORE INTO pussy888_bet_records (" . join(',', $fields) . ")";
        $values = array();
        $bindings = array();
        foreach ($datas as $row) {
            foreach ($row as $v) {
                $bindings[] = $v;
            }
            $values[] = '(' . implode(', ', array_map(function () {return "?";}, $row)) . ')';
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

    private function sign($string)
    {
        return strtoupper(md5(strtolower($string)));
    }

    //輸贏總表
    public function getDailyWin($info, $sDate, $eDate)
    {
        $url = $info->API_URL2 . 'AgentTotalReport.ashx';
        $data = array();
        $data['userName'] = $info->AGENT_NAME;
        //總表取單個小時的會少資料
        $data['sDate'] = date('Y-m-d', strtotime($sDate));
        $data['eDate'] = date('Y-m-d', strtotime($sDate));
        //$data['sDate'] = $sDate;
        //$data['eDate'] = $eDate;
        $data['Type'] = 'ServerTotalReport';
        $data['time'] = time() . '000';
        $data['authcode'] = $info->API_AUTHCODE;
        $data['sign'] = $this->sign($data['authcode'] . $data['userName'] . $data['time'] . $info->API_SECRETKEY);
        $result = $this->vendorsend->send('pussy888_get_daily_win', $url, 'GET', $data);
        return json_decode($result, true);
    }

    //獲取目標時間段前一小時到後一小時
    public function getAccountBetRecord($info, $userName, $sDate, $eDate)
    {
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
        } while ($nextLoop);

        return $records;
    }

    // call from $this->getAccountBetRecord
    private function getAccountBetRecordByPage($info, $userName, $sDate, $eDate, $pageIndex, $pageSize)
    {
        $url = $info->API_URL2 . 'GameLog.ashx';
        $data = array();
        $data['pageSize'] = $pageSize;
        $data['pageIndex'] = $pageIndex;
        $data['userName'] = $userName;
        $data['sDate'] = $sDate;
        $data['eDate'] = $eDate;
        $data['time'] = date('U') . '000';
        $data['authcode'] = $info->API_AUTHCODE;
        $data['sign'] = $this->sign($data['authcode'] . $data['userName'] . $data['time'] . $info->API_SECRETKEY);
        $result = $this->vendorsend->send('pussy888_get_bet_record', $url, 'GET', $data);
        return json_decode($result, true);
    }
}

