<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Enums\VendorEnum;
use App\Helpers\VendorSend;
use App\Helpers\Common;
use App\Models\VendorInfo;
use App\Models\St8Account;
use App\Models\Vendor;

class CreateSt8Account extends Command
{

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'create_st8_account {--vendor=}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '預先建立{VENDOR}帳號';

    protected $vendor_id = VendorEnum::ST8;
    protected $action = 'ST8_create_accounts';
    protected $account_length = 8;

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
        try {
            $vendor = $this->option('vendor') ? $this->option('vendor') : 0;

            #取得聚合所包含遊戲商
            $vendor_groups = Vendor::where('third_party_code', 'ST8')
                ->where('status', 1)
                ->pluck('id')
                ->toArray();

            if ($vendor == 0) {
                $vendors = $vendor_groups;
            } elseif (in_array($vendor, $vendor_groups)) {
                $vendors = [$vendor];
            } else {
                $vendors = [];
            }

            if (count($vendors) > 0) {
                foreach ($vendors as $vendor_id) {
                    $this->vendor_id = $vendor_id;
                    $vendor = Vendor::find($this->vendor_id);

                    echo str_replace('{VENDOR}', $vendor->name, $this->description) . PHP_EOL;

                    //檢查商戶是否維護中
                    if (Common::check_vendor_maintenances($this->vendor_id)) {
                        echo $vendor->name . ' vendor maintenances' . PHP_EOL;
                    } else {
                        //取設定檔
                        $settings = VendorInfo::where('vendor_id', $this->vendor_id)
                            ->where('status', 1)
                            ->get();

                        if (is_object($settings)) {
                            foreach ($settings as $row) {
                                echo '<<' . $row->name . '>>' . PHP_EOL;
                                //將遊戲商資訊解密
                                $info = Common::decryptVendorInfo($row);
                                if (is_object($info)) {
                                    // 取目前剩多少未綁定帳號
                                    $count = St8Account::where('member_id', 0)
                                        ->where('vendor_info_id', $row->id)
                                        ->count();

                                    if ($count < $row->prepared_account_number) {
                                        //帳號前綴
                                        $prefix = $row->account_prefix;

                                        // 取目前帳號最大值
                                        $max = St8Account::where('username', 'like', $prefix . '%')
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
            }
        } catch (\Illuminate\Database\QueryException $exception) {
            $info = $exception->errorInfo;
            echo $info[0] . ' : ' . $info[2];
        } catch (\Exception $exception) {
            echo $exception->getCode() . ' : ' . $exception->getMessage();
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
            $account = St8Account::create($row);
            echo '*****' . $username . '*****' . PHP_EOL;
        }
    }
}