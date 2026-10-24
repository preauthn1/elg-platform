<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Helpers\Common;
use App\Helpers\VendorSend;
use App\Models\VendorInfo;
use App\Models\Sky1388BetRecord;
use DB;

class GetSky1388BetRecord extends Command
{

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'sky1388_bet_record {--start=}'; //每次跑一個小時 php artisan sky1388_bet_record --start=2020-03-01@00

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '更新Sky1388投注紀錄';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    protected $vendorsend;
    protected $vendor_id = 28;
    protected $action = 'sky1388_get_bet_record';

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
            $start = $this->option('start')
                ? date('Y-m-d H:i:00', strtotime(str_replace('@', ' ', $this->option('start')) . ':00:00'))
                : date('Y-m-d H:i:00', strtotime('+8hours -30 minutes'));
            Common::log(0, 'start:' . $start, $this->action);

            echo $this->description . PHP_EOL;
            echo 'start ' . $start . PHP_EOL;

            $settings = VendorInfo::where('vendor_id', $this->vendor_id)
                ->where('status', 1)
                ->get();
            $used = []; //已執行過的不再執行

            if (count($settings) > 0) {
                foreach ($settings as $setting) {
                    $vendor_info_id = $setting->id;
                    //帳號前綴
                    $prefix = $setting->account_prefix;

                    $info = json_decode($setting->info);
                    if (is_object($info)  && !in_array($info->API_KEY, $used)) {
                        //API限制; 時間範圍只十分鐘, 不管有沒有username
                        for ($i = 0; $i < 60; $i += 10) {
                            $start_time = date('Y-m-d H:i:00', strtotime($start . '+' . $i . ' minutes'));
                            $end_time = date('Y-m-d H:i:s', strtotime($start . '+' . ($i + 10) . ' minutes'));

                            echo 'Date range:' . $start_time . ' ~ ' . $end_time . PHP_EOL;
                            $data = array();
                            $data['agid'] = $info->ID;
                            //$data['username'] = '';
                            $data['start_date'] = $start_time;
                            $data['end_date'] = $end_time;

                            $sign = $this->sign($data, $info);
                            $data['signature'] = $sign;
                            $data['page'] = 0;

                            $this->addRecord($info, $data);
                        }
                        $used[] = $info->API_KEY;
                    } else {
                        echo $setting->name . '解密失敗' . PHP_EOL;
                    }
                }
            }

            //total bet record
            echo '===== total bet record Start =====' . PHP_EOL;
            DB::select('call SP_transfer_bet_record_sky1388(null, null, null)');
            echo '===== total bet record End =====' . PHP_EOL;
        } catch (\Illuminate\Database\QueryException $e) {
            $info = $e->errorInfo;
            echo $info[0] . ' : ' . $info[2];
        } catch (\Exception $e) {
            echo $e->getCode() . ' : ' . $e->getMessage();
        }
    }

    private function addRecord($info, $postData)
    {
        echo '  addRecord page: ' . $postData['page'] . PHP_EOL;
        $url = $info->API_URL . '/user_game_history';
        $response = $this->vendorsend->send($this->action, $url, 'get', $postData);
        $result = json_decode($response, true);

        if (is_array($result) && strtolower($result['status']) == 'ok') {
            $total_page = isset($result['total_page']) ? $result['total_page'] : 0;
            $error_code = isset($result['error_code']) ? strtolower($result['error_code']) : '';

            if ($error_code == 'empty') {
                echo '  not data' . PHP_EOL;
            } else {
                krsort($result['list']);

                $datas = array();
                foreach($result['list'] as $list) {
                    $data = array();
                    $data['bet_id'] = $list['matchid'];
                    $data['username'] = $list['username'];
                    $data['game_code'] = $list['game_code'];
                    $data['bet_time'] = date('Y-m-d H:i:s', strtotime($list['bet_time'] . ' -8hours'));
                    $data['created_time'] = date('Y-m-d H:i:s', strtotime($list['created_time'] . ' -8hours'));
                    $data['bet'] = $list['bet'];
                    $data['win'] = $list['win'];
                    $data['refund'] = $list['refund'];
                    $data['win_lose'] = $list['winlose'];
                    $data['jackpot'] = $list['jackpot'];
                    $data['info'] = json_encode($list);
                    $datas[] = $data;
                }
                $create_records = $this->filterRecordsNotExists($datas);
                foreach($create_records as $data) {
                    Sky1388BetRecord::create($data);
                    echo '*****' . $data['bet_id'] . '*****' . PHP_EOL;
                }
                printf("*recordCount [%d] writeCount [%d] ", count($datas), count($create_records));
                echo 'success' . PHP_EOL;

                if ($total_page > ($postData['page'] + 1)) {
                    $postData['page']++;
                    $this->addRecord($info, $postData);
                }
            }
        } else {
            echo '  fail:' . $result['error_code'] . PHP_EOL;
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
                ...Sky1388BetRecord::whereIn($unique_field, $ids)
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

    /**
     * -取簽名
     */
    private function sign($data, $info)
    {
        ksort($data);
        $str = implode('', $data) . $info->PRIVATE_KEY;
        $sign = sha1($str);
        return $sign;
    }
}
