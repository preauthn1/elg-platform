<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Helpers\Common;
use App\Helpers\VendorSend;
use App\Models\VendorInfo;
use App\Models\N918Account;

class CreateN918Account extends Command
{

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'create_n918_account';

    /**
     * The console command description
     *
     * @var string
     */
    protected $description = '預先建立n918帳號';

    protected $vendor_send;
    protected $vendor_id = 33;
    protected $name = 'N918';
    protected $action = 'n918_create_accounts';

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
            $settings = VendorInfo::where('vendor_id', $this->vendor_id)->where('status', 1)->get();
            foreach ($settings as $row) {
                //將遊戲商資訊解密
                $info = Common::decryptVendorInfo($row);
                if (is_object($info)) {
                    $prefix = $row->account_prefix; //帳號前綴
                    // 取目前剩多少未綁定帳號
                    $count = N918Account::where('member_id', 0)->where('vendor_info_id', $row->id)->count();
                    if ($count < $row->prepared_account_number) {
                        // 取目前帳號最大值
                        $max = N918Account::where('username', 'like', $prefix . '%')
                            ->where('vendor_info_id', $row->id)
                            ->orderBy('username', 'DESC')
                            ->pluck('username')
                            ->first();
                        $max = substr($max, -8) ? substr($max, -8) : 0;
                        // 建立帳號補齊備用數量
                        $number = ($row->prepared_account_number - $count);
                        echo '補齊備用數量:' . $number . PHP_EOL;
                        $this->createAccount($info, $prefix, $max, $number, $row->id);
                    } else {
                        echo 'Account is enough';
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
            //預防帳號創太久，超過30組就下次再創
            if($i > 30){
                break;
            }
            // 帳號規則，前綴加上8碼數字，總長度不超過13個位元
            $username = $prefix . str_pad(($start + $i), 8, '0', STR_PAD_LEFT);
            $randomUserName = $this->getRandomUserName($info->API_URL, $info->AGENT_ID, $info->AUTH_CODE, $info->SECRET_KEY);
            $row = array(
                'vendor_info_id' => $infoId,
                'username' => $username,
                'player_id' => $randomUserName,
                'admin' => 1,
            );

            if ($randomUserName != false) {
                $account = N918Account::create($row);
                // 在遊戲商新建帳號
                $result = $this->createVendorAccount($info, $username, $randomUserName);
                // 如果失敗就不使用這筆記錄
                if (is_array($result) && $result['backData']['code'] == 0) {
                    echo '*****' . $username . '*****' . PHP_EOL;
                } else {
                    // 如果失敗就刪除這筆記錄
                    $account->update(['member_id' => -999999999]);
                    $fail++;
                    echo '!!!!!' . $username . '!!!!!' . PHP_EOL;
                }
            }
            // API限制1秒只能呼叫一次
            sleep(1);
        }
        return $fail;
    }

    /**
     * -建新帳號
     */
    private function createVendorAccount($info, $username, $randomUserName)
    {
        try {
            $url = $info->API_URL;
            $authCode = $info->AUTH_CODE;
            $secretKey = $info->SECRET_KEY;
            $time = date('U') . '000';
            $sign = $this->getSign($authCode . $info->AGENT_ID . $time . $secretKey);

            $data = array(
                'action' => 'addUser',
                'PassWd' => 'Aa123466',
                'pwdtype' => '1',
                'userName' => $randomUserName,
                'Name' => $username,
                'Tel' => '0912345678',
                'Memo' => 'N/A',
                'UserType' => '1',
                'time' => $time,
                'authcode' => $authCode,
                'sign' => $sign,
            );
            $backData = $this->vendor_send->send($this->action, $url, 'GET', $data);
            $backData = json_decode($backData, true);
            $result = array(
                'player_id' => $randomUserName,
                'backData' => $backData,
            );
            return $result;
        } catch (\Illuminate\Database\QueryException $e) {
            $info = $e->errorInfo;
            echo $info[0] . ' : ' . $info[2];
        } catch (\Exception $e) {
            echo $e->getCode() . ' : ' . $e->getMessage();
        }
    }

    private function getRandomUserName($url, $agent, $authCode, $secretKey)
    {
        $time = time() . '000';
        $sign = $this->getsign($authCode . $agent . $time . $secretKey);
        $data = array(
            'action' => 'RandomUserName',
            'userName' => $agent,
            'time' => $time,
            'authcode' => $authCode,
            'sign' => $sign,
        );
        $result = $this->vendor_send->send('n918_get_RandomUserName', $url, 'GET', $data);
        $result = json_decode($result, true);
        if (is_array($result) && $result['success'] == true) {
            return $result['account'];
        }
        return false;
    }

    private function getSign($string)
    {
        return strtoupper(md5(strtolower($string)));
    }
}
