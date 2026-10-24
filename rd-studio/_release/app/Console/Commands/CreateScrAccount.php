<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Helpers\VendorSend;
use App\Helpers\Common;
use App\Models\ScrAccount;
use App\Models\VendorInfo;
use DB;

class CreateScrAccount extends Command
{

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'create_scr_account';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '預先建立918帳號';

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
            echo $this->name . ' vendor maintenances' . PHP_EOL;
        } else {
            $settings = VendorInfo::where('vendor_id', $this->vendor_id)->where('status', 1)->get();
            foreach ($settings as $row) {
                echo $row->name . PHP_EOL;
                $info = json_decode($row->info);
                if (is_object($info)) {
                    $vendor_info_id = $row->id;
                    $prefix = $row->account_prefix;
                    // 取目前剩多少未綁定帳號
                    $count = ScrAccount::where('vendor_info_id', $vendor_info_id)
                        ->where('member_id', 0)
                        ->count();
                    if ($count < $row->prepared_account_number) {
                        // 取目前帳號最大值
                        $max = ScrAccount::where('vendor_info_id', $vendor_info_id)
                            ->where('username', 'like', $prefix . '00%')
                            ->orderBy('username', 'DESC')
                            ->pluck('username')
                            ->first();
                        $max = substr($max, -8) ? substr($max, -8) : 0;
                        // 建立帳號補齊100個
                        $number = ($row->prepared_account_number - $count);
                        $this->createAccount($prefix, $max, $number);
                    } else {
                        echo 'Account is enough' . PHP_EOL;
                    }
                } else {
                    echo $row->name . '解密失敗' . PHP_EOL;
                }
            }
        }
    }

    private function createAccount($prefix, $start, $number)
    {
        $fail = 0;
        for ($i = 1; $i <= $number; $i++) {
            // 帳號規則，前綴加上8碼數字，總長度不超過13個位元
            $username = $prefix . str_pad(($start + $i), 8, '0', STR_PAD_LEFT);
            $row = array(
                'username' => $username,
                'admin' => 1
            );
            $account = ScrAccount::create($row);
            // 密碼規則,6到12碼
            $password = [REDACTED] . strtotime($account->created_at)), 8, 12);
            // 在遊戲商新建帳號
            $result = $this->createVendorAccount($username, $password);
            // 如果失敗就刪除這筆記錄
            if ($result['returncode'] != '0' || !is_array($result)) {
                $account->update(['member_id' => -999999999]);
                $fail++;
                echo '!!!!!' . $username . '!!!!!' . PHP_EOL;
            } else {
                ScrAccount::where('username', $username)->update([
                    'player_id' => $result['playerid']
                ]);
                echo '*****' . $username . '*****' . PHP_EOL;
            }
            // API限制15秒只能呼叫一次
            sleep(15);
        }
        return $fail;
    }

    /**
     * -建新帳號
     */
    private function createVendorAccount($username, $password)
    {
        try {
            $url = 'player';
            $data = array();
            $data['operation'] = 'addnewplayer';
            $data['playername'] = $username;
            $data['playerpassword'] = 'A' . $password;
            $data['playertelno'] = '0978123456';
            $data['playerdescription'] = 'QA';
            // 要保存PLAYER ID
            return $this->post($url, $data);
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

    private function post($url, $data)
    {
        Common::log(0, $data, $this->action);
        $data['apiuserid'] = env('SCR_API_USER');
        $data['apipassword'] = env('SCR_API_PASSWORD');
        $data_string = json_encode($data);
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, env('SCR_URL') . $url);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data_string);
        curl_setopt($ch, CURLOPT_POST, TRUE);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
        // curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Content-Type: application/json',
            'Content-Length: ' . strlen($data_string)
        ));
        $result = curl_exec($ch);
        curl_close($ch);
        Common::log(0, $result, $this->action);
        return json_decode($result, true);
    }
}
