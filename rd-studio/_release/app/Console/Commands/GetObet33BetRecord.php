<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Helpers\Common;
use App\Helpers\VendorSend;
use App\Models\VendorInfo;
use App\Models\Obet33BetRecord;
use DB;

class GetObet33BetRecord extends Command
{

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'obet33_bet_record {--start=}{--end=}{--date}'; //每次跑一個小時 php artisan obet33_bet_record --start=2020-03-01@00

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '更新Obet33投注紀錄';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    protected $vendor_send;
    protected $vendor_id = 43;
    protected $action = 'Obet33_bet_record';

    public function __construct()
    {
        parent::__construct();
        $this->vendor_send = new VendorSend();
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
                : date('Y-m-d H:i:s', strtotime('+7hour -5minute'));
            $end_time = $this->option('end')
                ? str_replace('@', ' ', $this->option('end')) . ':00:00'
                : date('Y-m-d H:i:s', strtotime('+8hour -5minute'));
            if ($this->option('date')) { // input timezone GMT+8 because it is date format
                $data['stmtdate'] = str_replace("-", "", $this->option('date'));
            }
            printf('時間範圍:%s', ($this->option('date'))?$this->option('date'):"" . $start_time . ' to ' . $end_time . PHP_EOL);

            $settings = VendorInfo::where('vendor_id', $this->vendor_id)
                ->where('status', 1)
                ->get();
            if (count($settings) > 0) {
                foreach ($settings as $setting) {
                    $rate = isset($setting->rate) ? floatval($setting->rate) : 1;
                    $info = json_decode($setting->info);
                    if (is_object($info)) {
                        $records = $this->getBetRecord($info, $start_time, $end_time);
                        if (count($records) > 0) {
                            $this->addRecord($records, $rate);
                        }
                    }
                }
            }

            //total bet record
            echo '===== total bet record Start =====' . PHP_EOL;
            DB::select('call SP_transfer_bet_record_obet33(null, null, null)');
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
        $url = $info->API_URL . '/api/sb/betinfo';
        $data = array(
            'hash' => $this->hash($info),
            'agentId' => $info->AGENT_ID,
        );
        if ($this->option('date')) { // input timezone GMT+8 because it is date format
            $data['stmtdate'] = date('Ymd', strtotime($this->option('date')));
        } else {
            $data['startdate'] = $this->turnTime($start_time);
            $data['enddate'] = $this->turnTime($end_time);
        }

        $result = json_decode($this->vendor_send->send($this->action, $url, 'GET', $data), true);
        if (is_array($result) && $result['Status'] == 'success') {
            array_push($records, ...$result['Data']);
        }

        return $records;
    }

    private function addRecord($records, $rate)
    {

        $datas = array();
        foreach ($records as $list) {
            $data = array();
            $data['bet_id'] = $list['Id'];
            $data['username'] = $list['User'];
            $data['game_code'] = 'obet33';
            $sTime = $this->turnTime($list['SDate'], 1);
            $data['SDate'] = date('Y-m-d H:i:s', strtotime($sTime . ' +4hours'));
            $payTime = $this->turnTime($list['PDate'], 1);
            $data['bet_time'] = date('Y-m-d H:i:s', strtotime($payTime . ' -8hours'));
            $acceptedTime = $this->turnTime($list['ADate'], 1);
            $data['created_time'] = date('Y-m-d H:i:s', strtotime($acceptedTime . ' -8hours'));
            $data['bet_status'] = $list['Status'];
            $data['ticket_result'] = $list['Tresult'];
            $data['bet'] = ($list['Bamt'] / $rate);
            $data['win_lose'] = $list['Wamt'] == null ? 0 : ($list['Wamt'] / $rate);
            $data['info'] = json_encode($list);
            $datas[] = $data;
        }
        $records = $this->filterRecordsNotExists($datas);

        Obet33BetRecord::insert($records['createData']);
        foreach ($records['updateData'] as $data) {
            Obet33BetRecord::where('bet_id', $data['bet_id'])->update($data);
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
                ...Obet33BetRecord::whereIn($unique_field, $ids)
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

    /**
     * -取簽名
     */
    private function hash($info)
    {
        $str = "k=$info->API_KEY&a=$info->AGENT_ID";
        //加密要回傳大寫
        return strtoupper(md5($str));
    }

    /**
     * -轉換時間格式
     */
    private function turnTime($time, $type = 0)
    {
        if ($type == 0) {
            //轉換成打API需要的格式
            $find = array('-', ' ', ':');
            foreach ($find as $val) {
                $time = str_replace($val, "", $time);
            }
        } else if ($type == 1) {
            //轉換API回傳的格式
            str_replace('T', " ", $time);
        }

        return $time;
    }
}
