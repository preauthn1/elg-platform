<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Helpers\Common;
use App\Models\VendorInfo;
use App\Models\PtAccount;
use DB;

class CreatePtAccount extends Command
{

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'create_pt_account';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '預先建立PT帳號';

    protected $vendor_send;
    protected $vendor_id = 17;
    protected $name = 'WM';
    protected $action = 'wm_create_account';

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
                $prefix = $row->account_prefix; //帳號前綴
                // 取目前剩多少未綁定帳號
                $count = PtAccount::where('member_id', 0)->where('vendor_info_id', $row->id)->count();
                if ($count < $row->prepared_account_number) {
                    //將遊戲商資訊解密
                    $info = Common::decryptVendorInfo($row);
                    if (is_object($info)) {
                        // 取目前帳號最大值
                        $max = PtAccount::where('username', 'like', $prefix . '%')->where('vendor_info_id', $row->id)->orderBy('username', 'DESC')->pluck('username')->first();
                        $max = substr($max, -8) ? substr($max, -8) : 0;
                        // 建立帳號補齊備用數量
                        $number = ($row->prepared_account_number - $count);
                        if ($fail = $this->createAccount($info, $prefix, $max, $number, $row->id)) {
                            // 如果有失敗的就再加開那個數量，這樣下次排程執行時就不會卡在失敗那筆，可以重新補齊
                            $this->createAccount($info, $prefix, ($max + $number), $fail, $row->id);
                        }
                    } else {
                        echo $row->name . '解密失敗';
                    }
                }
            }
        }
    }

    private function createAccount($info, $prefix, $start, $number, $infoId)
    {
        $fail = 0;
        for ($i = 1; $i <= $number; $i++) {
            // 帳號規則，前綴加上8碼數字
            $username = $prefix . str_pad(($start + $i), 8, '0', STR_PAD_LEFT);
            $row = array(
                'vendor_info_id' => $infoId,
                'username' => $username,
                'admin' => 1
            );
            $account = PtAccount::create($row);
            // 密碼規則,6到12碼
            $password = [REDACTED] . strtotime($account->created_at)), 8, 12);
            // 在遊戲商新建帳號
            $result = $this->createVendorAccount($info, $username, $password);
            // 如果失敗就刪除這筆記錄
            if ($result != false && is_array($result)) {
                if (isset($result['error'])) {
                    $account->update(['member_id' => -999999999]);
                    $fail++;
                    echo '!!!!!' . $username . '!!!!!';
                } else {
                    echo '*****' . $username . '*****';
                }
            } else {
                $account->update(['member_id' => -999999999]);
                $fail++;
                echo '!!!!!' . $username . '!!!!!';
            }
        }
        return $fail;
    }

    // 確認或建立帳號
    private function createVendorAccount($info, $username, $password)
    {
        try {
            $url = 'player/create/';
            $data = array();
            $data['playername'] = $username;
            $data['password'] = $password;
            $data['adminname'] = $info->ADMIN_NAME;
            $data['kioskname'] = $info->KIOSK_NAME;
//            $data['viplevel'] = '42';
            $result = $this->request($info, $url, $data);
            return $result;
        } catch (\Exception $e) {
            return $e->getMessage();
        }
    }

    private function request($info, $url, $data)
    {
        Common::log(0, $data, 'create_pt_account');
        $path = base_path();
        $header = array();
        $header[] = "Accept:text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8";
        $header[] = "Cache-Control: max-age=0";
        $header[] = "Connection: keep-alive";
        $header[] = "Keep-Alive:timeout=5, max=100";
        $header[] = "Accept-Charset:ISO-8859-1,utf-8;q=0.7,*;q=0.3";
        $header[] = "Accept-Language:es-ES,es;q=0.8";
        $header[] = "Pragma: ";
        $header[] = "X_ENTITY_KEY: " . $info->ENTITY_KEY;
        $tuCurl = curl_init();
        curl_setopt($tuCurl, CURLOPT_URL, $info->HOST . $url . str_replace(['=', '&'], '/', http_build_query($data)));
        curl_setopt($tuCurl, CURLOPT_PORT, 443);
        curl_setopt($tuCurl, CURLOPT_VERBOSE, 0);
        curl_setopt($tuCurl, CURLOPT_HTTPHEADER, $header);
        curl_setopt($tuCurl, CURLOPT_SSL_VERIFYPEER, 0);
        curl_setopt($tuCurl, CURLOPT_SSL_VERIFYHOST, 0);
        curl_setopt($tuCurl, CURLOPT_SSLCERT, $path . $info->CERT_PATH . '.pem');
        curl_setopt($tuCurl, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($tuCurl, CURLOPT_SSLKEY, $path . $info->CERT_PATH .  '.key');
        $result = curl_exec($tuCurl);
        curl_close($tuCurl);
        Common::log(0, $result, 'create_pt_account');
        return json_decode($result, TRUE);
    }
}