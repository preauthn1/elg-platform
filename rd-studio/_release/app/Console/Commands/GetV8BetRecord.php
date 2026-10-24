<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Helpers\Common;
use App\Helpers\VendorSend;
use App\Models\VendorInfo;
use App\Models\V8BetRecord;
use DB;

class GetV8BetRecord extends Command
{

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'v8_bet_record {--start=} {--end=}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '更新V8投注記錄';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    protected $vendor_send;
    protected $vendor_id = 42;
    protected $action = 'v8_get_bet_record';

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
            echo '時間範圍:' . $start_time . ' to ' . $end_time . PHP_EOL;

            $settings = VendorInfo::where('vendor_id', $this->vendor_id)
                ->where('status', 1)
                ->get();

            if (count($settings) > 0) {
                foreach ($settings as $setting) {
                    //將遊戲商資訊解密
                    $info = Common::decryptVendorInfo($setting);
                    if (is_object($info)) {
                        $records = array();
                        $utcStartTime = date('Y-m-d H:i:s', strtotime($start_time . '-8hours'));
                        $utcEndTime = date('Y-m-d H:i:s', strtotime($end_time . '-8hours'));
                        $sTime = $utcStartTime;
                        //API時間間隔不能超過60分鐘，如果超過會分次撈
                        do {
                            $nextLoop = false;
                            $eTime = date('Y-m-d H:i:s', strtotime($sTime . '+60minute'));
                            $record = $this->getRecord($info, $sTime, $eTime);
                            array_push($records, ...$record);
                            if ($eTime < $utcEndTime) {
                                $nextLoop = true;
                                $sTime = $eTime;
                            }
                        } while ($nextLoop);

                        if (count($records) > 0) {
                            $this->addRecord($records);
                        }
                    } else {
                        echo $setting->name . '解密失敗' . PHP_EOL;
                    }
                }
            }

            //total bet record
            echo '===== total bet record Start =====' . PHP_EOL;
            DB::select('call SP_transfer_bet_record_v8(null, null, null)');
            echo '===== total bet record End =====' . PHP_EOL;
        } catch (\Illuminate\Database\QueryException $e) {
            $info = $e->errorInfo;
            echo $info[0] . ' : ' . $info[2];
        } catch (\Exception $e) {
            echo $e->getCode() . ' : ' . $e->getMessage();
        }
    }

    private function getRecord($info, $sTime, $eTime)
    {
        $record = [];
        $url = $info->BET_RECORD_URL;
        $time = time() . '000';
        $data = array(
            's' => 6,
            'startTime' => strtotime($sTime) . '000',
            'endTime' => strtotime($eTime) . '000'
        );

        $message = http_build_query($data);
        $param = $this->getParam($info, $message);
        $postData = array(
            "agent" => $info->AGENT,
            "timestamp" => $time,
            "param" => $param,
            "key" => $this->getKey($info, $time)
        );
        //不使用http_build_query()是因為param內有些字會被換掉
        $getParam = $this->params($postData);
        $url .= '?' . $getParam;
        $response = $this->vendor_send->send($this->action, $url, 'GET');
        $result = json_decode($response, true);
        if (is_array($result) && $result['d']['code'] == 0) {
            //把api取到的record換成想要個格式
            $record = $this->sortRecord($result['d']['list']);
        }
        return $record;
    }

    private function sortRecord($data)
    {
        $newData = [];
        foreach ($data as $columnName => $column) {
            $count = 0;
            foreach ($column as $key => $val) {
                $newData[$count][$columnName] = $val;
                $count++;
            }
        }
        return $newData;
    }

    private function addRecord($gameLog)
    {
        $datas = array();
        foreach ($gameLog as $row) {
            $data = array();
            $data['bet_id'] = $row['GameID'];
            $data['game_code'] = $row['KindID'];
            $data['username'] = $row['Accounts'];
            $data['bet'] = $row['CellScore'];
            $data['win_lose'] = $row['Profit'];
            $data['bet_time'] = date('Y-m-d H:i:s', strtotime($row['GameEndTime'] . ' -8hour'));
            $data['info'] = json_encode($row);
            $datas[] = $data;
        }
        $create_records = $this->filterRecordsNotExists($datas);
        //直接一次insert
        V8BetRecord::insert($create_records);
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
                ...V8BetRecord::whereIn($unique_field, $ids)
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

    private function params($data)
    {
        $str = '';
        foreach ($data as $key => $val) {
            $str .= $key . '=' . $val;
            if ($val !== end($data)) {
                $str .= '&';
            }
        }
        return $str;
    }

    private function getParam($info, $message)
    {
        return urlencode(openssl_encrypt($message, 'aes-128-ecb', $info->DESKEY, 0));
    }

    private function getKey($info, $time)
    {
        return md5($info->AGENT . $time . $info->MD5KEY);
    }
}