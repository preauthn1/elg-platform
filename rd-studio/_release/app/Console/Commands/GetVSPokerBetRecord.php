<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Helpers\Common;
use App\Helpers\VendorSend;
use App\Models\VendorInfo;
use App\Models\VspokerBetRecord;
use DB;

class GetVSPokerBetRecord extends Command
{

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'vspoker_bet_record {--start=}{--end=}'; //每次跑一個小時 php artisan sky1388_bet_record --start=2020-03-01@00

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '更新VSPoker投注紀錄';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    protected $vendor_send;
    protected $vendor_id = 36;
    protected $action = 'vspoker_get_bet_record';

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
                    $info = json_decode($setting->info);
                    if (is_object($info) && !in_array($info->SECRET_KEY, $used)) {
                        $function = 'GetPlayerBetLogDateTime';
                        $data = array();
                        $data['fromDate'] = $start_time;
                        $data['toDate'] = $end_time;
                        $data['reqDateTime'] = date('Y-m-d H:i:s');
                        $data['opCode'] = $info->OPCODE;
                        $str = $data['fromDate'] . $data['toDate'] . $function . $data['reqDateTime'];
                        $securityToken = [REDACTED], $str);
                        $data['securityToken'] = $securityToken;

                        $this->addRecord($info, $data);
                        $used[] = $info->SECRET_KEY;
                    }
                }
            }

            //total bet record
            echo '===== total bet record Start =====' . PHP_EOL;
            DB::select('call SP_transfer_bet_record_vspoker(null, null, null)');
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
        $url = $info->LOG_URL . '/getPlayerBetLogDateTime.shtml';
        $header = array('Content-Type: application/x-www-form-urlencoded');
        $response = json_decode($this->vendor_send->send($this->action, $url, 'POST', $postData, $header), true);
        if (is_array($response) && $response != -1) {
            $datas = array();
            if (count($response) > 0) {
                foreach ($response as $list) {
                    $data = array();
                    $data['bet_id'] = $list['id'];
                    $data['username'] = $list['username'];
                    $data['game_code'] = $list['gameid'];
                    $data['bet_time'] = date('Y-m-d H:i:s', strtotime($list['datetime'] . ' -8hours'));
                    //API注單回傳的是分數不是遊戲幣，所以要除以100
                    $data['bet'] = ($list['validturnover'] / 100);
                    $data['win_lose'] = ($list['winlose'] / 100);
                    $data['info'] = json_encode($list);
                    $datas[] = $data;
                }
            }
            $records = $this->filterRecordsNotExists($datas);
            VspokerBetRecord::insert($records);

            printf("*recordCount [%d] writeCount [%d] ", count($datas), count($records));
            echo PHP_EOL . 'success' . PHP_EOL;
        } else {
            echo '  fail:' . PHP_EOL;
        }
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
                ...VspokerBetRecord::whereIn($unique_field, $ids)
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

        return $createData;
    }

    /**
     * -取簽名
     */
    private function sign($info, $str)
    {
        $md5Str = $str . $info->OPCODE . $info->SECRET_KEY;
        return md5($md5Str);
    }
}
