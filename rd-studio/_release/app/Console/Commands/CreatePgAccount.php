<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Helpers\Common;
use App\Models\PgAccount;
use App\Models\VendorInfo;
use App\DES;
use SoapClient;

class CreatePgAccount extends Command
{

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'create_pg_account';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '預先建立pg帳號';

    protected $vendor_send;
    protected $vendor_id = 20;
    protected $name = 'PG';
    protected $action = 'pg_create_account';

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
                    //帳號前綴
                    $prefix = $row->account_prefix;
                    // 取目前剩多少未綁定帳號
                    $count = PgAccount::where('member_id', 0)->where('vendor_info_id', $row->id)->count();
                    if ($count < $row->prepared_account_number) {
                        // 取目前帳號最大值
                        $max = PgAccount::where('username', 'like', $prefix . '%')
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
            $username = $prefix . str_pad(($start + $i), 8, '0', STR_PAD_LEFT);
            $row = array(
                'vendor_info_id' => $infoId,
                'username' => $username,
                'admin' => 1,
            );
            $account = PgAccount::create($row);
            // 在MT新建帳號
            $result = $this->createVendorAccount($info, $username);
            $result_headers = explode(" ",$result);
            if (is_array($result_headers) && $result_headers[1] == "200") {
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
    private function createVendorAccount($info, $username)
    {
        try {
            $data = array();
            $data['UserInfo']['ExternalUserId'] = $username;
            $data['UserInfo']['Username'] = $username;
            $data['UserInfo']['Nickname'] = $username;
            $data['UserInfo']['Currency'] = $info->Currency;
            $data['UserInfo']['Country'] = $info->Country;
            $data['UserInfo']['Birthdate'] = '2000-01-01';
            $data['UserInfo']['Registration'] = date('Y-m-d');
            $data['UserInfo']['BrandId'] = $info->Brand;
            $data['UserInfo']['Language'] = $info->Language;
            $data['UserInfo']['IP'] = '';
            $data['UserInfo']['Locked'] = 'false';
            $data['UserInfo']['Gender'] = '';
            $result = $this->post($info,$data);
            return $result;
        } catch (\Exception $e) {
            return $e->getMessage();
        }
    }

    private function post($info,$data)
    {   
        $wsdl = $info->API_URL;
        Common::log(0, $data, $this->action);
        $option = array(
            "ssl" => array('ciphers' => 'RC4-SHA', 'verify_peer' => false, 'verify_peer_name' => false),
            'location' => $wsdl,
            "login" => $info->API_LOGIN,
            "password" => $info->API_PASSWORD,
            'trace' => 1,
        );
        $client = new SoapClient($wsdl, $option);
        $result = $client->RegisterUser($data);
        $result_headers = $client->__getLastResponseHeaders();
        Common::log(0, $result_headers, $this->action);
        return $result_headers;
    }
}
