<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Helpers\Common;
use App\Helpers\VendorSend;
use App\Models\VendorInfo;
use App\Models\AesAccount;

class CreateAesAccount extends Command
{

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'create_aes_account {--updateBetLimit=}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '預先建立aes帳號';

    protected $vendor_send;
    protected $vendor_id = 25;
    protected $name = 'AES';
    protected $action = 'aes_create_accounts';

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

        //檢查商戶是否維護中; 否
        if (Common::check_vendor_maintenances($this->vendor_id)) {
            echo $this->name . ' vendor maintenances' . PHP_EOL;
        } else {
            $settings = VendorInfo::where('vendor_id', $this->vendor_id)->where('status', 1)->get();
            foreach ($settings as $row) {
                //將遊戲商資訊解密
                $info = Common::decryptVendorInfo($row);
                if (is_object($info)) {
                    if ($this->option('updateBetLimit') == "true") {
                        $this->updateBetLimit($row);
                    }
                    $prefix = $row->account_prefix; //帳號前綴
                    // 取目前剩多少未綁定帳號
                    $count = AesAccount::where('member_id', 0)->where('vendor_info_id', $row->id)->count();
                    if ($count < $row->prepared_account_number) {
                        // 取目前帳號最大值
                        $max = AesAccount::where('username', 'like', $prefix . '%')
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
            $account = AesAccount::create($row);
            // 在遊戲商新建帳號
            $result = $this->createVendorAccount($info, $username);
            // 如果失敗就不使用這筆記錄
            if (is_array($result) && $result['status'] == "0000") {
                echo '*****' . $username . '*****' . PHP_EOL;
            } else {
                // 如果失敗就刪除這筆記錄
                $account->update(['member_id' => -999999999]);
                $fail++;
                echo '!!!!!' . $username . '!!!!!' . PHP_EOL;
            }
        }
        return $fail;
    }

    /**
     * -建新帳號
     */
    private function createVendorAccount($info, $username)
    {
        try {
            $url = $info->API_URL . '/wallet/createMember';
//            $info->BETLIMIT = 140102;
//            $info->CERT = 'hbAYVlUKf7liMT5cDxr';
            $betlimit = json_encode(array('SEXYBCRT' => array('LIVE' => array('limitId'=>array($info->BETLIMIT)))));
            $data = array(
                'cert' => $info->CERT,
                'agentId' => $info->AGENT,
                'userId' => $username,
                'currency' => $info->CURRENCY,
                'betLimit' => $betlimit,
            );
            $result = json_decode($this->vendor_send->send($this->action, $url, 'POST', $data), true);
            return $result;
        } catch (\Illuminate\Database\QueryException $e) {
            $info = $e->errorInfo;
            return response()->json([
                'code' => $info[0],
                'message' => $info[2],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'code' => $e->getCode(),
                'message' => $e->getMessage(),
            ]);
        }
    }
    private function updateBetLimit($row) {
        //將遊戲商資訊解密
        //@todo 分頁進行, 避免一次獲取大量數據
        $info = Common::decryptVendorInfo($row);
        if (is_object($info)) {
            $rows = AesAccount::where('vendor_info_id', $row->id)->get();
            foreach($rows as $item) {
                $url = $info->API_URL . "/wallet/updateBetLimit";
                $betlimit = json_encode(array('SEXYBCRT' => array('LIVE' => array('limitId'=>array($info->BETLIMIT)))));
                $data = array(
                    'cert' => $info->CERT,
                    'agentId' => $info->AGENT,
                    'userId' => $item->username,
                    'betLimit' => $betlimit,
                );
                $result = json_decode($this->vendor_send->send('aes_update_bet_limit', $url, 'POST', $data), true);
                if($result['status'] == '0000') {
                    echo sprintf('%s update bet limit success!' . PHP_EOL, $item->username);
                } else {
                    echo sprintf('%s update bet limit fail!' . PHP_EOL, $item->username);
                }
            }
        }
    }
}
