<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Helpers\Common;
use App\Helpers\VendorSend;
use App\Models\IgsDailyWin;
use App\Models\VendorInfo;
use DB;

class GetIgsDailyWin extends Command
{

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'igs_daily_win {--start=} {--end=}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '更新igs輸贏紀錄';

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
            $start = $this->option('start')
                ? str_replace('@', ' ', $this->option('start')) . ':00:00'
                : date('Y-m-d H:00:00', strtotime('+7hour'));
            $end = $this->option('end')
                ? str_replace('@', ' ', $this->option('end')) . ':00:00'
                : date('Y-m-d H:00:00', strtotime('+8hour'));
            echo $start . ' to ' . $end . PHP_EOL;
            $settings = VendorInfo::where('vendor_id', 26)->where('status', 1)->get();
            $used = []; //已執行過的不再執行

            foreach ($settings as $row) {
                //將遊戲商資訊解密
                $info = Common::decryptVendorInfo($row);
                if (is_object($info) && !in_array($info->APP_KEY, $used)) {
                    $total = 0;
                    $url = $info->API_URL . 'openapi/report/audit';
                    $limit = 1000;
                    $time = time();
                    $current = 0;

                    $data = array(
                        'sign' => '',
                        'ts' => $time,
                        'app_id' => $info->APP_ID,
                        'begin_at' => $start,
                        'end_at' => $end,
                        'limit' => $limit,
                        'current' => $current,
                    );
                    //如果總頁數>1，繼續跑api撈下一頁注單
                    do {
                        $nextLoop = false;
                        $data['current'] = $current;
                        $sign = md5(md5('app_id' . $info->APP_ID . 'begin_at' . $start . 'current' . $current . 'end_at' . $end . 'limit' . $limit . 'ts' . $time . $info->APP_ID));
                        $data['sign'] = $sign;
                        $total = $this->getRecord($info, $url, $data);
                        $current++;
                        if ($total > $current) {
                            $nextLoop = true;
                        }
                    } while ($nextLoop);
                    $used[] = $info->APP_KEY;
                } else {
                    echo $row->name . '解密失敗' . PHP_EOL;
                }
            }

        } catch (\Illuminate\Database\QueryException $e) {
            $info = $e->errorInfo;
            echo $info[0] . ' : ' . $info[2];
        } catch (\Exception $e) {
            echo $e->getCode() . ' : ' . $e->getMessage();
        }
    }

    private function getRecord($info, $url, $data)
    {
        $headers = array('Authorization:' . $this->getToken($info));
        $records = json_decode($this->vendorsend->send('igs_get_daily_win', $url, 'GET', $data, $headers), true);
        if (is_array($records) && $records['msg'] == 'success') {
            $datas = array();
            foreach ($records['data']['list'] as $row) {
                $date = $data['begin_at'];
                $datas[] = array(
                    'user_id' => $row['user_id'],
                    'date_time' => $date,
                    'win_lose' => $row['dpla'] / 10,
                    'bet_times' => $row['dbo'],
                    'bet' => $row['dba'] / 10,
                    'utc_date_time' => date("Y-m-d H:i:s", strtotime("$date -8hours")),
                );
            }
            if ($datas) {
                $this->addRecord($datas);
            }
            //回傳總頁數
            return $records['data']['total'];
        } else {
            return 0;
        }

    }

    private function addRecord($datas)
    {
        dump($datas);
        $fields = array_keys($datas[0]);
        $sql = "INSERT IGNORE INTO igs_bet_records (" . join(',', $fields) . ")";

        $values = array();
        $bindings = array();
        foreach ($datas as $row) {
            foreach ($row as $v) {
                $bindings[] = $v;
            }
            $values[] = '(' . implode(', ', array_map(function () {
                    return "?";
                }, $row)) . ')';
        }
        $sql .= " VALUES " . implode(', ', $values);
        DB::insert($sql, $bindings);
    }

    private function getToken($info)
    {
        $url = $info->API_URL . 'openapi/oauth/access-token';
        $time = time();
        $sign = md5(md5('app_id' . $info->APP_ID . 'app_key' . $info->APP_KEY . 'ts' . $time . $info->APP_ID));

        $data = array(
            'app_id' => $info->APP_ID,
            'app_key' => $info->APP_KEY,
            'ts' => $time,
            'sign' => $sign,
        );
        $result = json_decode($this->vendorsend->send('igs_get_token', $url, 'POST', $data), true);
        return $result['data']['access_token'];
    }

}
