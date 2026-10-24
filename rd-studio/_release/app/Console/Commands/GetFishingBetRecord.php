<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Helpers\VendorSend;
use App\Helpers\Common;
use App\Models\VendorInfo;
use App\Models\FishingBetRecord;
use DB;
use SebastianBergmann\CodeCoverage\Report\PHP;

class GetFishingBetRecord extends Command
{

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'fishing_bet_record {--start=}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '更新Fishing投注記錄';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    protected $vendor_id = 39;
    protected $action = 'fishing_get_bet_record';
    protected $vendor_send;

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
            // 一次只能跑半小時, +8時區
            if ($this->option('start')) {
                $start = str_replace('@', ' ', $this->option('start')) . ':00';
            }
            $start_time = $this->option('start')
                ? $start
                : date('Y-m-d H:i:s', strtotime('+7hour +30minute'));
            $end_time = $this->option('start')
                ? date('Y-m-d H:i:s', strtotime($start . ' +30minute'))
                : date('Y-m-d H:i:s', strtotime('+8hour'));

            echo $start_time . ' to ' . $end_time . PHP_EOL;

            $settings = VendorInfo::where('vendor_id', $this->vendor_id)
                ->where('status', 1)
                ->get();

            if (count($settings) > 0) {
                foreach ($settings as $setting) {
                    //將遊戲商資訊解密
                    $info = Common::decryptVendorInfo($setting);
                    if (is_object($info)) {
                        $records = $this->getBetRecord($info, $start_time, $end_time);
                        if (is_array($records) && count($records) > 0) {
                            $this->addRecord($records);
                        }
                    } else {
                        echo $setting->name . '解密失敗' . PHP_EOL;
                    }
                }
                //total bet record
                echo '===== total bet record Start =====' . PHP_EOL;
                DB::select('call SP_transfer_bet_record_fishing(null, null, null)');
                echo '===== total bet record End =====' . PHP_EOL;
            }
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
        $url = $info->BET_RECORD_URL . '/fishdata/userbetflow_pgs';
        //時間要轉換成utc+0的時間戳
        $sTime = strtotime($start_time . '-8hours');
        $eTime = strtotime($end_time . '-8hours');

        $data = array();
        $data['source'] = $info->SOURCE;
        $data['start_timestamp'] = $sTime;
        $data['end_timestamp'] = $eTime;
        $data['sign'] = $this->sign($info, $sTime, $eTime);

        $response = json_decode($this->vendor_send->send($this->action, $url, 'GET', $data), true);
        if (is_array($response) && $response['code'] == 0) {
            $records = $response['data'];
        }

        return $records;
    }


    private function addRecord($records)
    {
        $datas = array();
        foreach ($records as $row) {
            $data = array();
            $data['bet_id'] = $row['roundid'];
            $data['game_code'] = 'pgs_fishing';
            $data['username'] = $row['uid'];
            $data['bet'] = $row['bet_total'];
            $data['win_lose'] = $row['profit_total'];
            $data['bet_time'] = date("Y-m-d H:i:s", strtotime($row['roundend'] . "-8hours"));
            $data['info'] = json_encode($row);
            $datas[] = $data;
        }
        $create_records = $this->filterRecordsNotExists($datas);
        //直接一次insert
        FishingBetRecord::insert($create_records);
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
                ...FishingBetRecord::whereIn($unique_field, $ids)
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

    private function sign($info, $start_time, $end_time)
    {
        $str = $start_time . $end_time . $info->KEY;
        return md5($str);
    }
}