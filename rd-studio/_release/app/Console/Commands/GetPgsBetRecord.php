<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Helpers\Common;
use App\Helpers\VendorSend;
use App\Models\VendorInfo;
use DB;

class GetPgsBetRecord extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'pgs_bet_record {--start=} {--end=}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'target 投注記錄';

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
            $start = $this->option('start')
                ? str_replace('@', ' ', $this->option('start'))
                : date('Y-m-d H:i:s', strtotime('-1hour'));
            $end = $this->option('end') 
                ? str_replace('@', ' ', $this->option('end'))
                : date('Y-m-d H:i:s');
            echo $start . ' to ' . $end . PHP_EOL;
            $settings = VendorInfo::where('vendor_id', 24)
                ->where('status', 1)
                ->get();
            $used = []; //已執行過的不再執行
            $recordCount = $userCount = $writeCount = 0;
            foreach ($settings as $row) {
                $info = Common::decryptVendorInfo($row);
                $rate = isset($row->rate) ? $row->rate : 1;
                if (is_object($info) && !in_array($info->API_APPID, $used)) {
                    echo '======' . $row->name . '======' . PHP_EOL;
                    $records = $this->getAccountBetRecord($info, $start, $end);
                    $datas = array();
                    foreach($records as $r) {
                        $recordCount++;
                        $datas[] = array(
                            'username' => $r['playerName'],
                            'gameID' => $r['gameID'],
                            'beforeAmount' => floatval($r['beforeAmount'] / $rate),
                            'winLossAmount' => floatval($r['winLossAmount'] / $rate),
                            'betAmount' => floatval($r['betAmount'] / $rate),
                            'payoutAmount' => floatval($r['payoutAmount'] / $rate),
                            'validAmount' => floatval($r['validAmount'] / $rate),
                            'roundID' => $r['roundID'],
                            'betID' => $r['betID'],
                            'betTime' => $r['betTime'],
                            'roundStartTime' => $r['roundStartTime'],
                            'roundEndTime' => $r['roundEndTime'],
                        );
                    }
                    $writeCount += $this->insertIgnoreBatch($datas);
                    printf("*recordCount [%d] writeCount [%d] ", $recordCount, $writeCount);
                    echo PHP_EOL;
                    $used[] = $info->API_APPID;
                }
            }
            //total bet record
            echo '===== total bet record Start =====' . PHP_EOL;
            DB::select('call SP_transfer_bet_record_pgs(null, null, null)');
            echo '===== total bet record End =====' . PHP_EOL;
        } catch (\Illuminate\Database\QueryException $e) {
            $info = $e->errorInfo;
            echo $info[0] . ' : ' . $info[2];
        } catch (\Exception $e) {
            echo $e->getCode() . ' : ' . $e->getMessage();
        }
    }

    private function insertIgnore($datas) {
        $fields = array_keys($datas[0]);
        $sql = "INSERT IGNORE INTO pgs_bet_records (".join(',', $fields).")";
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

    //獲取目標時間段前一小時到後一小時
    public function getAccountBetRecord($info, $sDate, $eDate) {
        $records = array();
        $pageIndex = 1;
        do {
            $nextLoop = false;
            $pageSize = 1000;
            $result = $this->getAccountBetRecordByPage($info, $sDate, $eDate, $pageIndex, $pageSize);
            //dd($result);
            if (is_array($result) && is_array($result['data']['items'])) {
                array_push($records, ...$result['data']['items']);
                if (intval($result['data']['totalCount']) > ($pageIndex * $pageSize)) {
                    $nextLoop = true;
                    $pageIndex++;
                }
            }
        } while($nextLoop);

        return $records;
    }

    // call from $this->getAccountBetRecord
    private function getAccountBetRecordByPage($info, $sDate, $eDate, $pageIndex, $pageSize) {
        $url = $info->API_URL . 'v1/game-records';
        $data = array();
        $data['merchantID'] = $info->API_APPID;
        $data['MerchantSecretKey'] = $info->API_KEY;
        $data['pageIndex'] = $pageIndex;
        $data['pageSize'] = $pageSize;
        $data['startTime'] = $sDate;
        $data['endTime'] = $eDate;
        $vendorSend = new VendorSend();
        $result = $vendorSend->send('pgs_getAccountBetRecordByPage', $url, 'GET', $data);
        //$result = HttpClient::get($url, $data);
        return json_decode($result, true);
    }
}
