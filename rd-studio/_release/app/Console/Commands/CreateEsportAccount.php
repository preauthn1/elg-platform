<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Helpers\Common;
use App\Helpers\VendorSend;
use App\Models\VendorInfo;
use App\Models\EsportAccount;

class CreateEsportAccount extends Command
{

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'create_esport_account';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '預先建立Esport帳號';

    protected $vendor_send;
    protected $vendor_id = 38;
    protected $name = 'SA';
    protected $action = 'sa_create_accounts';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
        $this->vendor_send = new VendorSend(['json_post' => true]);
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
            foreach ($settings as $setting) {
                //將遊戲商資訊解密
                $info = Common::decryptVendorInfo($setting);
                if (is_object($info)) {
                    $vendor_info_id = $setting->id;
                    //帳號前綴
                    $prefix = $setting->account_prefix;
                    // 取目前剩多少未綁定帳號
                    $count = EsportAccount::where('member_id', 0)
                        ->where('vendor_info_id', $vendor_info_id)
                        ->count();
                    echo '-------------------------' . PHP_EOL;
                    echo '設定數量/未綁定帳號數量:' . $setting->prepared_account_number . '/' . $count . PHP_EOL;
                    if ($count < $setting->prepared_account_number) {
                        // 取目前帳號最大值
                        $max = EsportAccount::where('username', 'like', $prefix . '%')
                            ->where('vendor_info_id', $vendor_info_id)
                            ->orderBy('username', 'DESC')
                            ->pluck('username')
                            ->first();
                        $max = substr($max, -8) ? substr($max, -8) : 0;
                        // 建立帳號補齊備用數量
                        $number = ($setting->prepared_account_number - $count);

                        $this->createAccount($info, $prefix, $max, $number, $vendor_info_id);
                    } else {
                        echo 'Account is enough' . PHP_EOL;
                    }
                } else {
                    echo $setting->name . '解密失敗' . PHP_EOL;
                }
            }
        }
    }

    private function createAccount($info, $prefix, $start, $number, $info_id)
    {
        //失敗數量
        $fail = 0;
        for ($i = 1; $i <= $number; $i++) {
            // 帳號規則，前綴加上8碼數字，總長度不低於6個字元和超過12個位元
            $username = $prefix . str_pad(($start + $i), 8, '0', STR_PAD_LEFT);
            $row = array(
                'vendor_info_id' => $info_id,
                'username' => $username,
                'admin' => 0
            );
            $account = EsportAccount::create($row);

            // 在Esport新建帳號
            $result = $this->createVendorAccount($info, $username);

            // 如果失敗就將這筆記錄member_id改為-999999999
            if (is_array($result) && $result['code'] == 1000) {
                echo '*****' . $username . '*****' . PHP_EOL;
            } else {
                $account->update(['member_id' => -999999999]);
                $fail++;
                echo '!!!!!' . $username . '!!!!! ' . $result['message'] . PHP_EOL;
            }
        }
        return $fail;
    }

    private function createVendorAccount($info, $username)
    {
        try {
            // New players
            $url = $info->API_URL . '/user/register';

            $param = array(
                'platFormId' => $info->PLATFORMID,
                'userId' => $username,
                'userName' => $username
            );

            $postData = ['data' => $this->aes($info, json_encode($param))];

            $headers = array('Content-Type: application/json');
            $response = $this->vendor_send->send($this->action, $url, 'POST', $postData, $headers);
            $result = json_decode($response);

            if (is_object($result) && $result->code === 0) {
                return [
                    'code' => 1000
                ];
            } else {
                return [
                    'code' => 9996,
                    'message' => $result->msg,
                ];
            }
        } catch (\Illuminate\Database\QueryException $e) {
            $info = $e->errorInfo;
            return [
                'code' => $info[0],
                'message' => $info[2],
            ];
        } catch (\Exception $e) {
            return [
                'code' => $e->getCode(),
                'message' => $e->getMessage(),
            ];
        }
    }

    private function aes($info, $str)
    {
        $encrypted = openssl_encrypt($str, 'aes-256-cbc', $info->KEY, 1, $info->IV);
        return base64_encode($encrypted);
    }
}
