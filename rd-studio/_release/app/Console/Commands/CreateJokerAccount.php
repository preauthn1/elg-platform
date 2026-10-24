<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Helpers\Common;
use App\Helpers\VendorSend;
use App\Models\VendorInfo;
use App\Models\JokerAccount;
use DB;

class CreateJokerAccount extends Command
{

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'create_joker_account';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '預先建立Joker帳號';
    protected $vendor_send;
    protected $vendor_id = 2;
    protected $name = 'Joker';
    protected $action = 'joker_create_accounts';

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
            $setting = VendorInfo::where('vendor_id', $this->vendor_id)->where('status', 1)->get();
            foreach ($setting as $row) {
                echo $row->name . PHP_EOL;
                $info = json_decode($row->info);
                if (is_object($info)) {
                    $vendor_info_id = $row->id;
                    $prefix = $row->account_prefix;
                    // 取目前剩多少未綁定帳號
                    $count = JokerAccount::where('vendor_info_id', $vendor_info_id)
                        ->where('member_id', 0)
                        ->count();
                    if ($count < $row->prepared_account_number) {
                        // 取目前帳號最大值
                        $max = JokerAccount::where('vendor_info_id', $vendor_info_id)
                            ->where('username', 'like', $prefix . '00%')
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
            $username = $prefix . str_pad(($start + $i), 8, '0', STR_PAD_LEFT);
            $row = array(
                'vendor_info_id' => $vendor_info_id,
                'username' => $username,
                'admin' => 1
            );
            $account = JokerAccount::create($row);
            // 在遊戲商新建帳號
            $result = $this->createVendorAccount($username, $info);
            if (is_array($result) && isset($result['code']) && $result['code'] == 1000) {
                echo '*****' . $username . '*****' . PHP_EOL;
            } else {
                $account->update(['member_id' => -999999999]);
                $fail++;
                echo '!!!!!' . $username . '!!!!!' . PHP_EOL;
            }
            // API經過測試，一秒CALL多次API會返回"Invalid signature"
            sleep(1);
        }
        return $fail;
    }

    private function createVendorAccount($username, $info)
    {
        try {
            // Ensure user account
            $data = array();
            $data['Method'] = 'CU';
            $data['Timestamp'] = date('U');
            $data['Username'] = $username;
            ksort($data);
            $url = $info->API_URL . '?AppID=' . $info->APP_ID . '&Signature=' . $this->getSign($data, $info);
            $response = $this->vendor_send->send($this->action, $url, 'POST', $data);
            $result = json_decode($response, true);
            if (isset($result['Status']) && $result['Status'] == 'Created' && isset($result['Data'])) {
                return [
                    'code' => 1000,
                    'message' => $result['Data']
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

    /**
     * -取簽名
     */
    private function getSign($data, $info)
    {
        $string = http_build_query($data);
        $hash_hmac = hash_hmac("sha1", $string, $info->SECRET, true);
        return base64_encode($hash_hmac);
    }
}
