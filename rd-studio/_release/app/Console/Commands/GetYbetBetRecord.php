<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Helpers\Common;
use App\Helpers\VendorSend;
use App\Models\YbetBetRecord;
use App\Models\VendorInfo;
use DB;

class GetYbetBetRecord extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'ybet_bet_record';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Yeebet 投注記錄';
    protected $vendor_id = 52;
    protected $action = 'ybet_get_bet_record';

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
            echo $this->description . PHP_EOL;
            // yeebet 注單, 不需時間段要標記己抓取
            $settings = VendorInfo::where('vendor_id', $this->vendor_id)
                ->where('status', 1)
                ->get();
            $used = []; //已執行過的不再執行
            if (count($settings) > 0) {
                foreach ($settings as $setting) {
                    //將遊戲商資訊解密
                    $info = Common::decryptVendorInfo($setting);
                    if (is_object($info) && !in_array($info->API_KEY, $used)) {
                        $records = $this->getBetRecord($info);
                        if (is_array($records) && count($records) > 0) {
                            $this->addRecord($info, $records);
                        } else {
                            echo 'no records';
                        }
                        $used[] = $info->API_KEY;
                    } else {
                        echo $setting->name . '解密失敗' . PHP_EOL;
                    }
                }
            }

            //total bet record
            echo '===== total bet record Start =====' . PHP_EOL;
            DB::select('call SP_transfer_bet_record_ybet(null, null, null)');
            echo '===== total bet record End =====' . PHP_EOL;
        } catch (\Illuminate\Database\QueryException $e) {
            $info = $e->errorInfo;
            echo $info[0] . ' : ' . $info[2];
        } catch (\Exception $e) {
            echo $e->getCode() . ' : ' . $e->getMessage();
        }
    }

    private function getBetRecord($info)
    {
        $records = array();
        $url = $info->API_URL . '/data/getbets';

        $data = array();
        $data['appid'] = $info->APP_ID;
        $data['size'] = 2000;
        $data['sign'] = $this->sign($info, $data);

        $url .= '?' . $this->getParam($data);

        $vendorSend = new VendorSend();
        $response = $vendorSend->send($this->action, $url, 'POST', $data);
        $result = json_decode($response, true);
        if (is_array($result) && $result['result'] == 0) {
            $records = $result['array'];
        }

        return $records;
    }

    private function addRecord($info, $records)
    {
        $write_record = $update_record = 0;
        $ids = array();
        foreach ($records as $row) {
            $data = array();
            $data['bet_id'] = $row['id'];
            $data['game_code'] = $row['gameid'];
            $data['username'] = $row['username'];
            $data['bet'] = $row['betamount'];
            $data['win_lose'] = $row['winlost'];
            //API回傳的是13位時間戳
            $data['bet_time'] = date('Y-m-d H:i:s', $row['createtime']);
            $data['settle_time'] = date('Y-m-d H:i:s', $row['settletime']);
            $data['info'] = json_encode($row);

            //因為他們bet_id會重複,所以先採用update的方式取代掉舊的注單
            $record = YbetBetRecord::where('bet_id', $data['bet_id'])->first();
            if (!is_object($record)) {
                YbetBetRecord::create($data);
                echo '*****' . $data['bet_id'] . '*****';
                $write_record++;
            } else {
                $record->update($data);
                echo '!!!!!!!' . $data['bet_id'] . '!!!!!!!';
                $update_record++;
            }
            $ids[] = $row['id'];
        }
//        $create_records = $this->filterRecordsNotExists($datas);
//        //批次塞入資料
//        $collect = collect($create_records);
//        $chunks = $collect->chunk(1000)->toArray(); // 分組，一組 $size  個
//        foreach ($chunks as $chunk) {
//            YBetBetRecord::insert($chunk);
//        }
        $this->removeBetRecordsBatch($info, $ids);
        echo PHP_EOL;
        echo 'success: ';
        printf("*writeCount [%d] updateCount [%d]",  $write_record, $update_record);
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
                ...YbetBetRecord::whereIn($unique_field, $ids)
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

    private function removeBetRecordsBatch($info, $record_ids)
    {
        $collect = collect($record_ids);
        $chunks = $collect->chunk(500)->toArray(); // 分組，一組 $size  個
        foreach ($chunks as $chunk) {
            $this->removeBetRecords($info, $chunk);
        }
    }

    private function removeBetRecords($info, $record_ids)
    {
        $url = $info->API_URL . '/data/removebets';
        $ids = '';
        foreach ($record_ids as $val) {
            $ids .= $val;
            if ($val != end($record_ids)) {
                $ids .= ',';
            }
        }
        $data = array();
        $data['appid'] = $info->APP_ID;
        $data['ids'] = $ids;
        $data['sign'] = $this->sign($info, $data);

        $url .= '?' . $this->getParam($data);

        $vendorSend = new VendorSend();
        $response = $vendorSend->send($this->action, $url, 'POST', []);
        return json_decode($response, true);
    }

    private function sign($info, $param)
    {
        ksort($param);
        $msg = $this->getParam($param);
        $msg .= '&key=' . $info->API_KEY;
        return strtolower(md5($msg));
    }

    private function getParam($param)
    {
        $msg = '';
        foreach ($param as $key => $val) {
            $msg .= $key . '=' . $val;
            if ($val != end($param)) {
                $msg .= '&';
            }
        }
        return $msg;
    }
}
