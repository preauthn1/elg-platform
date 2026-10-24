<?php
namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\AgAccount;
use App\AgService;

class CreateAgAccount extends Command
{

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'create_ag_account';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '預先建立AG帳號';

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
        // 為MG平台可使用取cagent前綴
        list ($prefix, $suffix) = explode('_', env('AG_AGENT'));
        // 取目前剩多少未綁定帳號
        $count = AgAccount::where('member_id', 0)->count();
        if ($count < 100) {
            // 取目前帳號最大值
            $max = AgAccount::orderBy('username', 'DESC')->pluck('username')->first();
            $max = substr($max, - 8) ? substr($max, - 8) : 0;
            // 建立帳號補齊100個
            $number = (100 - $count);
            if ($fail = $this->createAccount($prefix, $max, $number)) {
                // 如果有失敗的就再加開那個數量，這樣下次排程執行時就不會卡在失敗那筆，可以重新補齊
                $this->createAccount($prefix, ($max + $number), $fail);
            }
        }
    }

    private function createAccount($prefix, $start, $number)
    {
        $fail = 0;
        for ($i = 1; $i <= $number; $i ++) {
            // 帳號規則，前綴加上8碼數字，總長度不超過13個位元
            $username = $prefix . 'x' . str_pad(($start + $i), 8, '0', STR_PAD_LEFT);
            $row = array(
                'username' => $username,
                'admin' => 1
            );
            $result = AgAccount::create($row);
            // 密碼規則,6到12碼
            $password = [REDACTED] . strtotime($result->created_at)), 8, 12);
            $model = new AgService();
            // 在AG新建帳號
            $result = $model->checkOrCreateAccount($username, $password, 'DE0A');
            // 如果失敗就刪除這筆記錄
            if ((string) $result->attributes()->info !== '0') {
                AgAccount::where('username', $username)->delete();
                $fail ++;
                echo '!!!!!' . $username . '-----' . $password . '!!!!!';
            } else {
                echo '*****' . $username . '-----' . $password . '*****';
            }
        }
        return $fail;
    }
}
