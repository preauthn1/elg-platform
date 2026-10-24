<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Helpers\Common;
use App\Helpers\VendorSend;
use App\Models\VendorInfo;
use App\Models\VpowerAccount;
use App\Models\VpowerBetRecord;
use DB;

class GetVpowerBetRecord extends Command
{

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'vpower_bet_record {--type=} {--start=} {--end=}';
    //每次跑一個小時 php artisan vpower_bet_record --type=1 --start=2020-03-01@00:00:00 --end=2020-03-01@23:59:59

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '更新Vpower投注紀錄';

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
            $type = $this->option('type') ? $this->option('type') : 1;
            $start = $this->option('start')
                ? str_replace('@', ' ', $this->option('start'))
                : date('Y-m-d H:i:00', strtotime('+7hours+30minutes'));
            $end = $this->option('end')
                ? str_replace('@', ' ', $this->option('end'))
                : date('Y-m-d H:i:00', strtotime($start . ' +1hour'));

            if(file_exists(base_path() . '/gobin/getBetRecords')) {
                $resultCode = 1;
                chdir(base_path() . '/gobin/getBetRecords');
                $cmd = sprintf('./getBetRecords vpower --start="%s" --end="%s"', $start, $end);
                if(Common::isWindows()) {
                    $cmd = sprintf('getBetRecords.exe vpower --start="%s" --end="%s"', $start, $end);
                }
                echo $cmd . PHP_EOL;
                system($cmd, $resultCode);
                if($resultCode == 0) {
                    return;
                }
            }


            echo $start . ' to ' . $end . PHP_EOL;
            $BeginTime = $start;
            $EndTime = $end;
            $settings = VendorInfo::where('vendor_id', 22)->where('status', 1)->get();
            $used = []; //已執行過的不再執行
            foreach ($settings as $row) {
                //將遊戲商資訊解密
                $info = Common::decryptVendorInfo($row);
                $rate = isset($row->rate) ? $row->rate : 1;
                if (is_object($info) && !in_array($info->API_APPID, $used)) {
                    $runStartTime = microtime(true);
                    $recordCount = $userCount = $writeCount = 0;
                    echo '======' . $row->name . '======' . PHP_EOL;
                    if ($type == 0) {
                        //API只能撈單個玩家的投注紀錄，所以要查詢有幾個玩家已綁定會員資料
                        $users = VpowerAccount::where('vendor_info_id', $row->id)
                            ->where('member_id', '>', 0)
                            ->get();
                        if (is_object($users)) {
                            foreach ($users as $user) {
                                $username = $user['username'];
                                $userCount++;
                                $records = $this->getAccountBetRecord($info, $BeginTime, $EndTime, $username);
                                $datas = array();
                                foreach($records as $bet) {
                                    $recordCount++;
                                    $data = array();
                                    $data['uniqleid'] = $bet['uniqleid'];
                                    $data['uid'] = $bet['uid'];
                                    $data['gameid'] = $bet['gameid'];
                                    $data['tableid'] = $bet['tableid'];
                                    $data['username'] = $username;
                                    $data['pay'] = floatval($bet['pay'] / $rate);
                                    $data['win'] = floatval($bet['win'] / $rate);
                                    $data['startscore'] = floatval($bet['startscore'] / $rate);
                                    $data['finalscore'] = floatval($bet['finalscore'] / $rate);
                                    $time = $bet['time'];
                                    $data['time'] = $time;
                                    $uct_time = date("Y-m-d H:i:s", $bet['time']);;
                                    $data['utc_time'] = $uct_time;
                                    $datas[] = $data;
                                }
                                $writeCount += $this->insertIgnoreBatch($datas);
                                printf("*%d/%d* account=[%s] [%d/%d], ", $userCount, sizeof($user), $username, $recordCount, $writeCount);
                                echo PHP_EOL;
                                //$this->addRecord($info, $BeginTime, $EndTime, $username, $page = 1);
                                //printf("[%d/%d]...", $userCount, count($user));
                            }
                        }
                        printf(PHP_EOL . "獲取時間段 $start ~ $end, 共 [%d] 用戶 [%d] 注單, 本次寫入 [%d] 注單, 耗時 %d 秒" . PHP_EOL, $userCount, $recordCount, $writeCount, (microtime(true) - $runStartTime));
                    } elseif ($type == 1) {
                        $increase = "+1 Minutes - 1 second";
                        $runTime = $BeginTime;
                        do {
                            $runEndTime = date("Y-m-d H:i:s", strtotime($runTime . " " . $increase));
                            $records = $this->getAccountBetRecord($info, $runTime, $runEndTime, '');
                            $datas = array();
                            foreach ($records as $bet) {
                                $recordCount++;
                                $data = array();
                                $data['uniqleid'] = $bet['uniqleid'];
                                $data['uid'] = $bet['uid'];
                                $data['gameid'] = $bet['gameid'];
                                $data['tableid'] = $bet['tableid'];
                                $data['username'] = str_replace("vworld", "", $bet['username']);
                                $data['pay'] = floatval($bet['pay'] / $rate);
                                $data['win'] = floatval($bet['win'] / $rate);
                                $data['startscore'] = floatval($bet['startscore'] / $rate);
                                $data['finalscore'] = floatval($bet['finalscore'] / $rate);
                                $time = $bet['time'];
                                $data['time'] = $time;
                                $uct_time = date("Y-m-d H:i:s", $bet['time']);;
                                $data['utc_time'] = $uct_time;
                                $datas[] = $data;
                            }
                            //$foo = collect($datas)->whereIn('uniqleid', ['159914880010','159914880040','159914880060','159914880070','159914880080'])->all();
                            //var_dump($foo);
                            $foo = collect($datas)->pluck('uniqleid')->all();
                            //var_dump($datas);
                            //echo join("','", $foo);
                            $this_write_count = $this->insertIgnoreBatch($datas);
                            $writeCount += $this_write_count;
                            printf("*time=%s-%s [%d/%d], ", $runTime, $runEndTime, sizeof($records), $this_write_count);
                            echo PHP_EOL;
                            $runTime = date("Y-m-d H:i:s", strtotime($runEndTime . " + 1 second"));

                        } while(strtotime($runTime) < strtotime($end));
                        printf(PHP_EOL . "獲取時間段 $start ~ $end, [%d] 注單, 本次寫入 [%d] 注單, 耗時 %d 秒" . PHP_EOL, $recordCount, $writeCount, (microtime(true) - $runStartTime));
                        //$this->addRecord($info, $BeginTime, $EndTime, $username = '', $page = 1);
                    }
                    $used[] = $info->API_APPID;
                } else {
                    echo $row->name . '解密失敗' . PHP_EOL;
                }
            }

