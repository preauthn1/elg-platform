<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Helpers\HttpClient;
use App\Helpers\VendorSend;
use App\Helpers\Common;
use App\Models\Param;
use App\Models\VendorInfo;
use DB;
use UUID;

class GetMega888BetRecord extends Command
{

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'mega888_bet_record {--hour=}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '更新 mega888 投注紀錄';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    protected $vendorsend;
    public function __construct()
    {
        parent::__construct();
        $this->vendorsend = new VendorSend(['json_post' => true]);
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        try {
            $runStartTime = microtime(true);
            // prepare start time and end time
            $startTime = strtotime('+7 hours'); // +8 -1 前一個小時
            $sDate = date('Y-m-d H:00:00', $startTime);
            $eDate = date('Y-m-d H:59:59', $startTime);
            if ($this->option('hour')) { // input timezone UTC
                $d =  str_replace('@', ' ', $this->option('hour')) . ':00:00';
                $startTime = strtotime($d . '+8 hours');
                $sDate = date('Y-m-d H:00:00', $startTime);
                $eDate = date('Y-m-d H:59:59', $startTime);
            }
            if(file_exists(base_path() . '/gobin/getBetRecords')) {
                $resultCode = 1;
                chdir(base_path() . '/gobin/getBetRecords');
                $cmd = sprintf('./getBetRecords mega888 --start="%s" --end="%s"', $sDate, $eDate);
                if(Common::isWindows()) {
                    $cmd = sprintf('getBetRecords.exe mega888 --start="%s" --end="%s"', $sDate, $eDate);
                }
                echo $cmd . PHP_EOL;
                system($cmd, $resultCode);
                if($resultCode == 0) {
                    return;
                }
            }
            printf("獲取時間段 $sDate ~ $eDate ...\n");
            $recordCount = $userCount = $writeCount = 0;
            $settings = VendorInfo::where('vendor_id', 10)->where('status', 1)->get();
            $used = []; //已執行過的不再執行
            foreach ($settings as $row) {
                $info = json_decode($row->info);
                // loop $i = 1 - 2, 1 as single, 2 as live
                if (is_object($info) && !in_array($info->API_KEY, $used)) {
                    for ($i = 1; $i < 3; $i++) {
                        // 1. get daily win report
                        // 2. loop result for fetch loginId to get bet records
                        // 3. prepare datas from records before insert data
                        $dailyWinResult = $this->getDailyWin($i, $startTime, $info);
                        //$dailyWinResult['result'] = array_slice($dailyWinResult['result'], 4, 1); //留一個測試用
                        if (is_array($dailyWinResult) && isset($dailyWinResult['result'])) {
                            foreach ($dailyWinResult['result'] as $n => $row) {
                                //var_dump($row);
                                $userCount++;
                                $records = $this->getPlayerBetRecord($i, $row['loginId'], $sDate, $eDate, $info);
                                $datas = [];
                                if ($i == 1) { // single
                                    foreach ($records as $r) {
                                        $recordCount++;
                                        $create_time = strtotime($r['CreateTime']);
                                        $ref_id = $row['loginId'] . '-' . $r['GameID'] . '-' . $create_time . '-' . $r['id'];
                                        $datas[] = array(
                                            'ref_id' => $ref_id,
                                            'btype' => 'single',
                                            'class_id' => $r['ClassID'],
                                            'player_id' => $row['loginId'],
                                            'bet_time' => date('Y-m-d H:i:s', $create_time),
                                            'bet_time_utc' => date('Y-m-d H:i:s', strtotime("-8 hours", $create_time)),
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
                                            'bet_id' => $r['id']
                                        );
                                    }
                                    $writeCount += $this->insertIgnoreBatch($datas);
                                } else if ($i == 2) { // live
                                    foreach ($records as $r) {
                                        $recordCount++;
                                        $create_time = strtotime($r['orderTimeBj']);
                                        $datas[] = array(
                                            'ref_id' => $r['orderId'],
                                            'btype' => 'live',
                                            'class_id' => '',
                                            'player_id' => $row['loginId'],
                                            'bet_time' => date('Y-m-d H:i:s', $create_time),
                                            'bet_time_utc' => date('Y-m-d H:i:s', strtotime("-8 hours", $create_time)),
                                            'begin_balance' => 0,
                                            'end_balance' => 0,
                                            'game_id' => $r['gameId'],
                                            'game_name' => $r['gameNameEn'],
                                            'line_num' => 0,
                                            'log_data_str' => '',
                                            'log_data_type' => $r['playNameEn'],
                                            'round_no' => $r['issueId'],
                                            'row_num' => 0,
                                            'table_id' => $r['tableId'],
                                            'win' => $r['validBet'] + $r['payment'],
                                            'bet' => $r['validBet'],
                                            'bet_id' => 0
                                        );
                                    }
                                    $writeCount += $this->insertIgnoreBatch($datas);
                                }
                                printf("*%d/%d* account=[%s] [%d/%d], ", $n, sizeof($dailyWinResult['result']), $row["loginId"], $recordCount, $writeCount);
                            }
                        }
                    }
                    $used[] = $info->API_KEY;
                }
                printf(
                    "獲取時間段 $sDate ~ $eDate, 共 [%d] 用戶 [%d] 注單, 本次寫入 [%d] 注單, 耗時 %d 秒\n",
                    $userCount,
                    $recordCount,
                    $writeCount,
                    (microtime(true) - $runStartTime)
                );
            }

            //total bet record
            echo '===== total bet record Start =====' . PHP_EOL;
            DB::select('call SP_transfer_bet_record_mega888(null, null, null)');
            echo '===== total bet record End =====' . PHP_EOL;
        } catch (\Illuminate\Database\QueryException $e) {
            $info = $e->errorInfo;
            echo $info[0] . ' : ' . $info[2];
        } catch (\Exception $e) {
            echo $e->getCode() . ' : ' . $e->getMessage();
        }
        echo date('Y-m-d H:i:s');
    }

