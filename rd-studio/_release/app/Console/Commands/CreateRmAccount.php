<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Helpers\VendorSend;
use App\Helpers\Common;
use App\Helpers\VendorPost;
use App\Models\VendorInfo;
use App\Models\RmAccount;
use DB;

class CreateRmAccount extends Command
{

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'create_rm_account';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '預先建立RedMario帳號';

    protected $vendor_send;
    protected $vendor_id = 32;
    protected $name = 'Mario club';
    protected $action = 'rm_create_accounts';
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
                        $count = RmAccount::where('member_id', 0)
                            ->where('vendor_info_id', $row->id)
                            ->count();

                        if ($count < $row->prepared_account_number) {
                            //帳號前綴
                            $prefix = $row->account_prefix;

                            // 取目前帳號最大值
                            $max = RmAccount::where('username', 'like', $prefix . '%')
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
        for ($i = 1; $i <= $number; $i++) {
            //預防帳號創太久，超過30組就下次再創
            if($i > 30){
                break;
            }
            // 帳號規則，前綴加上數字，總長度不超過13個位元
            $username = $prefix . str_pad(($start + $i), $this->account_length, '0', STR_PAD_LEFT);
            $row = array(
                'vendor_info_id' => $info_id,
                'username' => $username,
                'admin' => 1
            );
            $account = RmAccount::create($row);
            // 密碼規則,6到12碼
            $password = [REDACTED] . strtotime($account->created_at)), 8, 12);
            // 在遊戲商新建帳號
            $result = $this->createVendorAccount($info, $username);
            // 如果失敗就將這筆記錄member_id改為-999999999
            if ($result['code'] != 1000 || !is_array($result)) {
                $account->update(['member_id' => -999999999]);
                $fail++;
                echo '!!!!!' . $username . '!!!!!' . $result['message'] . PHP_EOL;
            } else {
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
            $url = 'api/acc/created';
            $time = date('U');
            $data = array();
            $data['api_id'] = $info->API_ID;
            $data['timestamp'] = $time;
            $data['sign'] = $this->sign($info, $time);
            $data['user_id'] = $username;
            $url = $info->API_URL . $url;
            $header = array('Content-Type:application/x-www-form-urlencoded');
            $response = $this->vendor_send->send($this->action, $url, 'POST', $data, $header);
            $result = json_decode($response, true);
            if ($result['code'] == '0') {
                return [
                    'code' => 1000,
                    'message' => $result['msg']
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

    private function sign($info, $time)
    {
        return md5(($info->API_ID.$time.$info->API_KEY));
    }
}
