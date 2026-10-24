<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Helpers\VendorSend;
use App\Helpers\Common;
use App\Models\VendorInfo;
use App\Models\NsAccount;
use SebastianBergmann\CodeCoverage\Report\PHP;

class CreateNsAccount extends Command
{

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'create_ns_account';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '預先建立NextSpin帳號';

    protected $vendor_send;
    protected $vendor_id = 48;
    protected $name = 'NextSpin';
    protected $action = 'ns_create_accounts';
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
                        $count = NsAccount::where('member_id', 0)
                            ->where('vendor_info_id', $row->id)
                            ->count();

                        if ($count < $row->prepared_account_number) {
                            //帳號前綴
                            $prefix = $row->account_prefix;

                            // 取目前帳號最大值
                            $max = NsAccount::where('username', 'like', $prefix . '%')
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
                            $this->createAccount($info, $prefix, $max, $number, $row->id);
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

    private function createAccount($info, $prefix, $start, $number, $info_id)
    {
        $fail = $time = 0;
        for ($i = 1; $i <= $number; $i++) {
            if ($time >= 50) {
                //怕一次建立太多帳號太久卡到其他排程，超過50次就下次跑排程再建立
                break;
            }
            // 帳號規則，前綴加上數字，總長度不超過13個位元
            $username = $prefix . str_pad(($start + $i), $this->account_length, '0', STR_PAD_LEFT);
            $row = array(
                'vendor_info_id' => $info_id,
                'username' => $username,
                'admin' => 1
            );
            $account = NsAccount::create($row);
            // 在遊戲商新建帳號
            $result = $this->createVendorAccount($info, $username);
            // 如果失敗就將這筆記錄member_id改為-999999999
            if ($result['code'] != 1000 || !is_array($result)) {
                $account->update(['member_id' => -999999999]);
                $fail++;
                echo '!!!!!' . $username . '!!!!!' . PHP_EOL;
                echo 'API_Error_Code : ' . $result['message'] . PHP_EOL;
            } else {
                echo '*****' . $username . '*****' . PHP_EOL;
            }
            $time++;
            // API限制1秒只能呼叫一次
        }
        return $fail;
    }

    private function createVendorAccount($info, $username)
    {
        try {
            $url = $info->API_URL;

            //因為他們沒有建立帳號的api,所以用轉入轉出當作建立帳號
            $API = ['deposit', 'withdraw'];
            $postData = array();
            $postData['acctId'] = $username;
            $postData['amount'] = 1;
            $postData['currency'] = $info->CURRENCY;
            $postData['merchantCode'] = $info->MERCHANT_CODE;

            echo PHP_EOL;
            foreach ($API as $row) {
                $postData['serialNo'] = time() . rand(10, 99);

                $headers = array(
                    'API:' . $row,
                    'DataType:JSON',
                    'Content-Type:application/json'
                );

                $response = json_decode($this->vendor_send->send($this->action, $url, 'POST', $postData, $headers));
                if (is_object($response) && $response->msg == 'Success') {
                    $code = 1000;
                    $message = $username;
                } else {
                    $code = 9996;
                    $message = $response->msg;
                    break;
                }
                //以防萬一,隔一秒再打轉回的API
                sleep(1);
            }

            return [
                'code' => $code,
                'message' => $message
            ];
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
}