    // 使用INSERT IGNORE INTO 語法來新增有可能會重複的資料
    private function insertIgnore($datas)
    {
        $fields = array_keys($datas[0]);
        $sql = "INSERT IGNORE INTO mega888_bet_records (".join(',', $fields).")";
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

    // 分次新增資料, 避免單次數據量太大被reject
    private function insertIgnoreBatch($datas, $batchSize = 1000)
    {
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

    private function getPlayerLiveBetRecordByPage($loginId, $sDate, $eDate, $pageIndex, $pageSize, $info)
    {
        $api = "open.mega.video.order.page";
        $url = $info->API_URL . $api;
        $random = (string) UUID::generate();
        $data = array();
        $data['random'] = $random;
        $data['digest'] = strtoupper(md5($random . $info->SN . $loginId . $info->API_KEY));
        $data['sn'] = $info->SN;
        $data['loginId'] = $loginId;
        $data['startTime'] = $sDate;
        $data['endTime'] = $eDate;
        $data['pageIndex'] = $pageIndex;
        $data['pageSize'] = $pageSize;
        $post = [
            'id' => $data['random'],
            'method' => $api,
            'params' => $data,
            'jsonrpc' => '2.0',
        ];
        $result = $this->vendorsend->send('mega888_request_getPlayerLiveBetRecordByPage', $url, 'POST', $post, ['Content-Type:application/json']);
//        Common::log(0, $data, 'mega888_request_getPlayerLiveBetRecordByPage');
//        $result = HttpClient::post($url, $post, ['Content-Type:application/json'], true);
//        Common::log(0, $result, 'mega888_response_getPlayerLiveBetRecordByPage');
        $r = json_decode($result, true);
        $r['result']['results'] = $r['result']['items'];
        return $r['result'];
    }

    private function getPlayerBetRecord($type, $loginId, $sDate, $eDate, $info)
    {
        $records = array();
        $pageIndex = 1;
        do {
            $nextLoop = false;
            $pageSize = 20;
            if ($type == 1) { // single
                $result = $this->getPlayerBetRecordByPage($loginId, $sDate, $eDate, $pageIndex, $pageSize, $info);
            } else if ($type == 2) { // live
                $result = $this->getPlayerLiveBetRecordByPage($loginId, $sDate, $eDate, $pageIndex, $pageSize, $info);
            }

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

    private function getPlayerBetRecordByPage($loginId, $sDate, $eDate, $pageIndex, $pageSize, $info)
    {
        $api = 'open.mega.player.game.log.url.get';
        $url = $info->API_URL . $api;
        $random = (string) UUID::generate();
        $data = array();
        $data['random'] = $random;
        $data['digest'] = strtoupper(md5($random . $info->SN . $loginId . $info->API_KEY));
        $data['sn'] = $info->SN;
        $data['loginId'] = $loginId;
        $data['startTime'] = $sDate;
        $data['endTime'] = $eDate;
        $post = [
            'id' => $data['random'],
            'method' => $api,
            'params' => $data,
            'jsonrpc' => '2.0',
        ];
        Common::log(0, $data, 'mega888_request_getPlayerBetRecordByPage');
        $response = $this->vendorsend->send('mega888_request_getPlayerBetRecordByPage', $url, 'POST', $post, ['Content-Type:application/json']);
//        $response = HttpClient::post($url, $post, ['Content-Type:application/json'], true);
        $result = json_decode($response, true);
        if (is_array($result) && $result['result']) {

            $gameLogUrl = $result['result'];
            $urlInfo = parse_url($gameLogUrl);
            parse_str($urlInfo['query'], $data);
            $data['action'] = "playerGameLog";
            $data['user'] = $data['playername'];
            $data['sDate'] = $data['logdate_begin'];
            $data['eDate'] = $data['logdate_end'];
            $data['pageIndex'] = $pageIndex;
            $data['pagesize'] = $pageSize;
            $r = HttpClient::post($urlInfo['scheme']."://".$urlInfo['host']."/ashx/log/GameLog.ashx", $data);
            return json_decode($r, true);
        }
        Common::log(0, $result, 'mega888_response_getUserBetRecord');
        return false;
    }

    private function getDailyWin($type, $startTime, $info)
    {
        $api = 'open.mega.player.total.report';
        $url = $info->API_URL . $api;

        $random = (string) UUID::generate();
        $data = array();
        $data['random'] = $random;
        $data['digest'] = $this->digest($random, $info);
        $data['sn'] = $info->SN;
        $data['agentLoginId'] = $info->ACCOUNT;
        $data['type'] = $type;
        $data['startTime'] = date('Y-m-d 00:00:00', $startTime);
        $data['endTime'] = date('Y-m-d 23:59:59', $startTime);
        $post = [
            'id' => $data['random'],
            'method' => $api,
            'params' => $data,
            'jsonrpc' => '2.0',
        ];
        $result = $this->vendorsend->send('mega888_request_getDailyWin', $url, 'POST', $post, ['Content-Type:application/json']);
//        Common::log(0, $data, 'mega888_request_getDailyWin');
//        $result = HttpClient::post($url, $post, ['Content-Type:application/json'], true);
//        Common::log(0, $result, 'mega888_response__getDailyWin');
        return json_decode($result, true);
    }

    /**
     * -取簽名
     */
    private function digest($random, $info)
    {
        return strtoupper(md5($random . $info->SN . $info->ACCOUNT . $info->API_KEY));
    }

//    private function post($url, $data)
//    {
//        $post = [
//            'id' => $data['random'],
//            'method' => $url,
//            'params' => $data,
//            'jsonrpc' => '2.0',
//        ];
//        Common::log(0, $post, 'mega888_daily_win');
//        $ch = curl_init();
//        curl_setopt($ch, CURLOPT_URL, env('MEGA888_URL') . $url);
//        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($post));
//        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
//            'Content-Type:application/json'
//        ));
//        curl_setopt($ch, CURLOPT_POST, TRUE);
//        curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
//        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
//        $result = curl_exec($ch);
//
//        curl_close($ch);
//        Common::log(0, $result, 'mega888_daily_win');
//        return json_decode($result, true);
//    }
}