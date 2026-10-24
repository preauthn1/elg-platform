<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Helpers\Common;
use App\Helpers\VendorSend;
use App\Models\VendorInfo;
use App\Models\SkybetBetRecord;
use DB;

class GetSkybetDailyWin extends Command
{

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'skybet_daily_win {--start=}{--end=}'; //每次跑一個小時 php artisan sky1388_bet_record --start=2020-03-01@00

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '更新Skybet輸贏紀錄';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    protected $vendorsend;
    protected $vendor_id = 31;
    protected $action = 'skybet_get_daily_win';

    public function __construct()
    {
        parent::__construct();
        $this->vendor_send = new VendorSend(['json_post' => true]);
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
            $used = []; //已執行過的不再執行

            if (count($settings) > 0) {
                foreach ($settings as $setting) {
                    $info = json_decode($setting->info);
                    if (is_object($info) && !in_array($info->DES_KEY, $used)) {
                        $data = array();
                        $data['Stime'] = $start_time;
                        $data['Etime'] = $end_time;
                        $data['Timestamp'] = date('U') . '000';
                        $data = $this->des_encrypt($data, $info->DES_KEY);
                        $postData = array();
                        $postData['WJAgent'] = $info->AGENT;
                        $postData['Data'] = $data;

                        $this->addRecord($info, $postData);
                        $used[] = $info->DES_KEY;
                    }
                }
            }

            //total bet record
            echo '===== total bet record Start =====' . PHP_EOL;
            DB::select('call SP_transfer_bet_record_skybet(null, null, null)');
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
        $url = $info->API_URL . '/WJMemFundLog';
        $headers = array('Content-Type: application/json');
        $result = json_decode($this->vendor_send->send($this->action, $url, 'POST', $postData, $headers), true);
        if (is_array($result) && $result['IsSuccess'] == true) {
            $datas = array();
            foreach ($result['data'] as $list) {
                $gameCode = '';
                switch ($list['FlowType']) {
                    case 2 :
                    case 3 :
                    case 13 :
                    case 20 :
                        $gameCode = 'Rank';
                        break;
                    case 4 :
                    case 5 :
                    case 6 :
                    case 9 :
                    case 10 :
                    case 14 :
                        $gameCode = 'Club';
                        break;
                    case 8:
                    case 19:
                        $gameCode = 'Challenge';
                        break;
                    case 12 :
                        $gameCode = 'Lottery';
                        break;
                    default:
                        continue 2;
                        break;
                }
                $data = array();
                $data['bet_id'] = $list['Id'];
                $data['username'] = $list['UName'];
                $data['game_code'] = $gameCode;
                $data['bet_time'] = date('Y-m-d H:i:s', strtotime($list['CreateTime'] . ' -8hours'));
                $data['created_time'] = date('Y-m-d H:i:s', strtotime($list['CreateTime'] . ' -8hours'));
                $data['bet_status'] = '2';
                $data['settlement_status'] = '2';
                if ($list['Amount'] <= 0) {
                    $data['bet'] = abs($list['Amount']);
                    $data['valid_bet'] = abs($list['Amount']);
                    $data['win'] = 0;
                    $data['win_lose'] = $list['Amount'];
                } else {
                    $data['bet'] = 0;
                    $data['valid_bet'] = 0;
                    $data['win'] = $list['Amount'];
                    $data['win_lose'] = $list['Amount'];
                }
                $data['info'] = json_encode($list);
                $datas[] = $data;
            }
            $records = $this->filterRecordsNotExists($datas);
            SkybetBetRecord::insert($records);
            echo '*****' . $data['bet_id'] . '*****' . PHP_EOL;
            printf("*recordCount [%d] writeCount [%d]", count($datas), count($records));
            echo PHP_EOL . 'success' . PHP_EOL;
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
                ...SkybetBetRecord::whereIn($unique_field, $ids)
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

    /**
     * -取簽名
     */
    private function des_encrypt($data, $key)
    {
        $key = md5($key);
        $key = substr($key, 0, 8);
        $data = json_encode($data);
        return bin2hex(openssl_encrypt($data, 'des-cbc', $key, 1, $key));
    }
}
