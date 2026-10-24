<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Helpers\Common;
use App\Models\HaolongAccount;
use App\Models\VendorInfo;
use DB;

class ChangeHaolongPassword extends Command
{

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'change_haolong_password';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '修正豪龍代理密碼';

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
        // 修改密碼
        // $url = 'changePasswordStatus.aspx';
        // $data = array();
        // $data['loginID'] = 'vwclyal01';
        // $data['newpass'] = '831645be0a706279355a394fc0b6af4b';
        // $data['newpass'] = '176D552FC77D40619B263EC0D912A13A';
        // {"loginID":"vwcVWQA96","amount":"25","apiUser":"tw4d","apiPass":"tw4d@win2017","user":"vwcJJMMYY","pass":"1776AE3162B64ECEB19E5B8B0D3AD16B"}
        $settings = VendorInfo::where('vendor_id', 4)->where('status', 1)->first();
        $info = json_decode($settings->info);
        $url = 'withdraw.aspx';
        $data = array();
        $data['loginID'] = 'vwcVWQA96';
        $data['amount'] = '0.5';
        $data['apiUser'] = "tw4d";
        $data['apiPass'] = "tw4d@win2017";
        $data['user'] = "vwcJJMMYY";
        $data['pass'] = "1776AE3162B64ECEB19E5B8B0D3AD16B";
        $result = $this->post($url, $data, $info);
    }



    private function post($url, $data, $info)
    {
        Common::log(0, $data, 'change_haolong_password');
        // $data['apiUser'] = env('HAOLONG_API_USER');
        // $data['apiPass'] = env('HAOLONG_API_PASSWORD');
        // $data['user'] = env('HAOLONG_USER');
        // $data['pass'] = env('HAOLONG_PASSWORD');
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $info->API_URL . $url);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        curl_setopt($ch, CURLOPT_POST, TRUE);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
        // curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        $result = curl_exec($ch);
        var_dump($result);
        curl_close($ch);
        Common::log(0, $result, 'change_haolong_password');
        return simplexml_load_string($result);
    }
}