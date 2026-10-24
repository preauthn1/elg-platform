<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Helpers\VendorSend;
use App\Helpers\Common;
use App\Models\VendorInfo;
use App\Models\V8Account;
use DB;

class CreateV8Account extends Command
{

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'create_v8_account';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '預先建立V8帳號';

    protected $vendor_send;
    protected $vendor_id = 42;
    protected $name = 'V8';
    protected $action = 'v8_create_accounts';
    protected $account_length = 8;

    /**
     * Create a new command instance.
     *
     * @return void
     */
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
            $settings = VendorInfo::where('vendor_id', $this->vendor_id)
                ->where('status', 1)
                ->get();

            if (is_object($settings)) {
                foreach ($settings as $row) {
                    //將遊戲商資訊解密
                    $info = Common::decryptVendorInfo($row);
                    if (is_object($info)) {
                        // 取目前剩多少未綁定帳號
                        $count = V8Account::where('member_id', 0)
                            ->where('vendor_info_id', $row->id)
                            ->count();

                        if ($count < $row->prepared_account_number) {
                            //帳號前綴
                            $prefix = $row->account_prefix;

                            // 取目前帳號最大值
                            $max = V8Account::where('username', 'like', $prefix . '%')
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
            $account = V8Account::create($row);
            // 在遊戲商新建帳號
            $result = $this->createVendorAccount($info, $username);
            // 如果失敗就將這筆記錄member_id改為-999999999
            if ($result['code'] != 1000 || !is_array($result)) {
                $account->update(['member_id' => -999999999]);
                $fail++;
                echo '!!!!!' . $username . '!!!!!' . PHP_EOL;
                echo 'API_Error_Code : ' . $result['message'] . PHP_EOL;
            } else {
                $account->update(['username_modified' => $info->AGENT . '_' . $username]);
                echo '*****' . $username . '*****' . PHP_EOL;
            }
            // API限制1秒只能呼叫一次
            sleep(1);
        }
        return $fail;
    }

    private function createVendorAccount($info, $username)
    {
        try {
            $url = $info->API_URL;
            $time = time() . '000';
            $data = array(
                's' => 0,
                'account' => $username,
                'money' => 0,
                'orderid' => $info->AGENT . $time . $username,
                'ip' => '0.0.0.0',
                'lineCode' => $info->LINECODE,
                'KindID' => 0
            );

            $message = http_build_query($data);
            $param = $this->getParam($info, $message);

            $postData = array(
                "agent" => $info->AGENT,
                "timestamp" => $time,
                "param" => $param,
                "key" => $this->getKey($info, $time)
            );
            //不使用http_build_query()是因為param內有些字會被換掉
            $getParam = $this->params($postData);
            $url .= '?' . $getParam;
            $response = $this->vendor_send->send($this->action, $url, 'GET');
            $result = json_decode($response, true);

            if (is_array($result) && $result['d']['code'] == '0') {
                return [
                    'code' => 1000
                ];
            } else {
                return [
                    'code' => 9996,
                    'message' => $result['d']['code']
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

    private function getParam($info, $message)
    {
        return urlencode(openssl_encrypt($message, 'aes-128-ecb', $info->DESKEY, 0));
    }

    private function getKey($info, $time)
    {
        return md5($info->AGENT . $time . $info->MD5KEY);
    }
}