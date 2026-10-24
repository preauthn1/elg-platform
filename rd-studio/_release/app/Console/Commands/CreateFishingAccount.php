<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Helpers\Common;
use App\Helpers\VendorSend;
use App\Models\VendorInfo;
use App\Models\FishingAccount;

class CreateFishingAccount extends Command
{

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'create_fishing_account';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '預先建立PGS_Fishing帳號';

    /**
     * Create a new command instance.
     *
     * @return void
     */

    protected $vendor_id = 39;
    protected $action = 'fishing_get_bet_record';
    protected $vendor_send;

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

        $settings = VendorInfo::where('vendor_id', $this->vendor_id)
            ->where('status', 1)
            ->get();

        foreach ($settings as $setting) {
            $vendor_info_id = $setting->id;
            //帳號前綴
            $prefix = $setting->account_prefix;
            // 取目前剩多少未綁定帳號
            $count = FishingAccount::where('member_id', 0)
                ->where('vendor_info_id', $vendor_info_id)
                ->count();
            echo '-------------------------' . PHP_EOL;
            echo '設定數量/未綁定帳號數量:' . $setting->prepared_account_number . '/' . $count . PHP_EOL;
            if ($count < $setting->prepared_account_number) {
                //將遊戲商資訊解密
                $info = Common::decryptVendorInfo($setting);
                if (is_object($info)) {
                    // 取目前帳號最大值
                    $max = FishingAccount::where('username', 'like', $prefix . '%')
                        ->where('vendor_info_id', $vendor_info_id)
                        ->orderBy('username', 'DESC')
                        ->pluck('username')
                        ->first();
                    $max = substr($max, -8) ? substr($max, -8) : 0;
                    // 建立帳號補齊備用數量
                    $number = ($setting->prepared_account_number - $count);

                    $this->createAccount($info, $prefix, $max, $number, $vendor_info_id);
                } else {
                    echo $setting->name . '解密失敗' . PHP_EOL;
                }
            } else {
                echo 'Account is enough' . PHP_EOL;
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
            $account = FishingAccount::create($row);

            // 在遊戲商新建帳號
            $result = $this->createVendorAccount($info, $username);
            if ($result['code'] != 1000 || !is_array($result)) {
                $account->update(['member_id' => -999999999]);
                $fail++;
                echo '!!!!!' . $username . '!!!!!' . PHP_EOL;
            } else {
                echo '*****' . $username . '*****' . PHP_EOL;
            }

        }
    }

    private function createVendorAccount($info, $username)
    {
        try {
            $url = $info->API_URL . '/3rd_exchange.aspx?source=1056';

            $data = array();
            $data['uuid'] = $username;
            $data['orderId'] = time();
            $data['count'] = 0;
            $data['type'] = 1;
            $data['sign'] = $this->sign($info, $data);

            $header = array('Content-Type:application/json');
            $response = json_decode($this->vendor_send->send($this->action, $url, 'POST', $data, $header), true);
            if (is_array($response) && $response['code'] == 200) {
                return [
                    'code' => 1000,
                    'message' => $response['msg'],
                ];
            } else {
                return [
                    'code' => 9996,
                    'message' => $response['msg']
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

    private function sign($info, $data)
    {
        ksort($data);
        $str = http_build_query($data) . '&key=' . $info->KEY;
        return md5($str);
    }
}
