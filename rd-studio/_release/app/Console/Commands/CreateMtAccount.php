<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Helpers\Common;
use App\Helpers\VendorSend;
use App\Models\VendorInfo;
use App\Models\MtAccount;
use App\DES;
use DB;

class CreateMtAccount extends Command
{

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'create_mt_account';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '預先建立mt帳號';

    protected $vendor_send;
    protected $vendor_id = 19;
    protected $name = 'MT';
    protected $action = 'mt_create_account';

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
            $settings = VendorInfo::where('vendor_id', $this->vendor_id)->where('status', 1)->get();
            foreach ($settings as $row) {
                //將遊戲商資訊解密
                $info = Common::decryptVendorInfo($row);
                if (is_object($info)) {
                    //帳號前綴
                    $prefix = $row->account_prefix;
                    // 取目前剩多少未綁定帳號
                    $count = MtAccount::where('member_id', 0)->where('vendor_info_id', $row->id)->count();
                    if ($count < $row->prepared_account_number) {
                        // 取目前帳號最大值
                        $max = MtAccount::where('username', 'like', $prefix . '%')
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
                'admin' => 1
            );
            $account = MtAccount::create($row);
            // 密碼規則,6到12碼
            $password = [REDACTED] . strtotime($account->created_at)), 8, 12);
            // 在MT新建帳號
            $result = $this->createVendorAccount($info, $username, $password);
            // 如果失敗就刪除這筆記錄
            if (is_array($result) && $result['resultCode'] === '1') {
                $account->update(['password' => $password]);
                echo '*****' . $username . '*****' . PHP_EOL;
            } else {
                $account->update(['member_id' => -999999999]);
                $fail++;
                echo '!!!!!' . $username . '!!!!!' . PHP_EOL;
            }
        }
        return $fail;
    }

    // 確認或建立帳號
    private function createVendorAccount($info, $username, $password)
    {
        try {
            $url = $info->MT_API_URL.'playerCreate2/';            
            //密碼
            $pwd = [REDACTED];
            //rawData
            $rawData = array();
            $rawData['nickname']='';
            $rawData['playerLevel'] = '0';
            $rawData = json_encode($rawData);
            //code
            $code = md5($info->MT_KEY.$rawData);
            //$data
            $data = base64_encode($rawData);
            //log
            $postdata = array();
            $postdata['username'] = $username;
            $postdata['merchanId'] = $info->MT_MERCHANID;
            $postdata['pwd'] = $pwd;
            $postdata['code'] = $code;
            $postdata['data'] = $data;
            $url .= $username . '/' . $info->MT_MERCHANID . '/' . $pwd . '/' . $code . '/' . $data;
            $header = array('Content-Type:application/json');
            $result = json_decode($this->vendor_send->send($this->action, $url, 'POST', $postdata, $header), true);
            return $result;
        } catch (\Exception $e) {
            return $e->getMessage();
        }
    }
}
