<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Helpers\VendorSend;
use App\Helpers\Common;
use App\Models\ScrAccount;
use App\Models\VendorInfo;
use DB;

class CreateNewScrAccount extends Command
{

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'create_new_scr_account';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '預先建立新918帳號';
    protected $vendor_send;
    protected $vendor_id = 3;
    protected $name = 'SCR';
    protected $action = 'new_scr_create_account';

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
            echo 'New SCR vendor maintenances' . PHP_EOL;
        } else {
            $settings = VendorInfo::where('vendor_id', $this->vendor_id)->where('status', 1)->get();

            foreach ($settings as $row) {
                echo $row->name . PHP_EOL;
                $prefix = $row->account_prefix; //帳號前綴
                // 取目前剩多少未綁定帳號
                $count = ScrAccount::where('member_id', 0)->where('vendor_info_id', $row->id)->count();
                if ($count < $row->prepared_account_number) {
                    //將遊戲商資訊解密
                    $info = Common::decryptVendorInfo($row);
                    if (is_object($info)) {
                        // 取目前帳號最大值
                        $max = ScrAccount::where('username', 'like', $prefix . '%')
                            ->where('vendor_info_id', $row->id)
                            ->orderBy('username', 'DESC')
                            ->pluck('username')
                            ->first();
                        $max = substr($max, -8) ? substr($max, -8) : 0;
                        // 建立帳號補齊備用數量
                        $number = ($row->prepared_account_number - $count);
                        $this->createAccount($info, $prefix, ($max + 1), $number, $row->id);
                    } else {
                        echo $row->name . '解密失敗';
                    }
                } else {
                    echo 'Account is enough' . PHP_EOL;
                }
            }
        }
    }

    private function createAccount($info, $prefix, $start, $number, $infoId)
    {
        $fail = 0;
        for ($i = 1; $i <= $number; $i++) {
            //取玩家編號
            $player_id = $this->getPlayerId($info);
            if ($player_id) {
                // 帳號規則，前綴加上8碼數字，總長度不超過13個位元
                $username = $prefix . str_pad(($start + $i), 8, '0', STR_PAD_LEFT);
                $row = array(
                    'vendor_info_id' => $infoId,
                    'username' => $username,
                    'player_id' => $player_id,
                    'admin' => 1
                );
                $account = ScrAccount::create($row);
                // 密碼規則,6到12碼
                $password = [REDACTED]' . substr(md5($username . strtotime($account->created_at)), 8, 12);
                // 在遊戲商新建帳號
                $result = $this->createVendorAccount($info, $username, $password, $player_id);
                // 如果失敗就不使用這筆記錄
                if (!is_array($result) || !$result['success']) {
                    $account->update(['member_id' => -999999999]);
                    $fail++;
                    echo '!!!!!' . $username . '!!!!!' . PHP_EOL;
                } else {
                    $account->update(['password' => $password]);
                    echo '*****' . $username . '*****' . PHP_EOL;
                }
            }
        }
        return $fail;
    }

    private function getPlayerId($info)
    {
        sleep(1);
        $url = $info->HOST . 'account/account.ashx';
        $data = array();
        $data['action'] = 'RandomUserName';
        $data['userName'] = $info->AGENT_NAME;
        $data['UserAreaId'] = $info->USER_AREA_ID;
        $data['time'] = date('U') . '000';
        $data['authcode'] = $info->AUTH_CODE;
        $data['sign'] = $this->sign($data['authcode'] . $data['userName'] . $data['time'] . $info->SECRET_KEY);
        $response = $this->vendor_send->send('new_scr_get_player_id', $url, 'POST', $data);
        $result = json_decode($response, true);
        //如果拿到PLAYER ID，才能建立帳號
        if (is_array($result) && $result['code'] == 0 && isset($result['account'])) {
            return  $result['account'];
        } else {
            echo 'get player id fail' . PHP_EOL;
            return false;
        }
    }

    /**
     * -建新帳號
     */
    private function createVendorAccount($info, $username, $password, $playerId)
    {
        try {
            $url = $info->HOST . 'account/account.ashx';
            $data = array();
            $data['action'] = 'addUser';
            $data['agent'] = $info->AGENT_NAME;
            $data['PassWd'] = $password;
            $data['pwdtype'] = '1';
            $data['userName'] = $playerId;
            $data['Name'] = $username;
            $data['Tel'] = '000000';
            $data['Memo'] = $username;
            $data['UserAreaId'] = $info->USER_AREA_ID;
            $data['UserType'] = '1';
            $data['time'] = date('U') . '000';
            $data['authcode'] = $info->AUTH_CODE;
            $data['sign'] = $this->sign($data['authcode'] . $data['userName'] . $data['time'] . $info->SECRET_KEY);
            $response = $this->vendor_send->send($this->action, $url, 'POST', $data);
            return json_decode($response, true);
        } catch (\Illuminate\Database\QueryException $e) {
            $info = $e->errorInfo;
            return response()->json([
                'code' => $info[0],
                'message' => $info[2]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'code' => $e->getCode(),
                'message' => $e->getMessage()
            ]);
        }
    }

    private function sign($string)
    {
        return  strtoupper(md5(strtolower($string)));
    }
}
