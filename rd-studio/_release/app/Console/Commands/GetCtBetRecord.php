<?php

namespace App\Console\Commands;

use App\Helpers\Common;
use App\Helpers\HttpClient;
use App\Helpers\VendorSend;
use App\Models\PgsAccount;
use App\Models\VendorInfo;
use DB;
use Illuminate\Console\Command;

class GetCtBetRecord extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'ct_bet_record';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'CT 投注記錄';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        try {
            // ct 注單, 不需時間段要標記己抓取
            $settings = VendorInfo::where('vendor_id', 27)->where('status', 1)->get();
            $used = []; //已執行過的不再執行

            $recordCount = $userCount = $writeCount = 0;
            foreach ($settings as $row) {
                $info = Common::decryptVendorInfo($row);
                if (is_object($info) && !in_array($info->API_KEY, $used)) {
                    $records = $this->getAccountBetRecord($info);
                    $datas = array();
                    foreach ($records as $r) {
                        $recordCount++;
                        $datas[] = array(
                            'betId' => $r['id'],
                            'userName' => $r['userName'],
                            'tableId' => $r['tableId'],
                            'shoeId' => $r['shoeId'],
                            'playId' => $r['playId'],
                            'lobbyId' => $r['lobbyId'],
                            'gameType' => $r['gameType'],
                            'gameId' => $r['gameId'],
                            'memberId' => $r['memberId'],
                            'betTime' => $r['betTime'],
                            'calTime' => $r['calTime'],
                            'betTimeUtc' => date('Y-m-d H:i:s', strtotime($r['betTime'] . " -8 hours")),
                            'calTimeUtc' => date('Y-m-d H:i:s', strtotime($r['calTime'] . " -8 hours")),
                            'winOrLoss' => $r['winOrLoss'],
                            'balanceBefore' => $r['balanceBefore'],
                            'betPoints' => $r['betPoints'],
                            'betPointsz' => $r['betPointsz'],
                            'availableBet' => $r['availableBet'],
                            'result' => $r['result'],
                            'betDetail' => $r['betDetail'],
                            'ip' => $r['ip'],
                            'ext' => $r['ext'],
                            'isRevocation' => $r['isRevocation'],
                            'currencyId' => $r['currencyId'],
                            'deviceType' => $r['deviceType'],
                            'pluginid' => $r['pluginid'],
                        );
                    }
                    //var_dump($datas);
                    $writeCount += $this->insertIgnoreBatch($datas);
                    $this->tagBetRecordsBatch($info, $datas);
                    printf("*recordCount [%d] writeCount [%d] ", $recordCount, $writeCount);
                    echo PHP_EOL;
                    $used[] = $info->API_KEY;
                } else {
                    echo $row->name . '解密失敗' . PHP_EOL;
                }
            }
            //total bet record
            echo '===== total bet record Start =====' . PHP_EOL;
            DB::select('call SP_transfer_bet_record_ct(null, null, null)');
            echo '===== total bet record End =====' . PHP_EOL;
        } catch (\Illuminate\Database\QueryException $e) {
            $info = $e->errorInfo;
            echo $info[0] . ' : ' . $info[2];
        } catch (\Exception $e) {
            echo $e->getCode() . ' : ' . $e->getMessage();
        }
    }

    private function insertIgnore($datas)
    {
        $fields = array_keys($datas[0]);
        $sql = "INSERT IGNORE INTO ct_bet_records (" . join(',', $fields) . ")";
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

    //獲取目標時間段前一小時到後一小時
    public function getAccountBetRecord($info)
    {
        $records = array();
        do {
            $nextLoop = false;
            $result = $this->getAccountBetRecordByPage($info);
            if (is_array($result) && $result['codeId'] === 0 && isset($result['list'])) {
                array_push($records, ...$result['list']);
                if (count($result['list']) >= 1000) {
                    $nextLoop = true;
                    sleep(10);
                }
            }
        } while ($nextLoop);

        return $records;
    }

    // call from $this->getAccountBetRecord
    private function getAccountBetRecordByPage($info)
    {
        $url = $info->API_URL . 'api/getReport/' . $info->AGENT_NAME . '/';
        $random = md5(uniqid());
        $token = [REDACTED] . $info->API_KEY . $random);
        $data = [
            'random' => $random,
            'token' => $token
        ];
        $vendorSend = new VendorSend(['json_post' => true]);
        $headers = array('Content-Type: application/json');
        $result = $vendorSend->send('ct_getAccountBetRecordByPage', $url, 'POST', $data, $headers);
        //var_dump($result);
        //$result = HttpClient::get($url, $data);
        return json_decode($result, true);
    }

    private function tagBetRecordsBatch($info, $datas, $batchSize = 1000)
    {
        $total = sizeof($datas);
        $batchTotal = ceil($total / $batchSize);
        for ($i = 1; $i <= $batchTotal; $i++) {
            $index = ($i - 1) * $batchSize;
            $batchData = array_slice($datas, $index, $batchSize);
            $this->tagBetRecords($info, $batchData);
        }
    }

    private function tagBetRecords($info, $datas)
    {
        $ids = array();
        foreach ($datas as $row) {
            $ids[] = $row['betId'];
        }
        $url = $info->API_URL . 'api/mark/' . $info->AGENT_NAME . '/';
        $random = md5(uniqid());
        $token = [REDACTED] . $info->API_KEY . $random);
        $data = [
            'random' => $random,
            'token' => $token,
            'list' => $ids
        ];
        $vendorSend = new VendorSend(['json_post' => true]);
        $headers = array('Content-Type: application/json');
        $result = $vendorSend->send('ct_getAccountBetRecordByPage', $url, 'POST', $data, $headers);
        //$result = HttpClient::get($url, $data);
        return json_decode($result, true);
    }
}
