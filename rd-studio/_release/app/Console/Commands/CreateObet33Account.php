<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Helpers\VendorSend;
use App\Helpers\Common;
use App\Models\VendorInfo;
use App\Models\Obet33Account;
use DB;

class CreateObet33Account extends Command
{

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'create_obet33_account';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '預先建立Obet33帳號';

    protected $vendor_send;
    protected $vendor_id = 43;
    protected $name = 'Obet33';
    protected $action = 'obet33_create_accounts';
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
            $settings = VendorInfo::where('vendor_id', $this->vendor_id)
                ->where('status', 1)
                ->get();

            if (is_object($settings)) {
                foreach ($settings as $row) {
                    //將遊戲商資訊解密
                    $info = Common::decryptVendorInfo($row);
                    if (is_object($info)) {
                        // 取目前剩多少未綁定帳號
                        $count = Obet33Account::where('member_id', 0)
                            ->where('vendor_info_id', $row->id)
                            ->count();

                        if ($count < $row->prepared_account_number) {
                            //帳號前綴
                            $prefix = $row->account_prefix;

                            // 取目前帳號最大值
                            $max = Obet33Account::where('username', 'like', $prefix . '%')
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
            // 帳號規則，前綴加上數字，總長度不超過13個位元
            $username = $prefix . str_pad(($start + $i), $this->account_length, '0', STR_PAD_LEFT);
            $row = array(
                'vendor_info_id' => $info_id,
                'username' => $username,
                'admin' => 1
            );
            $account = Obet33Account::create($row);
            // 在遊戲商新建帳號
            $result = $this->createVendorAccount($info, $username);
            // 如果失敗就將這筆記錄member_id改為-999999999
            if ($result['code'] != 1000 || !is_array($result)) {
                $account->update(['member_id' => -999999999]);
                $fail++;
                echo '!!!!!' . $username . '!!!!!' . PHP_EOL;
                echo 'API_Error_Code : ' . $result['message'] . PHP_EOL;
            } else {
                $account->update(['username_modified' => $result['username_modified']]);
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
            $url = $info->API_URL . '/api/sb/register';
            //遊戲商創帳號一定要加AgentId當前綴
            $username = $info->AGENT_ID . $username;
            $data = array(
                'hash' => $this->hash($info, $username),
                'agentId' => $info->AGENT_ID,
                'userId' => $username
            );

            $header = array('Content-Type:application/json');

            $response = $this->vendor_send->send($this->action, $url, 'POST', $data, $header);
            $result = json_decode($response, true);

            if (is_array($result) && $result['Status'] == 'success') {
                //username_modified,因為注單回傳的username都是大寫
                return [
                    'code' => 1000,
                    'username_modified' => strtoupper($username)
                ];
            } else {
                return [
                    'code' => 9996,
                    'message' => $result['message']
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

    private function hash($info, $username)
    {
        $str = "k=$info->API_KEY&a=$info->AGENT_ID&u=$username";
        //加密要回傳大寫
        return strtoupper(md5($str));
    }
}