<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Helpers\Common;
use App\Helpers\VendorSend;
use App\Models\YbetAccount;
use App\Models\YbetBetRecord;
use App\Models\VendorInfo;
use DB;

class GetYbetDetail extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'ybet_detail {--start=} {--end=} {--username=}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Yeebet 投注記錄';
    protected $vendor_id = 52;
    protected $action = 'ybet_get_detail';

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
            $start_time = $this->option('start')
                ? str_replace('@', ' ', $this->option('start')) . ':00:00'
                : date('Y-m-d H:i:s', strtotime('+7hour'));
            $end_time = $this->option('end')
                ? str_replace('@', ' ', $this->option('end')) . ':00:00'
                : date('Y-m-d H:i:s', strtotime('+8hour'));
            $username = $this->option('username') ? $this->option('username') : null;
            echo '時間範圍:' . $start_time . ' to ' . $end_time . PHP_EOL;
            $settings = VendorInfo::where('vendor_id', $this->vendor_id)
                ->where('status', 1)
                ->get();
            $used = []; //已執行過的不再執行
            if (count($settings) > 0) {
                foreach ($settings as $setting) {
                    $vendor_info_id = $setting->id;
                    //將遊戲商資訊解密
                    $info = Common::decryptVendorInfo($setting);
                    if (is_object($info) && !in_array($info->API_KEY, $used)) {
                        $records = $this->getBetRecord($info, $start_time, $end_time, $vendor_info_id, $username);
                        if (is_array($records) && count($records) > 0) {
                            $this->addRecord($records);
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

    private function getBetRecord($info, $start_time, $end_time, $vendor_info_id, $username)
    {
        $records = array();
        if($username == null){
            $members = YbetAccount::where('vendor_info_id', $vendor_info_id)->where('member_id', '>', 0)->get();
        } else {
            $members = YbetAccount::where('username', $username)->get();
        }

        foreach ($members as $user) {
            $url = $info->API_URL . '/record/bets/detail';
            $data = array();
            $data['appid'] = $info->APP_ID;
            $data['notifyid'] = time();
            $data['username'] = $user['username'];
            $data['begintime'] = strtotime($start_time . '-8hour');
            $data['endtime'] = strtotime($end_time . '-8hour');
            $data['index'] = '0';
            $data['size'] = 2000;
            $data['sign'] = $this->sign($info, $data);

            $url .= '?' . $this->getParam($data);
            $vendorSend = new VendorSend();
            $response = $vendorSend->send($this->action, $url, 'POST', []);
            $result = json_decode($response, true);
            if (is_array($result) && $result['result'] == 0) {
                array_push($records, ...$result['array']);
            }
        }
        return $records;
    }

    private function addRecord($records)
    {
        foreach ($records as $row) {
            $data = array();
            $data['bet_id'] = $row['id'];
            $data['game_code'] = $row['gameid'];
            $data['username'] = $row['username'];
            $data['bet'] = $row['betamount'];
            $data['win_lose'] = $row['winlost'];
            //API回傳的是13位時間戳
            $data['bet_time'] = $date = date('Y-m-d H:i:s', strtotime($row['createtime'] . ' -8 hours'));
            $data['settle_time'] = $date = date('Y-m-d H:i:s', strtotime($row['createtime'] . ' -8 hours'));
            $data['info'] = json_encode($row);
            //因為他們bet_id會重複,所以先採用update的方式取代掉舊的注單
            $record = YbetBetRecord::where('bet_id', $data['bet_id'])->first();
            if (!is_object($record)) {
                YbetBetRecord::create($data);
                echo '*****' . $data['bet_id'] . '*****';
            } else {
                $record->update($data);
                echo '!!!!!!!' . $data['bet_id'] . '!!!!!!!';
            }
        }

        echo 'success: ';
        echo PHP_EOL;

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
