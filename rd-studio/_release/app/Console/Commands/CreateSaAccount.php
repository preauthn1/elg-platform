<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Helpers\VendorSend;
use App\Helpers\Common;
use App\Models\VendorInfo;
use App\Models\SaAccount;
use DB;

class CreateSaAccount extends Command
{

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'create_sa_account';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '預先建立Sa帳號';

    /**
     * Create a new command instance.
     *
     * @return void
     */

    protected $vendor_send;
    protected $vendor_id = 37;
    protected $name = 'SA';
    protected $action = 'sa_create_accounts';
    protected $account_length = 8;

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
        echo $this->description . PHP_EOL;

        //檢查商戶是否維護中
        if (Common::check_vendor_maintenances($this->vendor_id)) {
            echo $this->name . ' vendor maintenances' . PHP_EOL;
        } else {
            //取設定檔
            $settings = VendorInfo::where('vendor_id', $this->vendor_id)->where('status', 1)->get();
            if (is_object($settings)) {
                foreach ($settings as $row) {
                    //將遊戲商資訊解密
                    $info = Common::decryptVendorInfo($row);
                    if (is_object($info)) {
                        // 取目前剩多少未綁定帳號
                        $count = SaAccount::where('member_id', 0)->where('vendor_info_id', $row->id)->count();
                        if ($count < $row->prepared_account_number) {
                            //帳號前綴
                            $prefix = $row->account_prefix;

                            // 取目前帳號最大值
                            $max = SaAccount::where('username', 'like', $prefix . '%')
                                ->where('vendor_info_id', $row->id)
                                ->orderBy('username', 'DESC')
                                ->pluck('username')
                                ->first();
                            $max = str_replace($prefix, '', $max)
                                ? str_replace($prefix, '', $max)
                                : 0;
                            // 建立帳號補齊備用數量
                            $number = ($row->prepared_account_number - $count);
                            echo '補齊備用數量:' . $number . PHP_EOL;
                            $this->createAccount($info, $prefix, $max, $number, $row->id, $row->currency);
                        } else {
                            echo 'Account is enough' . PHP_EOL;
                        }
                    } else {
                        echo $row->name . '解密失敗' . PHP_EOL;
                    }
                }
            } else {
                echo '查無vendor_infos' . PHP_EOL;
            }
        }
    }

    private function createAccount($info, $prefix, $start, $number, $info_id, $currency)
    {
        $fail = 0;
        for ($i = 1; $i <= $number; $i++) {
            // 帳號規則，前綴加上數字，總長度不超過13個位元
            $username = $prefix . str_pad(($start + $i), $this->account_length, '0', STR_PAD_LEFT);
            $row = array(
                'vendor_info_id' => $info_id,
                'username' => $username,
                'admin' => 1
            );
            $account = SaAccount::create($row);
            // 在遊戲商新建帳號
            $result = $this->createVendorAccount($info, $username, $currency);
            // 如果失敗就將這筆記錄member_id改為-999999999
            if ($result['code'] != 1000 || !is_array($result)) {
                $account->update(['member_id' => -999999999]);
                $fail++;
                echo '!!!!!' . $username . '!!!!!' . $result['message'] . PHP_EOL;
            } else {
                echo '*****' . $username . '*****' . PHP_EOL;
            }
        }
        return $fail;
    }

    private function createVendorAccount($info, $username, $currency)
    {
        try {
            $url = $info->API_URL;
            $time = date('YmdHis', time());

            $data = array();
            $data['method'] = 'RegUserInfo';
            $data['Key'] = $info->SECRETKEY;
            $data['Time'] = $time;
            $data['Username'] = $username;
            $data['CurrencyType'] = $currency;

            $Qs = $this->params($data);
            $s = md5($Qs . $info->MD5KEY . $time . $info->SECRETKEY);
            $q = $this->sign($info, $Qs);

            $postData = array(
                'q' => $q,
                's' => $s
            );

            $header = array('Content-Type:application/x-www-form-urlencoded');
            $response = $this->vendor_send->send($this->action, $url, 'POST', $postData, $header);
            $result = $this->xmlToArray($response);
            if (is_array($result) && $result['ErrorMsgId'] == '0') {
                return [
                    'code' => 1000,
                    'message' => $result['ErrorMsg']
                ];
            } else {
                return [
                    'code' => 9996,
                    'message' => $result['ErrorMsg']
                ];
            }
        } catch (\Illuminate\Database\QueryException $e) {
            $info = $e->errorInfo;
            return [
                'code' => $info[0],
                'message' => $info[2]
            ];
        } catch (\Exception $e) {
            return [
                'code' => $e->getCode(),
                'message' => $e->getMessage()
            ];
        }
    }

    private function params($data)
    {
        $str = '';

        foreach ($data as $key => $val) {
            $str .= $key . '=' . $val;
            if ($val !== end($data)) {
                $str .= '&';
            }
        }

        return $str;
    }

    private function sign($info, $Qs)
    {
        return base64_encode(openssl_encrypt($Qs, 'DES-CBC', $info->ENCRYPTKEY, OPENSSL_RAW_DATA, $info->ENCRYPTKEY));
    }

    //把API回來的XML格式轉成Array
    private function xmlToArray($result)
    {
        $xml = simplexml_load_string($result);
        $json = json_encode($xml);
        return json_decode($json, true);
    }
}