            //total bet record
            echo '===== total bet record Start =====' . PHP_EOL;
            DB::select('call SP_transfer_bet_record_vpower(null, null, null)');
            echo '===== total bet record End =====' . PHP_EOL;
        } catch (\Illuminate\Database\QueryException $e) {
            $info = $e->errorInfo;
            echo $info[0] . ' : ' . $info[2];
        } catch (\Exception $e) {
            echo $e->getCode() . ' : ' . $e->getMessage();
        }
    }
/*
    private function addRecord($info, $BeginTime, $EndTime, $username, $page)
    {
        $pagesize = 100;
        $url = $info->API_URL;
        $AppID = $info->API_APPID;
        $time = time();
        if ($username == '') {
            $Req = "Act=glog&GameId=&Username=&BeginTime=$BeginTime&EndTime=$EndTime&PageIndex=$page&Timestamp=$time&AppId=$AppID";
        } else {
            $Req = "Act=glog&GameId=&Username=$username&BeginTime=$BeginTime&EndTime=$EndTime&PageIndex=$page&Timestamp=$time&AppId=$AppID";
        }
        $Signature = rawurlencode($this->hmac_sha1($Req, $info->API_KEY));
        $url = $url . "?" . $Req . "&Signature=" . $Signature;
        $data = array();
        $data['Act'] = 'glog';
        $data['username'] = $username;
        $data['BeginTime'] = $BeginTime;
        $data['EndTime'] = $EndTime;
        $data['PageIndex'] = $page;
        $data['Timestamp'] = $time;
        $data['AppId'] = $AppID;
        $data['Signature'] = $Signature;

        $result = $this->get($url, $data);
        if (is_array($result) && $result['ErrorCode'] === 0) {
            foreach ($result['Data'] as $row) {
                $data = array();
                $data['uniqleid'] = $row['uniqleid'];
                $data['uid'] = $row['uid'];
                $data['gameid'] = $row['gameid'];
                $data['tableid'] = $row['tableid'];
                $data['username'] = $username;
                $data['pay'] = ($row['pay']);
                $data['win'] = ($row['win']);
                $data['startscore'] = ($row['startscore']);
                $data['finalscore'] = ($row['finalscore']);
                $time = $row['time'];
                $data['time'] = $time;
                $uct_time = date("Y-m-d H:i:s", $row['time']);;
                $data['utc_time'] = $uct_time;
                $this->recordCount++;
                $record = VpowerBetRecord::where('time', $time)->where('uid', $row['uid'])
                ->where('startscore', ($row['startscore']))->where('finalscore', ($row['finalscore']))->first();
                if (!is_object($record)) {
                 VpowerBetRecord::create($data);
                 echo '****' . $row['uniqleid'] . '****';
                }
            }
            if ($result['Total'] > $page * $pagesize) {
                $page++;
                $this->addRecord($info, $BeginTime, $EndTime, $username, $page);
            }
            echo 'sucess';
        } else {
            print_r($url);
            print_r($result);
            echo 'fail';
        }
    }
*/
    private function hmac_sha1($str, $key)
    {
        $signature = "";
        if (function_exists('hash_hmac')) {
            $signature = base64_encode(hash_hmac("sha1", $str, $key, true));
        } else {
            $blocksize = 64;
            $hashfunc = 'sha1';
            if (strlen($key) > $blocksize) {
                $key = pack('H*', $hashfunc($key));
            }
            $key = str_pad($key, $blocksize, chr(0x00));
            $ipad = str_repeat(chr(0x36), $blocksize);
            $opad = str_repeat(chr(0x5c), $blocksize);
            $hmac = pack(
                'H*', $hashfunc(
                    ($key ^ $opad) . pack(
                        'H*', $hashfunc(
                            ($key ^ $ipad) . $str
                        )
                    )
                )
            );
            $signature = base64_encode($hmac);
        }
        return $signature;
    }

    private function get($url, $data)
    {
        $data = json_encode($data);
        Common::log(0, $data, 'vpower_bet_record');
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Content-Type:application/json',
        ));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        $result = curl_exec($ch);
        Common::log(0, $result, 'vpower_bet_record');
        curl_close($ch);
        return json_decode($result, true);
    }

    //獲取目標時間段前一小時到後一小時
    public function getAccountBetRecord($info, $BeginTime, $EndTime, $username) {
        $retry = 0;
        $retry_limit = 3;
        $records = array();
        $page = 1;
        $total = 0;
        do {
            $nextLoop = false;
            $pageSize = 100;
            $result = $this->getAccountBetRecordByPage($info, $BeginTime, $EndTime, $username, $page);
            if (is_array($result) && $result['ErrorCode'] === 0) {
                $total = intval($result['Total']);
                $retry = 0;
                array_push($records, ...$result['Data']);
                //printf("total %d, fetch %d", intval($result['Total']),  ($page * $pageSize));
                if (intval($result['Total']) > ($page * $pageSize)) {
                    $nextLoop = true;
                    $page++;
                }
            } else if (is_array($result) && $result['ErrorCode'] === 15) {
                continue;
            } else {
                if ($retry < $retry_limit) {
                    $retry++;
                    echo PHP_EOL . "Retry $retry times" . PHP_EOL ;
                    print_r($result);
                    $nextLoop = true;
                    sleep(1);
                    continue;
                }
                $retry = 0;
            }
        } while($nextLoop);
        if ($total != sizeof($records)) {
            echo sprintf("st %s, et %s, total %d, records count %d", $BeginTime, $EndTime, $total, sizeof($records));

        }
        return $records;
    }

    // call from $this->getAccountBetRecord
    private function getAccountBetRecordByPage($info, $BeginTime, $EndTime, $username, $page) {
        $url = $info->API_URL;
        $AppID = $info->API_APPID;
        $time = time();
        if ($username == '') {
            $Req = "Act=glog&GameId=&Username=&BeginTime=$BeginTime&EndTime=$EndTime&PageIndex=$page&Timestamp=$time&AppId=$AppID";
        } else {
            $Req = "Act=glog&GameId=&Username=$username&BeginTime=$BeginTime&EndTime=$EndTime&PageIndex=$page&Timestamp=$time&AppId=$AppID";
        }
        $Signature = rawurlencode($this->hmac_sha1($Req, $info->API_KEY));
        $url = $url . "?" . str_replace(" ", "%20", $Req)  . "&Signature=" . $Signature;
        $data = array();
        $data['Act'] = 'glog';
        $data['GameId'] = '';
        $data['Username'] = $username;
        $data['BeginTime'] = $BeginTime;
        $data['EndTime'] = $EndTime;
        $data['PageIndex'] = $page;
        $data['Timestamp'] = $time;
        $data['AppId'] = $AppID;
        $data['Signature'] = $Signature;
        //echo $url . PHP_EOL;
        $header = array('Content-Type:application/json');
        $response = $this->vendorsend->send('vpower_get_bet_record', $url, 'GET', [], $header);
        //var_dump($response);
        $result = json_decode($response, true);
        //$result = $this->get($url, $data);
        $result['request_url'] = $url;
        return $result;
    }

    private function insertIgnore($datas) {
        $fields = array_keys($datas[0]);
        $sql = "INSERT IGNORE INTO vpower_bet_records (`".join('`,`', $fields)."`)";
        $values = array();
        $bindings = array();
        foreach ($datas as $row) {
            foreach ($row as $v) {
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
        for ($i = 1; $i <= $batchTotal; $i++) {
            $index = ($i - 1) * $batchSize;
            $batchData = array_slice($datas, $index, $batchSize);
            $affectedRows += $this->insertIgnore($batchData);
        }
        return $affectedRows;
    }
}
