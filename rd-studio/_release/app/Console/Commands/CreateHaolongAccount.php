<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Helpers\Common;
use App\Helpers\VendorSend;
use App\Models\VendorInfo;
use App\Models\HaolongAccount;
use DB;

class CreateHaolongAccount extends Command
{

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'create_haolong_account';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '預先建立豪龍帳號';
    protected $vendor_send;
    protected $vendor_id = 4;
    protected $name = 'Haolong';
    protected $action = 'haolong_create_accounts';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    protected $vendorsend;

    public function __construct()
    {
        parent::__construct();
        $this->vendorsend = new VendorSend();
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
            $settings = VendorInfo::where('vendor_id', $this->vendor_id)
                ->where('status', 1)
                ->get();
            if (count($settings) > 0) {
                foreach ($settings as $row) {
                    $info = json_decode($row->info);
                    if (is_object($info)) {
                        $prefix = $row->account_prefix;
                        // 取目前剩多少未綁定帳號
                        $count = HaolongAccount::where('vendor_info_id', $row->id)
                            ->where('member_id', 0)
                            ->count();
                        if ($count < $row->prepared_account_number) {
                            // 取目前帳號最大值
                            $max = HaolongAccount::where('vendor_info_id', $row->id)
                                ->where('username', 'like', $prefix . '00%')
                                ->orderBy('username', 'DESC')
                                ->pluck('username')
                                ->first();
                            $max = substr($max, -8) ? substr($max, -8) : 0;

                            // 建立帳號補齊
                            $number = ($row->prepared_account_number - $count);
                            $this->createAccount($prefix, $max, $number, $info, $row->id);
                        } else {
                            echo 'Account is enough' . PHP_EOL;
                        }
                    } else {
                        echo $row->name . '解密失敗' . PHP_EOL;
                    }
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
            $username_modified = (isset($info->USE_PREFIX) && $info->USE_PREFIX == 0)
                ? $username
                : $info->HAOLONG_USER . $username;
            $row = array(
                'vendor_info_id' => $vendor_info_id,
                'username' => $username,
                'member_id' => -1,
                'admin' => 1,
                'username_modified' => $username_modified
            );
            $account = HaolongAccount::create($row);
            // 密碼規則,6到12碼
            $password = [REDACTED] . strtotime($account->created_at)), 8, 12);
            $time = 1;
            $isFail = false;
            // 在遊戲商新建帳號
            do {
                $nextLoop = false;
                $result = $this->createVendorAccount($username, $password, $info);
                // 如果失敗就刪除這筆記錄
                if ($result != false && is_object($result) && isset($result->errorCode)) {
                    if ($result->errorCode == '0') {
                        $account->update(['member_id' => 0]);
                        echo '*****' . $username . '*****' . PHP_EOL;
                    } else {
                        if ($result->errorCode == '998' && $time < 3) {
                            //如果豪龍回傳系統忙碌中,就等待3秒再打一次,超過3次就棄用
                            $nextLoop = true;
                            echo '豪龍系統忙碌中,等待3秒後再試一次,目前第' . $time . '次' . PHP_EOL;
                            $time++;
                            sleep(3);
                        } else {
                            $isFail = true;
                        }
                    }
                }
            } while ($nextLoop);
            //創建帳號失敗,status改成-999999999
            if($isFail){
                $account->update(['member_id' => -999999999]);
                $fail++;
                echo '!!!!!' . $username . '!!!!!' . PHP_EOL;
            }
        }
        return $fail;
    }

    /**
     * -建新下線戶口
     */
    private function createVendorAccount($username, $password, $info)
    {
        try {
            $url = $info->API_URL . 'createPlayer.aspx';
            $data = array();
            $data['loginID'] = $username;
            $data['loginPass'] = $password;
            $data['fullName'] = $username;
            $data['apiUser'] = $info->API_USER;
            $data['apiPass'] = $info->API_PASSWORD;
            $data['user'] = $info->HAOLONG_USER;
            $data['pass'] = $info->HAOLONG_PASSWORD;

            return simplexml_load_string($this->vendorsend->send($this->action, $url, 'POST', $data));
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
}
