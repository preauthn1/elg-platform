<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Helpers\Common;
use App\Helpers\VendorSend;
use App\Models\VendorInfo;
use App\Models\AllbetAccount;

class CreateAllbetAccount extends Command
{

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'create_allbet_account';

    /**
     * The console command description
     *
     * @var string
     */
    protected $description = '預先建立Allbet帳號';

    protected $vendor_send;
    protected $vendor_id = 35;
    protected $name = 'Allbet';
    protected $action = 'allbet_create_account';

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
                //將遊戲商資訊解密
                $info = Common::decryptVendorInfo($row);
                if (is_object($info)) {
                    $prefix = $row->account_prefix; //帳號前綴
                    // 取目前剩多少未綁定帳號
                    $count = AllbetAccount::where('member_id', 0)->where('vendor_info_id', $row->id)->count();
                    if ($count < $row->prepared_account_number) {
                        // 取目前帳號最大值
                        $max = AllbetAccount::where('username', 'like', $prefix . '%')
                            ->where('vendor_info_id', $row->id)
                            ->orderBy('username', 'DESC')
                            ->pluck('username')
                            ->first();
                        $max = substr($max, -8) ? substr($max, -8) : 0;
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
        }
    }

    private function createAccount($info, $prefix, $start, $number, $infoId)
    {
        $fail = 0;
        for ($i = 1; $i <= $number; $i++) {
            //預防帳號創太久，超過30組就下次再創
            if ($i > 30) {
                break;
            }
            // 帳號規則，前綴加上8碼數字，總長度不超過13個位元
            $username = $prefix . str_pad(($start + $i), 8, '0', STR_PAD_LEFT);

            $row = array(
                'vendor_info_id' => $infoId,
                'username' => $username,
                'admin' => 1,
            );
            $account = AllbetAccount::create($row);
            $password = [REDACTED] . strtotime($account->created_at)), 8, 12);
            // 在遊戲商新建帳號
            $result = $this->createVendorAccount($info, $username, $password);

            // 如果失敗就不使用這筆記錄
            if (is_array($result) && $result['error_code'] == 'OK') {
                $account->update(['password' => $password]);
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
    private function createVendorAccount($info, $username, $password)
    {
        try {
            $url = $info->API_URL . '/check_or_create';
            $param = array();
            $param['random'] = mt_rand();
            $param['agent'] = $info->AGENT;
            $param['client'] = $username;
            $param['password'] = $password;
            $param['orHandicapNames'] = 'A';
            $param['orHallRebate'] = 0;

            $data = $this->data($info, $param);
            $sign = $this->sign($info, $data);

            $postData = array();
            $postData['data'] = $data;
            $postData['sign'] = $sign;
            $postData['propertyId'] = $info->PROPERY_ID;

            $response = $this->vendor_send->send($this->action, $url, 'GET', $postData);

            $response = json_decode($response, true);

            return $response;
        } catch (\Illuminate\Database\QueryException $e) {
            $info = $e->errorInfo;
            echo $info[0] . ' : ' . $info[2];
        } catch (\Exception $e) {
            echo $e->getCode() . ' : ' . $e->getMessage();
        }
    }

    private function data($info, $param)
    {
        $real_param = http_build_query($param);
        $key = base64_decode($info->DES_KEY);
        $pad = 8 - (strlen($real_param) % 8);
        $string = $real_param . str_repeat(chr($pad), $pad);
        $data = openssl_encrypt(
            $string,
            'DES-EDE3-CBC',
            $key,
            OPENSSL_RAW_DATA | OPENSSL_NO_PADDING,
            base64_decode("AAAAAAAAAAA=")
        );
        $data = base64_encode($data);
        return $data;
    }

    private function sign($info, $data)
    {
        return base64_encode(md5($data . $info->MD5_KEY, true));
    }
}
