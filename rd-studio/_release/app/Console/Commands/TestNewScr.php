<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Helpers\Common;
use App\Models\ScrAccount;
use DB;

class TestNewScr extends Command
{

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'test_new_scr';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '測試918的穩定度';

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
        // 遊戲記錄 2020-02-11 14:24
        // $url = 'account/account.ashx';
        // $data = array();
        // $data['action'] = 'disable';
        // $data['userName'] = '01268390166';
        // $data['time'] = date('U') . '000';
        // $data['authcode'] = 'vVwzNNhghpFDYBTnaEqA';
        // $data['sign'] = $this->sign($data['authcode'] . $data['userName'] . $data['time'] . 'vBEA65ar4Jj32G6h2c47');

        // $report = $this->post($url, $data);
        // var_dump($report);
        // exit;
        // $url = 'UserscoreLog.ashx';
        // $data = array();
        // $data['userName'] = '01268390166';
        // $data['sDate'] = '2020-02-11 00:00:00';
        // $data['eDate'] = '2020-02-11 23:00:00';
        // $data['time'] = date('U') . '000';
        // $data['authcode'] = 'vVwzNNhghpFDYBTnaEqA';
        // $data['sign'] = $this->sign($data['authcode'] . $data['userName'] . $data['time'] . 'vBEA65ar4Jj32G6h2c47');

        // $report = $this->post($url, $data);
        // var_dump($report);
        // exit;
        // $url = 'AgentMoneyLog.ashx';
        // $data = array();
        // $data['userName'] = '01268390166';
        // $data['sDate'] = '2020-02-11 00:00:00';
        // $data['eDate'] = '2020-02-11 23:00:00';
        // $data['time'] = date('U') . '000';
        // $data['authcode'] = 'vVwzNNhghpFDYBTnaEqA';
        // $data['sign'] = $this->sign($data['authcode'] . $data['userName'] . $data['time'] . 'vBEA65ar4Jj32G6h2c47');

        // $report = $this->post($url, $data);
        // var_dump($report);
        // exit;
        // $url = 'AccountReport.ashx';
        // $data = array();
        // $data['userName'] = '01268390166';
        // $data['sDate'] = '2020-02-11 00:00:00';
        // $data['eDate'] = '2020-02-11 23:00:00';
        // $data['time'] = date('U') . '000';
        // $data['authcode'] = 'vVwzNNhghpFDYBTnaEqA';
        // $data['sign'] = $this->sign($data['authcode'] . $data['userName'] . $data['time'] . 'vBEA65ar4Jj32G6h2c47');

        // $report = $this->post($url, $data);
        // var_dump($report);
        // exit;
        // $url = 'GameLog.ashx';
        // $data = array();
        // $data['userName'] = '01268390166';
        // $data['sDate'] = '2020-02-11 00:00:00';
        // $data['eDate'] = '2020-02-11 23:00:00';
        // $data['time'] = date('U') . '000';
        // $data['authcode'] = 'vVwzNNhghpFDYBTnaEqA';
        // $data['sign'] = $this->sign($data['authcode'] . $data['userName'] . $data['time'] . 'vBEA65ar4Jj32G6h2c47');

        // $report = $this->post($url, $data);
        // var_dump($report);
        // exit;
        // $url = 'AgentTotalReport.ashx';
        // $data = array();
        // $data['userName'] = 'elgames';
        // $data['sDate'] = '2020-02-11 00:00:00';
        // $data['eDate'] = '2020-02-11 23:00:00';
        // $data['Type'] = 'ServerTotalReport';
        // $data['time'] = date('U') . '000';
        // $data['authcode'] = 'vVwzNNhghpFDYBTnaEqA';
        // $data['sign'] = $this->sign($data['authcode'] . $data['userName'] . $data['time'] . 'vBEA65ar4Jj32G6h2c47');

        // $report = $this->post($url, $data);
        // var_dump($report);
        // exit;
        //查詢餘額
        $user_info = $this->getBalance();
        var_dump($user_info);
        //如果拿到接下來就轉點
        if (is_array($user_info) && $user_info['success'] === true) {
            $result = $this->transaction($user_info);
            var_dump($result);
        }
    }

    private function getBalance($isFirst = true)
    {
        $url = 'account/account.ashx';
        $data = array();
        $data['action'] = 'getUserInfo';
        // $data['userName'] = '01268390166';
        $data['userName'] = '01598327557';
        $data['time'] = date('U') . '000';
        $data['authcode'] = 'vVwzNNhghpFDYBTnaEqA';
        $data['sign'] = $this->sign($data['authcode'] . $data['userName'] . $data['time'] . 'vBEA65ar4Jj32G6h2c47');

        $user_info = $this->post($url, $data, $isFirst);
        if (is_array($user_info) && $user_info['success'] === true) {
            return $user_info;
        } else if ($isFirst) {
            $this->getBalance(false);
        }
    }

    private function transaction($user_info, $isFirst = true)
    {
        $url = 'account/setScore.ashx';
        $data = array();
        $data['action'] = 'setServerScore';
        $data['orderid'] = date('U') . rand(1000, 9999);
        $data['scoreNum'] = ($user_info['MoneyNum'] > 0) ? -$user_info['MoneyNum'] : rand(1, 10);
        // $data['userName'] = '01268390166';
        $data['userName'] = '01598327557';
        $data['ActionUser'] = 'crontab';
        $data['ActionIp'] = '0.0.0.0';
        $data['time'] = date('U') . '000';
        $data['authcode'] = 'vVwzNNhghpFDYBTnaEqA';
        $data['sign'] = $this->sign($data['authcode'] . $data['userName'] . $data['time'] . 'vBEA65ar4Jj32G6h2c47');

        $result =  $this->post($url, $data, $isFirst);
        if (is_array($result) && $result['success'] === true) {
            return $result;
        } else if ($isFirst) {
            $this->transaction($user_info, false);
        }
    }
    private function sign($string)
    {
        // var_dump(strtolower($string));
        return  strtoupper(md5(strtolower($string)));
    }
    private function post($url, $data, $isFirst = true)
    {
        Common::log(0, $data, 'new918_' . $data['action'] . ($isFirst ? 1 : 2));
        // echo 'http://example.invalid:9991/ashx/' . $url . '?' . http_build_query($data);
        $host = 'http://example.invalid:9991/ashx/'; //平常轉點用
        // $host = 'http://example.invalid:9919/ashx/'; //報表用
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $host . $url);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        curl_setopt($ch, CURLOPT_POST, TRUE);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        $result = curl_exec($ch);
        curl_close($ch);
        Common::log(0, $result, 'new918_' . $data['action']  . ($isFirst ? 1 : 2));
        return json_decode($result, true);
    }
}