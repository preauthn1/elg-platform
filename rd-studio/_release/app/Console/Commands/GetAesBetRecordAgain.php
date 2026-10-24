<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Helpers\VendorSend;
use App\Helpers\Common;
use App\Models\AesBetRecord;
use App\Models\VendorInfo;
use DB;

class GetAesBetRecordAgain extends Command
{

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'aes_bet_record_again {--start=}'; //每次跑一個小時 php artisan aes_bet_record --start=2020-03-01@00

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '重撈aes投注紀錄';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    private $header;
    protected $vendorsend;

    public function __construct()
    {
        parent::__construct();
        $this->vendorsend = new VendorSend();
        $this->header = array('Content-Type : application/x-www-form-urlencoded; charset=UTF-8');
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        try {
            $sDate = $this->option('start')
                ? str_replace('@', ' ', $this->option('start')) . ':00:00'
                : date('Y-m-d H:i:00', strtotime('-1hour'));
            $eDate = date('Y-m-d H:i:00', strtotime($sDate.'+1hour'));
            echo $sDate . ' ~ ' . $eDate . PHP_EOL;
            $settings = VendorInfo::where('vendor_id', 25)->where('status', 1)->get();
            $sDate = str_replace(' ', 'T', $sDate) . '+00:00';
            $eDate = str_replace(' ', 'T', $eDate) . '+00:00';
            $used = []; //已執行過的不再執行
            foreach ($settings as $row) {
                //將遊戲商資訊解密
                $info = Common::decryptVendorInfo($row);
                $rate = isset($row->rate) ? $row->rate : 1;
                if (is_object($info) && !in_array($info->AGENT, $used)) {
                    echo '======' . $row->name . '======' . PHP_EOL;
                    $data = array(
                        'cert' => $info->CERT,
                        'agentId' => $info->AGENT,
                        'startTime' => $sDate,
                        'endTime' => $eDate,
                        'platform' => 'SEXYBCRT'
                    );
                    $this->addRecord($info, $data, $rate);
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
    }


    private function addRecord($info, $data, $rate = 1)
    {
        $url = $info->API_BET_URL . '/fetch/getTransactionByTxTime';
        $result = json_decode(gzdecode($this->vendorsend->send('aes_get_bet_record_again', $url, 'GET', $data)), true);
        if (is_array($result) && $result['status'] == "0000") {
            $datas = array();
            foreach ($result['transactions'] as $row) {
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
                // 3/17 9:00 以後移除 ID 欄位, 建議您使用platform+platformTxId為唯一值來記錄注單
                $data['bet_id'] = (isset($row['ID'])) ? $row['ID'] : $row['platform'] . $row['platformTxId'];
                $data['jackpotBetAmount'] = floatval($row['jackpotBetAmount'] / $rate);
                $data['turnover'] = $row['turnover'];
                $data['roundId'] = $row['roundId'];
                $datas[] = $data;
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
        } else {
            echo 'fail' . PHP_EOL;
        }
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
        return $collect->filter(function($item) use($unique_field, $exists_ids) {
            return !in_array($item[$unique_field], $exists_ids);
        })->all();
    }

    private function getTime($time)     {
        $time = str_replace('Z', '', str_replace('T', ' ', $time));
        return $time;
    }
}
