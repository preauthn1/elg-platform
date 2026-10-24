<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Helpers\Common;
use App\Helpers\VendorSend;
use App\Models\VendorInfo;
use App\Models\Play8oyBetRecord;
use DB;

class GetPlay8oyBetRecord extends Command
{

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'play8oy_bet_record {--start=} {--end=}';
    // php artisan play8oy_bet_record --start=2021-08-19@23 --end=2021-08-20@01

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '更新Play8oy投注記錄';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    protected $vendor_send;
	protected $vendor_id = 30;
	protected $action = 'play8oy_get_bet_record';

    public function __construct()
    {
        parent::__construct();
        $this->vendor_send = new VendorSend(['json_post'=>true]);
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
            $url = 'game/log/record';

            $settings = VendorInfo::where('vendor_id', $this->vendor_id)
                ->where('status', 1)
                ->get();
            $used = []; //已執行過的不再執行

            if (count($settings) > 0) {
                foreach ($settings as $setting) {
                    //將遊戲商資訊解密
                    $info = Common::decryptVendorInfo($setting);
                    if (is_object($info) && !in_array($info->API_KEY, $used)) {
                        $url = $info->API_URL . $url;

                        //每十分鐘獲取一次
                        $increase = "+10 Minutes";
                        $run_start_time = $start_time;
                        $today_last = false;  //為今天最後一筆
                        while (strtotime($run_start_time) < strtotime($end_time)) { //$run_start_time 小於結束時間則繼續
                            $run_end_time = date("Y-m-d H:i:s", strtotime($run_start_time . " " . $increase));
                            if ($today_last == true) {
                                $run_start_time = date("Y-m-d H:i:s", strtotime($run_start_time . " +1 Seconds"));
                                $run_end_time = date("Y-m-d H:i:s", strtotime($run_start_time . " " . $increase));
                                $today_last = false;
                            } elseif (date('Ymd', strtotime($run_start_time)) != date('Ymd', strtotime($run_end_time))) {
                                $run_end_time = date('Y-m-d', strtotime($run_start_time)) . ' 23:59:59';
                                $today_last = true;
                            }

                            //如果運行結束時間大於結束時間, 就直接用結束時間
                            if (strtotime($run_end_time) > strtotime($end_time)) {
                                $run_end_time = $end_time;
                            }
                            $this->addRecord($run_start_time, $run_end_time, $info, $url);
                            $run_start_time = $run_end_time;
                        }
                        $used[] = $info->API_KEY;
                    } else {
                        echo $setting->name . '解密失敗' . PHP_EOL;
                    }
                }
            }

            //total bet record
            echo '===== total bet record Start =====' . PHP_EOL;
            DB::select('call SP_transfer_bet_record_play8oy(null, null, null)');
            echo '===== total bet record End =====' . PHP_EOL;
        } catch (\Illuminate\Database\QueryException $e) {
            $info = $e->errorInfo;
            echo $info[0] . ' : ' . $info[2];
        } catch (\Exception $e) {
            echo $e->getCode() . ' : ' . $e->getMessage();
        }
    }

    private function addRecord($start_time, $end_time, $info, $url, $page = 1)
    {
        $data = array();
        $data['StartDate'] = $start_time;
        $data['EndDate'] = $end_time;
        $data['PageNumber'] = $page;
        ksort($data);

        $header = array(
            'merchantName:' . $info->API_MERCHANT_NAME,
            'Sign:' . $this->sign($data, $info->API_KEY),
            'Content-Type:application/json'
        );
        $request = $this->vendor_send->send($this->action, $url, 'POST', $data, $header);
        $result = json_decode($request, true);
        if (is_array($result) && $result['Code'] == '0') {
            $total_page = $result['Data']['Page']['TotalPage'];

            $datas = array();
            foreach ($result['Data']['Record'] as $row) {
                $data = array();
                $data['bet_id'] = $row['GameSerialID'];
                $data['game_code'] = substr($row['ThemeID'], 0, 4);
                $data['username'] = $row['UserName'];
                $data['bet'] = $row['BetCoin'];
                $data['valid_bet'] = $row['AvailTotalBet'];
                $data['win'] = $row['WinAmount'];
                $data['win_lose'] = ($row['WinAmount'] - $row['BetCoin']);
                $data['bet_time'] = date('Y-m-d H:i:s', strtotime($row['CreateTime'] . ' -8hour'));
                $data['info'] = json_encode($row);
                $datas[] = $data;
            }

            $create_records = $this->filterRecordsNotExists($datas);
            //直接一次insert
            Play8oyBetRecord::insert($create_records);
            printf("獲取時間段:$start_time ~ $end_time ($page/$total_page), recordCount [%d] writeCount [%d] ", count($datas), count($create_records));
            echo PHP_EOL;

            //當還有其他頁數時, 取得下一頁資訊
            if ($total_page > $page) {
                $this->addRecord($start_time, $end_time, $info, $url, ++$page);
            }
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
    private function filterRecordsNotExists($datas, $size = 100) {
        $exists_ids = array();
        $collect = collect($datas);
        $chunks = $collect->chunk($size); // 分組，一組 $size  個
        $unique_field = 'bet_id';
        // 分組取出己經存在的押注記錄
        foreach ($chunks as $chunk) {
            $ids = $chunk->pluck($unique_field)->toArray();
            array_push(
                $exists_ids,
                ...Play8oyBetRecord::whereIn($unique_field, $ids)
                ->select($unique_field)
                ->get()
                ->pluck($unique_field)
                ->toArray()
            );
        }

        // 過濾掉己經存在的押注記錄
        return $collect->filter(function($item) use($unique_field, $exists_ids){
            return !in_array($item[$unique_field], $exists_ids);
        })
            ->all();
    }

    private function sign($data, $apiKey)
    {
        $string = '';
        if (count($data) > 0) {
            foreach ($data as $key => $value) {
                $string .= $key . '=' . $value . '&';
            }
        }
        $string .= 'PrivateKey=' . $apiKey;
        $hash_hmac = hash('sha256', $string, true);
        return base64_encode($hash_hmac);
    }
}