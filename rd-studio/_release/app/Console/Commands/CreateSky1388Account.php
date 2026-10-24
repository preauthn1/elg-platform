<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Helpers\Common;
use App\Helpers\VendorSend;
use App\Models\VendorInfo;
use App\Models\Sky1388Account;

class CreateSky1388Account extends Command
{

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'create_sky1388_account';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '預先建立sky1388帳號';

    protected $vendor_send;
    protected $vendor_id = 28;
    protected $name = 'Sky1388';
    protected $action = 'sky1388_create_accounts';

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
            $settings = VendorInfo::where('vendor_id', $this->vendor_id)
                ->where('status', 1)
                ->get();

            foreach ($settings as $setting) {
                //將遊戲商資訊解密
                $info = Common::decryptVendorInfo($setting);
                if (is_object($info)) {
                    $vendor_info_id = $setting->id;
                    //帳號前綴
                    $prefix = $setting->account_prefix;
                    // 取目前剩多少未綁定帳號
                    $count = Sky1388Account::where('member_id', 0)
                        ->where('vendor_info_id', $vendor_info_id)
                        ->count();
                    echo '-------------------------' . PHP_EOL;
                    echo '設定數量/未綁定帳號數量:' . $setting->prepared_account_number . '/' . $count . PHP_EOL;
                    if ($count < $setting->prepared_account_number) {
                        // 取目前帳號最大值
                        $max = Sky1388Account::where('username', 'like', $prefix . '%')
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
                'admin' => 0,
            );
            $account = Sky1388Account::create($row);

            // 在Sky1388新建帳號
            $result = $this->createVendorAccount($info, $username, $account);

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

    private function createVendorAccount($info, $username, $account)
    {
        try {
            // New players
            $url = $info->API_URL . '/user_register';
            
            $data = array();
            $data['agid'] = $info->ID; //$info->AGENCY_ID;
            $data['username'] = $username;
            // 密碼規則,6到12碼
            $password = [REDACTED] . strtotime($account->created_at)), 8, 12);
            $data['password'] = $password;
            $data['lang'] = 'eng';

            $sign = $this->sign($data, $info);
            $data['signature'] = $sign;

            $response = json_decode($this->vendor_send->send($this->action, $url, 'GET', $data));

            if (is_object($response) && strtolower($response->status) == 'ok') {
                return [
                    'code' => 1000,
                    'player_id' => $username,
                ];
            } else {
                return [
                    'code' => 9996,
                    'message' => $response->error_code,
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

    private function sign($data, $info)
    {
        ksort($data);
        $str = implode('', $data) . $info->PRIVATE_KEY;
        $sign = sha1($str);
        return $sign;
    }
}
