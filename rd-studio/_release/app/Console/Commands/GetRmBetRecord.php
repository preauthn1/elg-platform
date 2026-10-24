<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Helpers\Common;
use App\Helpers\VendorSend;
use App\Models\VendorInfo;
use App\Models\RmBetRecord;
use DB;

class GetRmBetRecord extends Command
{

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'rm_bet_record {--start=} {--end=} {--vendor_info_id=}';
    //每次跑一個小時 php artisan rm_bet_record --start=2020-03-01@00 --start=2020-03-01@01 --vendor_info_id=92

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '更新Rm投注記錄';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    protected $vendor_send;
    protected $vendor_id = 32;
    protected $action = 'rm_get_bet_record';

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
                ->where('status', 1);
            // 撈注單時可以指定商戶
            if ($this->option('vendor_info_id')) {
                $settings = $settings->where('id', $this->option('vendor_info_id'));
            }
            $settings = $settings->get();
            $used = []; //已執行過的不再執行

            if (count($settings) > 0) {
                foreach ($settings as $setting) {
                    //將遊戲商資訊解密
                    $info = Common::decryptVendorInfo($setting);
                    $rate = isset($setting->rate) ? floatval($setting->rate) : 1;
                    $currency = isset($setting->currency) ? $setting->currency : 'MYR';
                    if (is_object($info) && !in_array($info->API_KEY, $used)) {
                        echo $setting->name . PHP_EOL;
                        $url = $info->API_URL . 'api/acc/getGameLog';;
                        $time = date('U');
                        $data = array();
                        $data['api_id'] = $info->API_ID;
                        $data['timestamp'] = $time;
                        $data['sign'] = $this->sign($info, $time);
                        $data['user_id'] = '';
                        $data['date_from'] = date("d/m/Y H:i:s", strtotime($start_time));
                        $data['date_to'] = date("d/m/Y H:i:s", strtotime($end_time));
                        $header = array('Content-Type:application/x-www-form-urlencoded');
                        $response = $this->vendor_send->send($this->action, $url, 'POST', $data, $header);
                        //取注單API有30秒的限制
                        if (is_null(json_decode($response))) {
                            $loop_time = 0;
                            if ($this->isTimeLimit($response)) {
                                do {
                                    $isLoop = false;
                                    $loop_time++;
                                    sleep(30);
                                    $response = $this->vendor_send->send($this->action, $url, 'POST', $data, $header);
                                    if ($loop_time <= 3 && $this->isTimeLimit($response)) {
                                        $isLoop = true;
                                    }
                                } while ($isLoop);
                            }
                        }
                        $result = json_decode($response, true);
                        if (is_array($result) && $result['code'] == '0') {
                            $this->addRecord($result['userGameLogDTO'], $currency, $rate, $setting);
                        } else {
                            echo 'no record' . PHP_EOL;
                        }
                        $used[] = $info->API_KEY;
                    } else {
                        echo $setting->name . '解密失敗' . PHP_EOL;
                    }
                }
            }

            //total bet record
            echo '===== total bet record Start =====' . PHP_EOL;
            DB::select('call SP_transfer_bet_record_rm(null, null, null)');
            echo '===== total bet record End =====' . PHP_EOL;
        } catch (\Illuminate\Database\QueryException $e) {
            $info = $e->errorInfo;
            echo $info[0] . ' : ' . $info[2];
        } catch (\Exception $e) {
            echo $e->getCode() . ' : ' . $e->getMessage();
        }
    }

    private function addRecord($gameLog, $currency, $rate, $vendor_info)
    {
        $datas = array();
        $i = 0;
        $d = array();
        foreach ($gameLog as $row) {
            if ($row['currencyCode'] == $currency) {
                $data = array();
                $data['vendor_info_id'] = $vendor_info->id;
                $data['bet_id'] = $row['logId'];
                $data['game_code'] = $row['gameCode'];
                $data['username'] = $row['loginId'];
                $data['bet'] = ($row['betAmount'] / $rate);
                $data['win_lose'] = ($row['totalWinLoseAmount'] / $rate);
                $date = str_replace('/', '-', $row['createdDateStr']);
                $date = date('Y-m-d H:i:s', strtotime($date));
                $data['bet_time'] = date('Y-m-d H:i:s', strtotime($date . ' -8hour'));
                $data['info'] = json_encode($row);
                $datas[] = $data;
            }
            /*
            $m = RmBetRecord::where('bet_id', $row['logId'])->first();
            if ($m) {
                echo $i++ . ' ';
                // echo '--------' . $m['bet_id'] . PHP_EOL;
                if ( abs($m['bet'] - ($row['betAmount'] / $rate)) > 1) {
                    echo '++++++++++' . $m['bet_id'] . PHP_EOL;
                    echo sprintf('record bet %d, log bet %d', $m['bet'], $row['betAmount'] / $rate) . PHP_EOL;

                }
            }
            */
        }
        $create_records = $this->filterRecordsNotExists($datas, $vendor_info->id);
        //批次塞入資料
        $collect = collect($create_records);
        $chunks = $collect->chunk(1000)->toArray(); // 分組，一組 $size  個
        foreach ($chunks as $chunk) {
            RmBetRecord::insert($chunk);
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
    private function filterRecordsNotExists($datas, $vendor_info_id, $size = 100)
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
                ...RmBetRecord::whereIn($unique_field, $ids)
                ->where('vendor_info_id', $vendor_info_id)
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

    private function sign($info, $time)
    {
        return md5(($info->API_ID . $time . $info->API_KEY));
    }

    //判斷是不是30秒限制
    private function isTimeLimit($response)
    {
        //先用檢查字串的方式
        return strpos($response, 'HTTP Status 429 – Too Many Requests');
    }
}