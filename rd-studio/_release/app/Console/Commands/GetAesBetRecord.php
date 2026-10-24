<?php

namespace App\Console\Commands;

use App\Helpers\VendorSend;
use Illuminate\Console\Command;
use App\Helpers\Common;
use App\Models\AesBetRecord;
use App\Models\VendorInfo;
use DB;

class GetAesBetRecord extends Command
{

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'aes_bet_record {--start=} {--infoId=}'; //每次跑一個小時 php artisan aes_bet_record --start=2020-03-01@00

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '更新aes投注紀錄';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    private $header;
    protected $vendorsend;
    protected $api_path;

    public function __construct()
    {
        parent::__construct();
        $this->vendorsend = new VendorSend();
        $this->header = array('Content-Type : application/x-www-form-urlencoded; charset=UTF-8');

        $today = date('Y-m-d');

        // 2022-05-11後更新拉帐API domain
        if ($today > '2022-05-11') {
            // 2022/05/11 起即生效
            $this->api_path = '/fetch/gzip';
        } else {
            // 2022/07/01 起即失效
            $this->api_path = '/fetch';
        }
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
            /*
            $date = $this->option('start')
               ? str_replace('@', 'T', $this->option('start')) . ':00:00+00:00'
               : str_replace(' ', 'T', date('Y-m-d H:i:00', strtotime('-1hour'))) . '+00:00';
            */
            $vendor_info_id = $this->option('infoId')
                ? $this->option('infoId')
                : 0;

            $settings = VendorInfo::where('vendor_id', 25)->where('status', 1);
            if ($vendor_info_id > 0) {
                $settings = $settings->where('id', $vendor_info_id);
            }
            $settings = $settings->get();

            $used = []; //已執行過的不再執行

            foreach ($settings as $row) {
                //將遊戲商資訊解密
                $info = Common::decryptVendorInfo($row);
                echo $row->name . PHP_EOL;
                $rate = isset($row->rate) ? $row->rate : 1;
                if (is_object($info) && !in_array($info->AGENT, $used)) {
                    if ($this->option('start')) {
                        $url = $info->API_BET_URL . $this->api_path . '/getTransactionByTxTime';
                        $sDate = str_replace('@', ' ', $this->option('start')) . ':00:00';
                        $eDate = date('Y-m-d H:i:00', strtotime($sDate . '+1hour'));
                        $sDate = date("c", strtotime($sDate));
                        $eDate = date("c", strtotime($eDate));
                        echo $sDate . ' ~ ' . $eDate . PHP_EOL;
                        $data = array(
                            'cert' => $info->CERT,
                            'agentId' => $info->AGENT,
                            'startTime' => $sDate,
                            'endTime' => $eDate,
                            'platform' => 'SEXYBCRT'
                        );
                    } else {
                        $url = $info->API_BET_URL . $this->api_path . '/getTransactionByUpdateDate';
                        //$record = AesBetRecord::orderBy('updateTime', 'desc')->first();
                        $record = DB::table('aes_bet_records as b')
                            ->join('aes_accounts as v', 'b.userId', 'v.username')
                            ->where('v.vendor_info_id', $row->id)
                            ->where('b.updateTime', '>=', date('Y-m-d H:i:s', strtotime('-7DAY')))
                            ->orderBy('b.updateTime', 'desc')
                            ->first();
                        $lastUpdateTime = strtotime($record->updateTime);
                        $now = strtotime('now');
                        $diff = $now - $lastUpdateTime;
                        $timeFrom = date("c", strtotime($record->updateTime));
                        if (!$record || $diff > (10*60*60)) {
                            $timeFrom = date("c", $now);
                            echo 'last update time was over 10 hours use timeFrom as ' . $timeFrom . PHP_EOL;
                            $timeFrom = date("c", strtotime($timeFrom . ' -8 hours'));
                            echo 'so let timeFrom as ' . $timeFrom. PHP_EOL;
                        }

                        echo $timeFrom . PHP_EOL;
                        $data = array(
                            'cert' => $info->CERT,
                            'agentId' => $info->AGENT,
                            'timeFrom' => $timeFrom,
                            'platform' => 'SEXYBCRT'
                        );
                    }
                    $this->getRecord($url, $data, $rate);
                    $used[] = $info->AGENT;
                } else {
                    echo $row->name . '解密失敗' . PHP_EOL;
                }
            }

        } catch (\Illuminate\Database\QueryException $e) {
            $info = $e->errorInfo;
            echo $info[0] . ' : ' . $info[2];
        } catch (\Exception $e) {
            echo $e->getCode() . ' : ' . $e->getMessage();
        }
        echo 'end ' . PHP_EOL;
    }

    private function getRecord($url, $data, $rate = 1)
    {
        $record = array();
        $failCount = 0;
        do {
            $result = json_decode(gzdecode($this->vendorsend->send('aes_get_bet_record', $url, 'GET', $data)), true);
            $nextLoop = false;
            if (is_array($result) && $result['status'] == "0000") {
                $failCount = 0;
                array_push($record, ...$result['transactions']);
                if (isset($data['startTime']) && count($result['transactions']) >= 20000) {
                    $nextLoop = true;
                    $sDate = $this->getTime($result['transactions'][19999]['txTime']);
                    $sDate = $sDate = date("c", strtotime($sDate));
                    $data['startTime'] = $sDate;
                    sleep(20);
                }
            } else {
                if($failCount < 3) {
                    $failCount++;
                    echo 'fail '. $failCount . ' wait 20 secs will retry' . PHP_EOL;
                    $nextLoop = true;
                    sleep(20);
                } else {
                    echo 'fail please try again later.'. PHP_EOL;
                }
            }
        } while ($nextLoop);
        return $this->addRecord($record, $rate);
    }


    private function addRecord($betrecord, $rate = 1)
    {
        $datas = array();
        foreach ($betrecord as $row) {
            $data = array();
            $data['gameType'] = $row['gameType'];
            $data['winAmount'] = floatval($row['winAmount'] / $rate);
            $data['bet_time'] = $this->getTime($row['txTime']);
            $data['realWinAmount'] = floatval($row['realWinAmount'] / $rate);
            $data['updateTime'] = $this->getTime($row['updateTime']);
            $data['realBetAmount'] = floatval($row['realBetAmount'] / $rate);
            $data['userId'] = $row['userId'];
            $data['betType'] = $row['betType'];
            $data['platform'] = $row['platform'];
            $data['txStatus'] = $row['txStatus'];
            $data['betAmount'] = floatval($row['betAmount'] / $rate);
            $data['platformTxId'] = $row['platformTxId'];
            $data['gameCode'] = $row['gameCode'];
            $data['currency'] = $row['currency'];
            $data['jackpotWinAmount'] = floatval($row['jackpotWinAmount'] / $rate);
            $data['bet_id'] = (isset($row['ID']) && $row['ID'])?$row['ID']:$row['platform'] . $row['platformTxId']; // 3/17 9:00 以後移除 ID 欄位, 建議您使用platform+platformTxId為唯一值來記錄注單
            $data['jackpotBetAmount'] = floatval($row['jackpotBetAmount'] / $rate);
            $data['turnover'] = $row['turnover'];
            $data['roundId'] = $row['roundId'];
            $datas[] = $data;
//            $record = AesBetRecord::where('bet_id', $row['ID'])->first();
//            if (!is_object($record)) {
//                AesBetRecord::create($data);
//                echo '****' . $row['ID'] . '****' . PHP_EOL;
//            }
        }
        $create_records = $this->filterRecordsNotExists($datas);
        foreach($create_records as $data) {
            AesBetRecord::create($data);
            echo '*****' . $data['bet_id'] . '*****' . PHP_EOL;
        }
        printf("*recordCount [%d] writeCount [%d] ", count($datas), count($create_records));
        echo PHP_EOL;
        //total bet record
        echo '===== total bet record Start =====' . PHP_EOL;
        DB::select('call SP_transfer_bet_record_aes(null, null, null)');
        echo '===== total bet record End =====' . PHP_EOL;
        echo 'sucess' . PHP_EOL;
    }

    private function getTime($time)
    {
        $time = str_replace('Z', '', str_replace('T', ' ', $time));
        return $time;
    }

    /**
     * 過濾掉己經存在的押注記錄
     * @param $datas
     * @param int $size
     * @return array
     */
    private function filterRecordsNotExists($datas, $size = 100) {
        $exists_ids = array();
        $collect = collect($datas);
        $chunks = $collect->chunk($size); // 分組，一組 $size  個
        $unique_field = "bet_id";
        // 分組取出己經存在的押注記錄
        foreach ($chunks as $chunk) {
            $ids = $chunk->pluck($unique_field)->toArray();
            array_push(
                $exists_ids,
                ...AesBetRecord::whereIn($unique_field, $ids)
                    ->select($unique_field)
                    ->get()
                    ->pluck($unique_field)
                    ->toArray()
            );
        }
        // 過濾掉己經存在的押注記錄
        return $collect->filter(function($item) use($unique_field, $exists_ids){
            return !in_array($item[$unique_field], $exists_ids);
        })->all();
    }
}
