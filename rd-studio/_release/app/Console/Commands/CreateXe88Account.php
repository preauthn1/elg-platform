<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Helpers\VendorSend;
use App\Helpers\Common;
use App\Models\VendorInfo;
use App\Models\Xe88Account;
use DB;

use function GuzzleHttp\json_encode;

class CreateXe88Account extends Command
{

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'create_xe88_account';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '預先建立xe88帳號';

    protected $vendor_send;
    protected $vendor_id = 11;
    protected $name = 'XE88';
    protected $action = 'xe88_create_account';
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
            $settings = VendorInfo::where('vendor_id', $this->vendor_id)->where('status', 1)->get();
            foreach ($settings as $row) {
                echo $row->name . PHP_EOL;
                //將遊戲商資訊解密
                $info = json_decode($row->info);
                if (is_object($info)) {
                    $prefix = $row->account_prefix;
                    $vendor_info_id = $row->id;
                    // 取目前剩多少未綁定帳號
                    $count = Xe88Account::where('vendor_info_id', $vendor_info_id)
                        ->where('member_id', 0)
                        ->count();
                    if ($count < $row->prepared_account_number) {
                        // 取目前帳號最大值
                        $max = Xe88Account::where('vendor_info_id', $vendor_info_id)
                            ->where('username', 'like', $prefix . '%')
                            ->orderBy('username', 'DESC')
                            ->pluck('username')
                            ->first();
                        $max = substr($max, -8) ? substr($max, -8) : 0;
                        // 建立帳號補齊100個
                        $number = ($row->prepared_account_number - $count);
                        $this->createAccount($prefix, $max, $number, $info, $vendor_info_id);
                    } else {
                        echo 'Account is enough' . PHP_EOL;
                    }
                } else {
                    echo $row->name . '解密失敗' . PHP_EOL;
                }
            }
        }
    }

    private function createAccount($prefix, $start, $number, $info, $vendor_info_id)
    {
        $fail = 0;
        for ($i = 1; $i <= $number; $i++) {
            // 帳號規則，前綴加上8碼數字，總長度不超過13個位元
            $username =  $prefix . str_pad(($start + $i), 8, '0', STR_PAD_LEFT);
            $row = array(
                'vendor_info_id' => $vendor_info_id,
                'username' => $username,
                'admin' => 1
            );
            $result = Xe88Account::create($row);
            // 密碼規則,6到12碼
            $password = [REDACTED] . strtotime($result->created_at)), 8, 12);
            // 在遊戲商新建帳號
            $result = $this->createVendorAccount($username, $password, $info);
            // 如果失敗就刪除這筆記錄
            if ($result == true) {
                echo '*****' . $username . '*****' . PHP_EOL;
            } else {
                Xe88Account::where('username', $username)->delete();
                $fail++;
                echo '!!!!!' . $username . '!!!!!' . PHP_EOL;
            }
        }
        return 'Fail count:' . $fail . PHP_EOL;
    }


    private function createVendorAccount($username, $password, $info)
    {
        try {
            $url = $info->API_URL . 'player/create';
            // New players
            $data = array();
            $data['agentid'] = $info->AGENT_ID;
            $data['account'] = $username;
            $data['password'] = $password;

            $requestbody = json_encode($data);
            $hashdata = hash_hmac("sha256", $requestbody, $info->SIGNATURE_KEY, true);
            $hash = base64_encode($hashdata);
            $response = $this->vendor_send->send($this->action, $url, 'POST', $data, ['hashkey: ' . $hash]);
            $result = json_decode($response, true);
            if (is_array($result) && isset($result['code']) && $result['code'] == '0') {
                return true;
            } else {
                return false;
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
}
