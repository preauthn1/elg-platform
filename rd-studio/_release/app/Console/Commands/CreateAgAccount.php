<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Helpers\Common;
use App\Models\VendorInfo;
use App\Models\AgAccount;
use App\AgService;
use App\DES;
use DB;

class CreateAgAccount extends Command
{

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'create_ag_account';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '預先建立AG帳號';
    protected $vendor_id = 1;
    protected $name = 'AG';
    protected $action = 'ag_create_account';

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
        echo $this->description . PHP_EOL;

        //檢查商戶是否維護中
        if (Common::check_vendor_maintenances($this->vendor_id)) {
            echo $this->name . ' vendor maintenances' . PHP_EOL;
        } else {
            $settings = VendorInfo::where('vendor_id', $this->vendor_id)->where('status', 1)->get();

            foreach ($settings as $row) {
                //將遊戲商資訊解密
                $info = Common::decryptVendorInfo($row);
                if (is_object($info)) {
                    // 為MG平台可使用取cagent前綴
                    $prefix = $row->account_prefix; //帳號前綴
                    // 取目前剩多少未綁定帳號
                    $count = AgAccount::where('member_id', 0)->where('vendor_info_id', $row->id)->count();
                    if ($count < $row->prepared_account_number) {
                        // 取目前帳號最大值
                        $max = AgAccount::where('username', 'like', $prefix . '%')
                            ->where('vendor_info_id', $row->id)
                            ->orderBy('username', 'DESC')
                            ->pluck('username')
                            ->first();
                        $max = substr($max, -8) ? substr($max, -8) : 0;
                        // 建立帳號補齊備用數量
                        $number = ($row->prepared_account_number - $count);
                        $this->createAccount($info, $prefix, $max, $number, $row->id);
                    } else {
                        echo 'Account is enough' . PHP_EOL;
                    }
                } else {
                    echo $row->name . '解密失敗' . PHP_EOL;
                }
            }
        }
    }

    private function createAccount($info, $prefix, $start, $number, $infoId)
    {
        $fail = 0;
        for ($i = 1; $i <= $number; $i++) {
            // 帳號規則，前綴加上8碼數字，總長度不超過13個位元
            //PRO一直帳號重覆，改成elg
            $username = $prefix . str_pad(($start + $i), 8, '0', STR_PAD_LEFT);
            $row = array(
                'vendor_info_id' => $infoId,
                'username' => $username,
                'admin' => 1
            );
            $account = AgAccount::create($row);
            // 密碼規則,6到12碼
            $password = [REDACTED] . strtotime($account->created_at)), 8, 12);
            // 在AG新建帳號
            $result = $this->createVendorAccount($info, $username, $password);
            // 如果失敗就刪除這筆記錄
            if ($result != false && is_object($result) && (string)$result->attributes()->info == '0') {
                echo '*****' . $username . '*****' . PHP_EOL;
            } else {
                $account->update(['member_id' => -999999999]);
                $fail++;
                echo '!!!!!' . $username . '!!!!!' . PHP_EOL;
            }
        }
        return $fail;
    }

    // 確認或建立帳號
    private function createVendorAccount($info, $username, $password)
    {
        try {
            $url = $info->BUSINESS_HOST;
            $params = array();
            $params[] = 'cagent=' . $info->AGENT;
            $params[] = 'loginname=' . $username;
            $params[] = 'method=lg'; // 確認或建立帳號的編號
            $params[] = 'actype=' . $info->GAME_TYPE; // 0:試玩，1:真錢
            $params[] = 'password=' . $password;
            $params[] = 'oddtype=' . $info->ODD_TYPE; // 設定可下注的金額A、B、C、D...I
            $params[] = 'cur=' . $info->CURRENCY; // 幣種

            $result = $this->request($info, $url, $params);
            return simplexml_load_string($result);
        } catch (\Exception $e) {
            return $e->getMessage();
        }
    }

    private function request($info, $url, $params = null, $needAgent = true)
    {
        Common::log(0, $params, $this->action);
        $param = implode('/\\\\/', $params);
        $des = new DES($info->DES_KEY, 'DES-ECB', DES::OUTPUT_BASE64);
        $param = $des->encrypt($param);
        $key = md5($param . $info->MD5_KEY);
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url . '?params=' . $param . '&key=' . $key);
        if ($needAgent) {
            curl_setopt($ch, CURLOPT_USERAGENT, 'WEB_LIB_GI_' . $info->AGENT);
        }
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        $result = curl_exec($ch);
        curl_close($ch);
        Common::log(0, $result, $this->action);
        return $result;
    }
}