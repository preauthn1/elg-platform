<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Helpers\Common;
use App\Helpers\VendorSend;
use App\Models\VendorInfo;
use App\Models\Pussy888Account;

class CreatePussy888Account extends Command
{

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'create_pussy888_account';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '預先建立Pussy888帳號';

    protected $vendor_send;
    protected $vendor_id = 23;
    protected $name = 'PUSSY';
    protected $action = 'pussy888_create_accounts';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
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
                    $count = Pussy888Account::where('member_id', 0)->where('vendor_info_id', $row->id)->count();
                    if ($count < $row->prepared_account_number) {
                        // 取目前帳號最大值
                        $max = Pussy888Account::where('username', 'like', $prefix . '%')
                            ->where('vendor_info_id', $row->id)
                            ->orderBy('username', 'DESC')
                            ->pluck('username')
                            ->first();
                        $max = substr($max, -8) ? substr($max, -8) : 0;
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
            $username = $prefix . str_pad(($start + $i), 8, '0', STR_PAD_LEFT);
            $row = array(
                'vendor_info_id' => $infoId,
                'username' => $username,
                'admin' => 1,
            );
            $account = Pussy888Account::create($row);
            // 在遊戲商新建帳號
            $result = $this->createVendorAccount($info, $username);
            // 如果失敗就不使用這筆記錄
            if (is_array($result) && $result['backdata']['success'] == true) {
                $account->update(['player_id' => $result['player_id']]);
                echo '*****' . $username . '*****' . PHP_EOL;
            } else {
                // 如果失敗就刪除這筆記錄
                $account->update(['member_id' => -999999999]);
                $fail++;
                echo '!!!!!' . $username . '!!!!!' . PHP_EOL;
            }
        }
        return $fail;
    }

    /**
     * -建新帳號
     */
    private function createVendorAccount($info, $username)
    {
        try {
            $url = $info->API_URL1;
            $url .= 'account/account.ashx';
            $authcode = $info->API_AUTHCODE;
            $secretKey = $info->API_SECRETKEY;
            $time = time();
            $player_id = $this->getRandomUserName($url, $info->AGENT_NAME, $authcode, $secretKey);
            $sign = $this->getsign($authcode, $player_id, $time, $secretKey);
            
            $data = array(
                'action' => 'addUser',
                'agent' => $info->AGENT_NAME,
                'PassWd' => 'Aa123466',
                'pwdtype' => '1',
                'userName' => $player_id,
                'Name' => $username,
                'Tel' => '0912345678',
                'Memo' => 'N/A',
                'UserType' => '1',
                'time' => $time,
                'authcode' => $authcode,
                'sign' => $sign,
            );
            $vendorsend = new VendorSend;
            $backdata = $vendorsend->send($this->action, $url, 'GET', $data);
            $backdata = json_decode($backdata, true);
            $result = array(
                'player_id' => $player_id,
                'backdata' => $backdata,
            );
            return $result;
        } catch (\Illuminate\Database\QueryException $e) {
            $info = $e->errorInfo;
            return response()->json([
                'code' => $info[0],
                'message' => $info[2],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'code' => $e->getCode(),
                'message' => $e->getMessage(),
            ]);
        }
    }

    private function getRandomUserName($url, $agent, $authcode, $secretKey)
    {
        $time = time() . '000';
        $username = $agent;
        $sign = $this->getsign($authcode, $username, $time, $secretKey);
        $data = array(
            'action' => 'RandomUserName',
            'userName' => $username,
            'UserAreaId' => '0',
            'time' => $time,
            'authcode' => $authcode,
            'sign' => $sign,
        );
        $vendorsend = new VendorSend;
        $result = $vendorsend->send('pussy888_get_RandomUserName', $url, 'GET', $data);
        $result = json_decode($result, true);
        if (is_array($result) && $result['success'] == true) {
            return $result['account'];
        }
    }

    private function getsign($authcode, $username, $time, $secretKey)
    {
        $sign = "";
        $sign = "$authcode$username$time$secretKey";
        $sign = strtolower($sign);
        return strtoupper(md5($sign));
    }
}
