<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Helpers\Common;
use App\Helpers\VendorSend;
use App\Models\VendorInfo;
use App\Models\EsportBetRecord;
use DB;

class GetEsportBetRecord extends Command
{

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'esport_bet_record {--start=}{--end=}'; //每次跑一個小時 php artisan sky1388_bet_record --start=2020-03-01@00

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '更新Esport投注紀錄';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    protected $vendorsend;
    protected $vendor_id = 38;
    protected $action = 'esport_get_bet_record';

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
            $start_time = $this->option('start')
                ? str_replace('@', ' ', $this->option('start')) . ':00:00'
                : date('Y-m-d H:i:s', strtotime('+7hour'));
            $end_time = $this->option('end')
                ? str_replace('@', ' ', $this->option('end')) . ':00:00'
                : date('Y-m-d H:i:s', strtotime('+8hour'));
            echo '時間範圍:' . $start_time . ' to ' . $end_time . PHP_EOL;
            //API是UTC+0時間，所以要轉成UTC+0
            $start_time = date('Y-m-d H:i:s', strtotime($start_time . '-8hour'));
            $end_time = date('Y-m-d H:i:s', strtotime($end_time . '-8hour'));

            $settings = VendorInfo::where('vendor_id', $this->vendor_id)
                ->where('status', 1)
                ->get();
            $used = []; //已執行過的不再執行

            if (count($settings) > 0) {
                foreach ($settings as $setting) {
                    $info = json_decode($setting->info);
                    if (is_object($info) && !in_array($info->KEY, $used)) {
                        $records = $this->getBetRecord($info, $start_time, $end_time);
                        if (count($records) > 0) {
                            $this->addRecord($records);

                            $used[] = $info->KEY;
                        } else {
                            echo 'no records';
                        }
                    }
                }
            }

            //total bet record
            echo '===== total bet record Start =====' . PHP_EOL;
            DB::select('call SP_transfer_bet_record_esport(null, null, null)');
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
        $pageIndex = 1;
        do {
            $nextLoop = false;
            $result = $this->getBetRecordByPage($info, $start_time, $end_time, $pageIndex);
            if (is_array($result) && $result['code'] === 0) {
                array_push($records, ...$result['data']['dataList']);
                if ($result['data']['totalPages'] > $pageIndex) {
                    $nextLoop = true;
                    $pageIndex++;
                }
            }
        } while ($nextLoop);
        return $records;
    }

    private function getBetRecordByPage($info, $start_time, $end_time, $pageIndex)
    {
        $url = $info->API_URL . '/betting/get_bet';
        echo $start_time . PHP_EOL;
        $sTime = strtotime($start_time);
        echo $sTime . PHP_EOL;
        $eTime = strtotime($end_time);
        $param = array(
            'platFormId' => $info->PLATFORMID,
            'startTime' => $sTime,
            'endTime' => $eTime,
            'page' => $pageIndex,
            'pageSize' => 1000
        );
        $postData = ['data' => $this->aes($info, json_encode($param))];

        $headers = array('Content-Type: application/json');
        $response = $this->vendor_send->send($this->action, $url, 'POST', $postData, $headers);
        $result = json_decode($response, true);

        return $result;
    }

    private function addRecord($records)
    {
        $datas = array();
        foreach ($records as $list) {
            $data = array();
            $data['bet_id'] = $list['orderId'];
            $data['username'] = $list['userNname'];
            $data['game_code'] = 'esport';
            $data['bet_time'] = date('Y-m-d H:i:s', $list['settleTime']);
            $data['created_time'] = date('Y-m-d H:i:s', $list['betTime']);
            $data['bet_status'] = $list['status'];
            $data['bet'] = $list['amount'];
            $data['win_lose'] = $list['win'] - $list['amount'];
            $data['info'] = json_encode($list);
            $datas[] = $data;
        }
        $records = $this->filterRecordsNotExists($datas);
        EsportBetRecord::insert($records['createData']);
        echo '*****' . $data['bet_id'] . '*****' . PHP_EOL;
        foreach ($records['updateData'] as $data) {
            EsportBetRecord::where('bet_id', $data['bet_id'])->update($data);
        }
        printf("*recordCount [%d] writeCount [%d] updateCount [%d]",
            count($datas), count($records['createData']), count($records['updateData']));
        echo PHP_EOL . 'success' . PHP_EOL;
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
                ...EsportBetRecord::whereIn($unique_field, $ids)
                ->select($unique_field)
                ->get()
                ->pluck($unique_field)
                ->toArray()
            );
        }

        // 分開己經存在的押注記錄
        $createData = $collect->filter(function ($item) use ($unique_field, $exists_ids) {
            return !in_array($item[$unique_field], $exists_ids);
        })->all();
        $updateData = $collect->reject(function ($item) use ($unique_field, $exists_ids) {
            return !in_array($item[$unique_field], $exists_ids);
        })->all();

        return ['createData' => $createData, 'updateData' => $updateData];
    }


    private function aes($info, $str)
    {
        $encrypter = openssl_encrypt($str, 'aes-256-cbc', $info->KEY, 1, $info->IV);
        return base64_encode($encrypter);
    }
}
