<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Helpers\Common;
use App\Helpers\VendorSend;
use App\Models\VendorInfo;
use App\Models\Bola2BetRecord;
use DB;

class GetBola2BetRecord extends Command
{

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'bola2_bet_record {--start=}{--end=}';
    //每次跑一天 php artisan bola_bet_record --start=2020-03-01 --end=2020-03-01

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '更新Bola2投注紀錄';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    protected $vendor_send;
    protected $vendor_id = 45;
    protected $action = 'Bola2_bet_record';

    public function __construct()
    {
        parent::__construct();
        $this->vendor_send = new VendorSend(['json_post' => true]);
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
            //這個+3不是時區問題,遊戲商當天14:00 ~ 隔天早上04:00都算做同一天,所以讓+8時區5點時也會撈前一天的注單
            $start_time = $this->option('start')
                ? $this->option('start')
                : date('Y-m-d', strtotime('+3hour'));
            $end_time = $this->option('end')
                ? $this->option('end')
                : $start_time;
            echo '時間範圍:' . $start_time . ' to ' . $end_time . PHP_EOL;

            $settings = VendorInfo::where('vendor_id', $this->vendor_id)
                ->where('status', 1)
                ->get();
            if (count($settings) > 0) {
                foreach ($settings as $setting) {
                    $info = json_decode($setting->info);
                    if (is_object($info)) {
                        $records = $this->getBetRecord($info, $start_time, $end_time);
                        if (count($records) > 0) {
                            $this->addRecord($records);
                        } else {
                            echo 'no record';
                        }
                    }
                }
            }

            //total bet record
            echo '===== total bet record Start =====' . PHP_EOL;
            DB::select('call SP_transfer_bet_record_bola2(null, null, null)');
            echo '===== total bet record End =====' . PHP_EOL;
        } catch (\Illuminate\Database\QueryException $e) {
            $info = $e->errorInfo;
            echo $info[0] . ' : ' . $info[2];
        } catch (\Exception $e) {
            echo $e->getCode() . ' : ' . $e->getMessage();
        }
    }

    private function getBetRecord($info, $start_time, $end_time)
    {
        $records = array();
        $url = $info->API_URL . '/report/all/?';
        $url .= 'date_start=' . $start_time . '&date_end=' . $end_time;
        $pageIndex = 1;
        do {
            $netLoop = false;
            //size上限為1000
            $pageSize = 1000;
            $result = $this->getBetRecordByPage($info, $url, $pageIndex, $pageSize);
            if (is_array($result) && $result['Status'] == 200) {
                echo 'page : ' . $pageIndex . PHP_EOL;
                if ($result['Data'] != '') {
                    array_push($records, ...$result['Data']);
                    if (count($result['Data']) >= $pageSize) {
                        $netLoop = true;
                        $pageIndex++;
                    }
                }
            } else {
                echo 'message : ' . $result['Message'] . PHP_EOL;
            }
        } while ($netLoop);


        if (is_array($result) && $result['Status'] == 'success') {
            array_push($records, ...$result['Data']);
        }

        return $records;
    }

    private function getBetRecordByPage($info, $url, $pageIndex, $pageSize)
    {
        $url = $url . '&page=' . $pageIndex . '&size=' . $pageSize;

        $random = str_pad(mt_rand(0, 999999), 6, "0", STR_PAD_BOTH);

        $data = array(
            'AgentName' => $info->AGENT_NAME,
            'Random' => $random,
            'HashKey' => $this->hash($info->AGENT_NAME . $random . $info->API_KEY)
        );

        $header = array('Content-Type:application/json');
        $response = $this->vendor_send->send($this->action, $url, 'POST', $data, $header);
        $result = json_decode($response, true);

        return $result;
    }

    private function addRecord($records)
    {
        $datas = array();
        foreach ($records as $list) {
            if($list['Status'] != ""){
                $data = array();
                $data['bet_id'] = $list['TransactionId'];
                $data['username'] = $list['LoginUsername'];
                $data['game_code'] = 'bola2';
                $data['RoundDate'] = date('Y-m-d H:i:s', strtotime($list['RoundDate'] . ' -8hours'));
                $data['bet_time'] = date('Y-m-d H:i:s', strtotime($list['TransactionDate'] . ' -8hours'));
                //會有千分位
                $stake = str_replace(',', '', $list['Stake']);
                $data['bet'] = $stake;
                $data['win_lose'] = str_replace(',', '', $list['Payout']) - $stake;
                $data['info'] = json_encode($list);
                $datas[] = $data;
            }
        }
        $records = $this->filterRecordsNotExists($datas);

        Bola2BetRecord::insert($records);
        echo 'success: ';
        printf("*recordCount [%d] writeCount [%d] ", count($datas), count($records));
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
                ...Bola2BetRecord::whereIn($unique_field, $ids)
                ->select($unique_field)
                ->get()
                ->pluck($unique_field)
                ->toArray()
            );
        }
        // 過濾掉己經存在的押注記錄
        return $collect->filter(function ($item) use ($unique_field, $exists_ids) {
            return !in_array($item[$unique_field], $exists_ids);
        })->all();
    }

    /**
     * -取簽名
     */
    private function hash($str)
    {
        return md5($str);
    }

}
