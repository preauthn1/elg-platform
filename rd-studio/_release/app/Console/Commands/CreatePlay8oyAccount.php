<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Helpers\VendorSend;
use App\Helpers\Common;
use App\Helpers\VendorPost;
use App\Models\VendorInfo;
use App\Models\Play8oyAccount;
use DB;

class CreatePlay8oyAccount extends Command
{

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'create_play8oy_account';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '預先建立Play8oy帳號';

    protected $vendor_send;
    protected $vendor_id = 30;
    protected $name = 'play8oy';
    protected $action = 'play8oy_create_accounts';
    protected $account_length = 8;

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
        $this->vendor_send = new VendorSend(['json_post'=>true]);
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
                        $count = Play8oyAccount::where('member_id', 0)
                            ->where('vendor_info_id', $row->id)
                            ->count();

                        if ($count < $row->prepared_account_number) {
                            //帳號前綴
                            $prefix = $row->account_prefix;
                            // 取目前帳號最大值
                            $max = Play8oyAccount::where('username', 'like', $prefix . '%')
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
                            $this->createAccount($info, $prefix, $max, $number, $row->id);
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

    private function createAccount($info, $prefix, $start, $number, $info_id)
    {
        $fail = 0;
        $api_agent = strtolower($info->API_AGENT);
        for ($i = 1; $i <= $number; $i++) {
            // 帳號規則，前綴加上數字，總長度不超過13個位元
            $username = $prefix . str_pad(($start + $i), $this->account_length, '0', STR_PAD_LEFT);
            $row = array(
                'vendor_info_id' => $info_id,
                'username' => $username,
                'username_modified' => $api_agent . $username,
                'admin' => 1
            );
            $account = Play8oyAccount::create($row);
            // 密碼規則,6到12碼
            $password = [REDACTED] . strtotime($account->created_at)), 8, 12);
            // 在遊戲商新建帳號
            $result = $this->createVendorAccount($info, $username, $password);
            // 如果失敗就將這筆記錄member_id改為-999999999
            if ($result['code'] != 1000 || !is_array($result)) {
                $account->update(['member_id' => -999999999]);
                $fail++;
                echo '!!!!!' . $username . '!!!!!' . PHP_EOL;
            } else {
                echo '*****' . $username . '*****' . PHP_EOL;
            }
        }
        return $fail;
    }

    private function createVendorAccount($info, $username, $password)
    {
        try {
            $url = 'player/create';
            $data = array();
            $data['PlayerAccount'] = $username;
            $data['Password'] = $password;
            $data['Agent'] = $info->API_AGENT;
            ksort($data);
            $url = $info->API_URL . $url;
            $header = array('merchantName:' . $info->API_MERCHANT_NAME,
                'Sign:' . $this->sign($data, $info->API_KEY),
                'Content-Type:application/json');
            $response = $this->vendor_send->send($this->action, $url, 'POST', $data, $header);
            $result = json_decode($response, true);
            if ($result['Code'] == '0') {
                return [
                    'code' => 1000,
                    'message' => $result['Message']
                ];
            } else {
                return [
                    'code' => 9996,
                    'message' => $result['Message']
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

    private function sign($data, $apiKey)
    {
        $string = '';
        if (count($data) > 0) {
            foreach ($data as $key => $value) {
                $string .= $key . '=' . $value . '&';
            }
        }
        $string .= 'PrivateKey=' . $apiKey;
        $hash_hmac = hash('sha256', $string, true);
        return base64_encode($hash_hmac);
    }
}
