<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Helpers\Common;
use App\Helpers\VendorSend;
use App\Models\VendorInfo;
use App\Models\MgcBetRecord;
use DB;

class GetMgcBetRecord extends Command
{

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'mgc_bet_record {--start=} {--end=}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '更新MGC投注記錄';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    protected $vendor_send;
    protected $vendor_id = 51;
    protected $action = 'mgc_get_bet_record';

    public function __construct()
    {
        parent::__construct();
        $this->vendor_send = new VendorSend(['json_post' => 'array']);
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

            $settings = VendorInfo::where('vendor_id', $this->vendor_id)
                ->where('status', 1)
                ->get();
            $used = []; //已執行過的不再執行
            if (count($settings) > 0) {
                foreach ($settings as $setting) {
                    //將遊戲商資訊解密
                    $info = Common::decryptVendorInfo($setting);
                    if (is_object($info) && !in_array($info->API_KEY, $used)) {
                        $records = $this->getBetRecord($info, $start_time, $end_time);
                        if (count($records) > 0) {
                            $this->addRecord($records);
                        }
                        $used[] = $info->API_KEY;
                    } else {
                        echo $setting->name . '解密失敗' . PHP_EOL;
                    }
                }
            }

            //total bet record
            echo '===== total bet record Start =====' . PHP_EOL;
            DB::select('call SP_transfer_bet_record_mgc(null, null, null)');
            echo '===== total bet record End =====' . PHP_EOL;
        } catch (\Illuminate\Database\QueryException $e) {
            $info = $e->errorInfo;
            echo $info[0] . ' : ' . $info[2];
        } catch (\Exception $e) {
            echo $e->getCode() . ' : ' . $e->getMessage();
        }
    }

    //獲取目標時間段前一小時到後一小時
    private function getBetRecord($info, $start_time, $end_time)
    {
        $records = array();
        $page = 1;

        do {
            $nextLoop = false;
            $result = $this->getBetRecordByPage($info, $start_time, $end_time, $page);
            if (is_array($result) && $result['code'] == 0) {
                array_push($records, ...$result['data']);
                if (count($result['data']) >= 100) {
                    $nextLoop = true;
                    $page++;
                }
            }
        } while ($nextLoop);

        return $records;
    }

    private function getBetRecordByPage($info, $start_time, $end_time, $page = 1)
    {
        $url = $info->API_URL . '/api/getGameLog';

        $sTime = strtotime($start_time . '-8hour');
        $eTime = strtotime($end_time . '-8hour');

        $data = array(
            'startTime' => $sTime,
            'endTime' => $eTime,
            'page' => $page
        );

        ksort($data);

        $postData = array(
            'mchid' => $info->Merchant_ID,
            'sign' => $this->sign($info, $data),
            'params' => json_encode($data)
        );

        $response = $this->vendor_send->send($this->action, $url, 'POST', $postData);
        $result = json_decode($response, true);

        return $result;
    }

    private function addRecord($records)
    {
        $datas = array();
        foreach ($records as $row) {
            $data = array();
            $data['bet_id'] = $row['id'];
            $data['game_code'] = $row['gameType'];
            $data['username'] = $row['playerId'];
            $data['bet'] = $row['bet'];
            $data['win_lose'] = $row['total_winLose'];
            //API回傳的是13位時間戳
            $data['bet_time'] = date('Y-m-d H:i:s', strtotime($row['save_time'] . '-8hour'));
            $data['info'] = json_encode($row);
            $datas[] = $data;

        }
        $create_records = $this->filterRecordsNotExists($datas);
        //批次塞入資料
        $collect = collect($create_records);
        $chunks = $collect->chunk(1000)->toArray(); // 分組，一組 $size  個
        foreach ($chunks as $chunk) {
            MgcBetRecord::insert($chunk);
        }
        echo 'success: ';
        printf("*recordCount [%d] writeCount [%d] ", count($datas), count($create_records));
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
                ...MgcBetRecord::whereIn($unique_field, $ids)
                ->select($unique_field)
                ->get()
                ->pluck($unique_field)
                ->toArray()
            );
        }

        // 過濾掉己經存在的押注記錄
        return $collect->filter(function ($item) use ($unique_field, $exists_ids) {
            return !in_array($item[$unique_field], $exists_ids);
        })
            ->all();
    }

    private function sign($info, $params)
    {
        $paramStr = '';

        foreach ($params as $key => $value) {
            $paramStr .= $key . '=' . $value;
            if ($value != end($params)) {
                $paramStr .= '&';
            }
        }

        $message = $info->Merchant_ID . $paramStr . $info->API_KEY;
        //加密要回傳大寫
        return strtoupper(md5($message));
    }
}