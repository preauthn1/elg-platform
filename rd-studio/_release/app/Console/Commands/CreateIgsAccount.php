<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Helpers\Common;
use App\Helpers\VendorSend;
use App\Models\IgsAccount;
use App\Models\VendorInfo;

class CreateIgsAccount extends Command
{

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'create_igs_account';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '預先建立igs帳號';

    protected $vendor_send;
    protected $vendor_id = 26;
    protected $name = 'IGS';
    protected $action = 'igs_create_accounts';

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
        }
        $settings = VendorInfo::where('vendor_id', $this->vendor_id)->where('status', 1)->get();
        foreach ($settings as $row) {
            //將遊戲商資訊解密
            $info = Common::decryptVendorInfo($row);
            if (is_object($info)) {
                //帳號前綴
                $prefix = $row->account_prefix;
                // 取目前剩多少未綁定帳號
                $count = IgsAccount::where('member_id', 0)->where('vendor_info_id', $row->id)->count();
                if ($count < $row->prepared_account_number) {
                    // 取目前帳號最大值
                    $max = IgsAccount::where('username', 'like', $prefix . '%')
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
            $account = IgsAccount::create($row);
            $password = [REDACTED] . strtotime($account->created_at)), 8, 12);
            // 在MT新建帳號
            $result = $this->createVendorAccount($info, $username, $password);
            if (is_array($result) && $result['code'] == 1000) {
                $account->update(['player_id' => $result['player_id'], 'password' => $password]);
                echo '*****' . $username . '*****' . PHP_EOL;
            } else {
                $account->update(['member_id' => -999999999]);
                $fail++;
                echo '!!!!!' . $username . '!!!!!' . PHP_EOL;
            }
        }
        return $fail;
    }

    private function createVendorAccount($info, $username, $password)
    {
        try {
            // New players
            $url = $info->API_URL . 'openapi/site/created-user';
            $pwd = [REDACTED] . md5($password));
            $time = time();
            $sign = md5(md5('app_id'.$info->APP_ID . 'password' . $pwd . 'ts' . $time . 'username' . $username . $info->APP_ID));
            $data = array(
                'username' => $username,
                'password' => $pwd,
                'ts' => $time,
                'app_id' => $info->APP_ID,
                'sign' => $sign
            );
            $headers = array('Authorization:' . $this->getToken($info));
            $result = json_decode($this->vendor_send->send($this->action, $url, 'POST', $data, $headers), true);
            if (is_array($result) && $result['msg'] == 'success') {
                return [
                    'code' => 1000,
                    'player_id' => $result['data']['user_id'],
                ];
            } else {
                echo 123;
                return [
                    'code' => 9996,
                    'message' => $result,
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

    private function getToken($info)
    {
        $url = $info->API_URL . 'openapi/oauth/access-token';
        $time = time();
        $sign = md5(md5('app_id'.$info->APP_ID. 'app_key' . $info->APP_KEY . 'ts' . $time . $info->APP_ID));
        $data = array(
            'app_id' => $info->APP_ID,
            'app_key' => $info->APP_KEY,
            'ts' => $time,
            'sign' => $sign,
        );
        $result = json_decode($this->vendor_send->send('igs_get_token', $url, 'POST', $data), true);
        return $result['data']['access_token'];
    }
}
