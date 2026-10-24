<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Helpers\Common;
use App\Models\CokeFightingAccount;
use DB;

class CreateCokeFightingAccount extends Command
{

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'create_coke_fighting_account';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '預先建立鬥雞帳號';

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
        // 取目前剩多少未綁定帳號
        $count = CokeFightingAccount::where('member_id', 0)->count();
        if ($count < 500) {
            // 取目前帳號最大值
            $max = CokeFightingAccount::where('username', 'like', env('VENDOR_ACCOUNT_PREFIX') . '00%')->orderBy('username', 'DESC')->pluck('username')->first();
            $max = substr($max, -8) ? substr($max, -8) : 0;
            // 建立帳號補齊100個
            $number = (500 - $count);
            if ($fail = $this->createAccount($max, $number)) {
                // 如果有失敗的就再加開那個數量，這樣下次排程執行時就不會卡在失敗那筆，可以重新補齊
                $this->createAccount(($max + $number), $fail);
            }
        }
    }

    private function createAccount($start, $number)
    {
        $fail = 0;
        for ($i = 1; $i <= $number; $i++) {
            // 帳號規則，前綴加上8碼數字，總長度不超過13個位元
            $username =  env('VENDOR_ACCOUNT_PREFIX') . str_pad(($start + $i), 8, '0', STR_PAD_LEFT);
            $row = array(
                'username' => $username,
                'admin' => 1
            );
            $account = CokeFightingAccount::create($row);
            // 密碼規則,6到12碼
            $password = [REDACTED] . strtotime($account->created_at)), 8, 12);
            // 在遊戲商新建帳號
            $result = $this->createVendorAccount($username, $password);
            // 如果失敗就刪除這筆記錄
            if ($result != false) {
                echo '*****' . $username . '*****';
            } else {
                $account->update(['member_id' => -999999999]);
                $fail++;
                echo '!!!!!' . $username . '!!!!!';
            }
        }
        return $fail;
    }


    private function createVendorAccount($username, $password)
    {
        try {
            $url = 'api/player/' . env('COKE_FIGHTING_SITE') . '/createMember';
            // New players
            $data = array();
            $data['user'] = $username;
            $data['currency'] = env('COKE_FIGHTING_CURRENCY');
            $data['maxbet'] = 1000;
            $data['minbet'] = 5;
            $data['matchlimit'] = 1000;
            $data['mindraw'] = 5;
            $data['maxdraw'] = 1000;
            $result = $this->post($data, $url);
            if ($result['status'] == 1) {
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


    private function post($data, $url)
    {
        $data['cert'] = env('COKE_FIGHTING_CERT');
        $data['agent'] = env('COKE_FIGHTING_AGENT');
        Common::log(0, env('COKE_FIGHTING_URL') . $url, 'create_coke_fighting_account');
        Common::log(0, $data, 'create_coke_fighting_account');
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, env('COKE_FIGHTING_URL') . $url);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        curl_setopt($ch, CURLOPT_POST, TRUE);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        $result = curl_exec($ch);

        curl_close($ch);
        Common::log(0, $result, 'create_coke_fighting_account');
        return json_decode($result, true);
    }
}