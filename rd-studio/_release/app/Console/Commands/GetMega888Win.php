<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Helpers\Common;
use App\Helpers\VendorSend;
use App\Models\VendorInfo;
use App\Models\Mega888DailyWin;
use UUID;

class GetMega888Win extends Command
{

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'mega888_daily_win {--date=}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '更新mega888每日贏分';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    protected $vendorsend;

    public function __construct()
    {
        parent::__construct();
        $this->vendorsend = new VendorSend(['json_post' => true]);
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        try {
            $date = $this->option('date') ? $this->option('date') : date('Y-m-d', strtotime('+7hours30minute'));
            $settings = VendorInfo::where('vendor_id', 10)->where('status', 1)->get();
            $used = []; //已執行過的不再執行
            foreach ($settings as $row) {
                $info = json_decode($row->info);
                if (is_object($info) && !in_array($info->API_KEY, $used)) {
                    $posturl = 'open.mega.player.total.report';
                    $url = $info->API_URL . $posturl;
                    //查报表的类型，默认为1。mega电游/BG视讯 --> 1/2
                    for ($i = 1; $i < 3; $i++) {
                        $random = (string)UUID::generate();
                        $data = array();
                        $data['random'] = $random;
                        $data['digest'] = $this->digest($random, $info);
                        $data['sn'] = $info->SN;
                        $data['agentLoginId'] = $info->ACCOUNT;
                        $data['type'] = $i;
                        $data['startTime'] = $date . ' 00:00:00';
                        $data['endTime'] = $date . ' 23:59:59';

                        $post = [
                            'id' => $data['random'],
                            'method' => $posturl,
                            'params' => $data,
                            'jsonrpc' => '2.0',
                        ];

                        $result = json_decode($this->vendorsend->send('mega888_get_win', $url, 'POST', $post, ['Content-Type:application/json']), true);
//                $result = $this->post($url, $data);
                        if (is_array($result) && $result['error'] == null) {
                            $data = array();
                            $data['vendor_info_id'] = $row->id;
                            $data['date'] = $date;
                            Mega888DailyWin::where('date', $date)->where('vendor_info_id', $row->id)->where('type', $i)->delete();
                            foreach ($result['result'] as $record) {
                                $data['type'] = $i;
                                $data['loginId'] = $record['loginId'];
                                $data['bet'] = $record['bet'];
                                $data['win'] = $record['win'];
                                $data['yieId'] = $record['yield'];
                                $data['userId'] = $record['userId'];
                                $data['name'] = $record['name'];
                                $data['memo'] = $record['memo'];
                                $data['tel'] = $record['tel'];
                                $win = Mega888DailyWin::where('date', $date)->where('loginId', $record['loginId'])->where('type', $i)->first();
                                // if (!is_object($win)) {
                                Mega888DailyWin::create($data);
                                echo '*****' . $record['loginId'] . '*****';
                                // } else {
                                //     Mega888DailyWin::where('date', $date)->where('loginId', $row['loginId'])->where('type', $i)->update($data);
                                //     echo '-------' . $row['loginId'] . '-------';
                                // }
                            }
                            echo 'sucess-' . $i . PHP_EOL;
                        } else {
                            echo 'fail-' . $i;
                        }
                    }
                    $used[] = $info->API_KEY;
                }
            }
        } catch (\Illuminate\Database\QueryException $e) {
            $info = $e->errorInfo;
            echo $info[0] . ' : ' . $info[2];
        } catch (\Exception $e) {
            echo $e->getCode() . ' : ' . $e->getMessage();
        }
    }

    /**
     * -取簽名
     */
    private function digest($random, $info)
    {
        return strtoupper(md5($random . $info->SN . $info->ACCOUNT . $info->API_KEY));
    }

//    private function post($url, $data)
//    {
//        $post = [
//            'id' => $data['random'],
//            'method' => $url,
//            'params' => $data,
//            'jsonrpc' => '2.0',
//        ];
//        Common::log(0, $post, 'mega888_daily_win');
//        $ch = curl_init();
//        curl_setopt($ch, CURLOPT_URL, env('MEGA888_URL') . $url);
//        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($post));
//        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
//            'Content-Type:application/json'
//        ));
//        curl_setopt($ch, CURLOPT_POST, TRUE);
//        curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
//        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
//        $result = curl_exec($ch);
//
//        curl_close($ch);
//        Common::log(0, $result, 'mega888_daily_win');
//        return json_decode($result, true);
//    }
}