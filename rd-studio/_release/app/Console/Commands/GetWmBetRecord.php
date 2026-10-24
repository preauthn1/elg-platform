<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Helpers\VendorSend;
use App\Helpers\Common;
use App\Helpers\VendorPost;
use App\Models\VendorInfo;
use App\Models\WmBetRecord;
use DB;

class GetWmBetRecord extends Command
{

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'wm_bet_record {--hour=}'; //wm_bet_record --hour=2020-03-18@00

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '更新Wm投注紀錄(每次撈一個小時)';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    protected $vendorsend;
    public function __construct()
    {
        parent::__construct();
        $this->vendorsend = new VendorSend();
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        try {
            $date_time = $this->option('hour')
                ? str_replace('@', ' ', $this->option('hour')) . ':00:00'
                : date('Y-m-d H:i:00', strtotime('-5hour'));
            echo $date_time . PHP_EOL;
            //切割時間
            list($date, $time) = explode(' ', $date_time);
            list($hour, $minute, $second) = explode(':', $time);

            $url = 'platform/feed/export/JSON/';
            $data = array();
            $data['date'] =  str_replace('-', '', $date);
            $data['hour'] =  $hour;
            $data['minute'] = $minute . '?interval=60'; //每次撈一個小時
            //取SETTING
            $settings = VendorInfo::where('vendor_id', 16)->where('status', 1)->get();
            $used = []; //已執行過的不再執行
            foreach ($settings as $row) {
                //將遊戲商資訊解密
                $info = Common::decryptVendorInfo($row);
                if (is_object($info) && !in_array($info->LICENSEE_TOKEN, $used)) {
                    echo '======' . $row->name . '======' . PHP_EOL;
                    $post_url = $info->SERVER_NAME . $url . $info->LICENSEE_TOKEN;
                    foreach ($data as $row) {
                        $post_url .= '/' . $row;
                    }
                    $result = json_decode($this->vendorsend->send('wm_get_bet_record', $post_url, 'GET', [], ['Content-Type:application/json']), true);
//                    $result = VendorPost::WmPost($info, $url, $data, false);
                    if (is_array($result) && isset($result[0]['A'])) {
                        $datas = array();
                        foreach ($result as $row) {
                            $data = array();
                            $data['round_id'] = $row['A'];
                            $data['game_identity'] = $row['B'];
                            $data['currency'] = $row['C'];
                            $data['wallet_type'] = $row['D'];
                            $data['external_reference'] = $row['E'];
                            $data['external_user_id'] = $row['F'];
                            $data['user_token'] = $row['G'];
                            $data['session_token'] = $row['H'];
                            $data['start_time'] = $row['I'];
                            $data['end_time'] = $row['J'];
                            $data['initial_balance'] = $row['K'];
                            $data['finial_balance'] = $row['L'];
                            $data['bet_amount'] = $row['M'];
                            $data['win_amount'] = $row['N'];
                            $data['jack_pot_bet'] = $row['O'];
                            $data['jack_pot_win'] = $row['P'];
                            $start = $row['I'];
                            $end = $row['J'];
                            $data['utc_start_time'] = date("Y-m-d H:i:s", strtotime("$start +4 hour"));
                            $data['utc_end_time'] = date("Y-m-d H:i:s", strtotime("$end +4 hour"));
                            $datas[] = $data;
//                            $records = WmBetRecord::where('round_id', $row['A'])->first();
//                            if (!is_object($records)) {
//                                WmBetRecord::create($data);
//                                echo '*****' . $row['F'] . '*****' . PHP_EOL;
//                            }
                        }

                        $create_records = $this->filterRecordsNotExists($datas);
                        foreach($create_records as $data) {
                            WmBetRecord::create($data);
                            echo '*****' . $data['round_id'] . '*****' . PHP_EOL;
                        }
                        printf("*recordCount [%d] writeCount [%d] ", count($datas), count($create_records));
                        echo 'success' . PHP_EOL;
                    } else if (is_array($result) && !isset($result['Message'])) {
                        echo 'no data' . PHP_EOL;
                    } else {
                        echo 'fail' . PHP_EOL;
                    }
                    $used[] = $info->LICENSEE_TOKEN;
                }
            }

            //total bet record
            echo '===== total bet record Start =====' . PHP_EOL;
            DB::select('call SP_transfer_bet_record_wm(null, null, null)');
            echo '===== total bet record End =====' . PHP_EOL;
        } catch (\Illuminate\Database\QueryException $e) {
            $info = $e->errorInfo;
            echo $info[0] . ' : ' . $info[2];
        } catch (\Exception $e) {
            echo $e->getCode() . ' : ' . $e->getMessage();
        }
    }

    /**
     * 過濾掉己經存在的押注記錄
     * @param $datas
     * @param int $size
     * @return array
     */
    private function filterRecordsNotExists($datas, $size=100) {
        $exists_ids = array();
        $collect = collect($datas);
        $chunks = $collect->chunk($size); // 分組，一組 $size  個
        $unique_field = "round_id";
        // 分組取出己經存在的押注記錄
        foreach ($chunks as $chunk) {
            $ids = $chunk->pluck($unique_field)->toArray();
            array_push(
                $exists_ids,
                ...WmBetRecord::whereIn($unique_field, $ids)
                    ->select($unique_field)
                    ->get()
                    ->pluck($unique_field)
                    ->toArray()
            );
        }
        // 過濾掉己經存在的押注記錄
        return $collect->filter(function($item) use($unique_field, $exists_ids){
            return !in_array($item[$unique_field], $exists_ids);
        })->all();
    }
}