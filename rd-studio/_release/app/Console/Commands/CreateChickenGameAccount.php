<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Helpers\Common;
use App\Models\ChickenGameAccount;
use SoapClient;
use DB;

class CreateChickenGameAccount extends Command
{

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'create_chicken_game_account';

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
        $count = ChickenGameAccount::where('member_id', 0)->count();
        if ($count < 500) {
            // 取目前帳號最大值
            $max = ChickenGameAccount::where('username', 'like', env('VENDOR_ACCOUNT_PREFIX') . '00%')->orderBy('username', 'DESC')->pluck('username')->first();
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
            $result = ChickenGameAccount::create($row);
            // 密碼規則,6到12碼
            $password = [REDACTED] . strtotime($result->created_at)), 8, 12);
            // 在遊戲商新建帳號
            $result = $this->createVendorAccount($username, $password);
            // 如果失敗就刪除這筆記錄
            if ($result != false) {
                echo '*****' . $username . '*****';
            } else {
                ChickenGameAccount::where('username', $username)->delete();
                $fail++;
                echo '!!!!!' . $username . '!!!!!';
            }
        }
        return $fail;
    }


    private function createVendorAccount($username, $password)
    {
        try {
            // New players
            $data = array();
            $data['strUsername'] = $username;
            $data['strPassword'] = $password;
            $data['strAgent'] = env('CHICKEN_GAME_AGENT');
            $data['Api_key'] = env('CHICKEN_GAME_API_KEY');
            $result = $this->webService($data);
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


    private function webService($data)
    {
        Common::log(0, $data, 'create_chicken_game_account');
        $client = new \SoapClient(env('CHICKEN_GAME_URL'));
        $result = $client->CreateAccount($data);
        Common::log(0, $result, 'create_chicken_game_account');
        return json_decode($result, true);
    }
}