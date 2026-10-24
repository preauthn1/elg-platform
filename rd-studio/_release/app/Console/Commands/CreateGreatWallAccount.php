<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Helpers\VendorSend;
use App\Helpers\Common;
use App\Helpers\VendorPost;
use App\Models\VendorInfo;
use App\Models\GreatWallAccount;
use DB;

class CreateGreatWallAccount extends Command
{

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'create_great_wall_account';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '預先建立GW99帳號';
    protected $vendor_send;
    protected $vendor_id = 5;
    protected $name = 'GreatWall';
    protected $action = 'great_wall_create_accounts';

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

            foreach ($settings as $row) {
                //將遊戲商資訊解密
                $info = Common::decryptVendorInfo($row);
                if (is_object($info)) {
                    $prefix = $row->account_prefix; //帳號前綴
                    // 取目前剩多少未綁定帳號
                    $count = GreatWallAccount::where('member_id', 0)->where('vendor_info_id', $row->id)->count();
                    if ($count < $row->prepared_account_number) {
                        // 取目前帳號最大值
                        $max = GreatWallAccount::where('username', 'like', $prefix . '%')
                            ->where('vendor_info_id', $row->id)
                            ->orderBy('username', 'DESC')
                            ->pluck('username')
                            ->first();
                        $max = substr($max, -5) ? substr($max, -5) : 0;
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
            $username = $prefix . str_pad(($start + $i), 5, '0', STR_PAD_LEFT);
            $row = array(
                'vendor_info_id' => $infoId,
                'username' => $username,
                'admin' => 1
            );
            $account = GreatWallAccount::create($row);
            // 密碼規則,6到12碼
            $password = [REDACTED] . strtotime($account->created_at)), 8, 12);
            // 在遊戲商新建帳號
            $result = $this->createVendorAccount($info, $username, $password);
            // 如果失敗就刪除這筆記錄
            if ($result['code'] != 1000 || !is_array($result)) {
                $account->update(['member_id' => -999999999]);
                $fail++;
                echo '!!!!!' . $username . '!!!!!' . PHP_EOL;
            } else {
                echo '*****' . $username . '*****' . PHP_EOL;
            }
            // API經過測試，一秒CALL多次API會返回"Invalid signature"
        }
        return $fail;
    }

    private function createVendorAccount($info, $username, $password)
    {
        try {
            // New players
            $url = 'Api/IGame/Player/Create';
            $data = array();
            $data['PlayerAccount'] = $username;
            $data['Password'] = $password;
            $data['Agent'] = $info->API_AGENT;
            ksort($data);
            $url = $info->API_URL . $url;
            $header = array(
                'merchantName:' . $info->API_MERCHANT_NAME,
                'signature:' . $this->GreatWallSign($data, $info->API_KEY),
                'Content-Type:application/json'
            );
            $result = json_decode($this->vendor_send->send($this->action, $url, 'POST', $data, $header), true);
            if ($result['result']['code'] === '0') {
                return [
                    'code' => 1000,
                    'message' => $result['result']['message']
                ];
            } else {
                return [
                    'code' => 9996,
                    'message' => $result
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

    private function GreatWallSign($data, $apiKey)
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
