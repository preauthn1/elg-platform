<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Helpers\VendorSend;
use App\Helpers\Common;
use App\Models\VendorInfo;
use App\Models\SpadegamingAccount;

class CreateSpadeGamingAccount extends Command
{

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'create_spadegaming_account';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '預先建立SpadeGaming帳號';

    protected $vendor_send;
    protected $vendor_id = 47;
    protected $name = 'SpadeGaming';
    protected $action = 'spadegaming_create_accounts';
    protected $account_length = 8;

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
        $this->vendor_send = new VendorSend(['json_post' => true]);
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
                        $count = SpadegamingAccount::where('member_id', 0)
                            ->where('vendor_info_id', $row->id)
                            ->count();

                        if ($count < $row->prepared_account_number) {
                            //帳號前綴
                            $prefix = $row->account_prefix;

                            // 取目前帳號最大值
                            $max = SpadegamingAccount::where('username', 'like', $prefix . '%')
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
                            $this->createAccount($prefix, $max, $number, $row->id);
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

    private function createAccount($prefix, $start, $number, $info_id)
    {
        for ($i = 1; $i <= $number; $i++) {
            // 帳號規則，前綴加上數字，總長度不超過13個位元
            $username = $prefix . str_pad(($start + $i), $this->account_length, '0', STR_PAD_LEFT);
            $row = array(
                'vendor_info_id' => $info_id,
                'username' => $username,
                'admin' => 1
            );
            $account = SpadegamingAccount::create($row);
            echo '*****' . $username . '*****' . PHP_EOL;
        }
    }
}