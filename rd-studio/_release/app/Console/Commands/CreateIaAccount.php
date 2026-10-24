<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use function GuzzleHttp\json_encode;
use App\Helpers\Common;
use App\Helpers\VendorSend;
use App\Models\IaAccount;
use App\Models\VendorInfo;

class CreateIaAccount extends Command
{

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'create_ia_account';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '預先建立ia帳號';

    protected $vendor_send;
    protected $vendor_id = 21;
    protected $name = 'IA';
    protected $action = 'ia_create_account';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
        $this->vendor_send = new VendorSend(['json_post' => 'array']);
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
                    $count = IaAccount::where('member_id', 0)->where('vendor_info_id', $row->id)->count();
                    if ($count < $row->prepared_account_number) {
                        // 取目前帳號最大值
                        $max = IaAccount::where('username', 'like', $prefix . '%')
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
            $account = IaAccount::create($row);
            // 在MT新建帳號
            $result = $this->createVendorAccount($info, $username);
            if (is_array($result) && $result['code'] == 1000) {
                echo '*****' . $username . '*****' . PHP_EOL;
            } else {
                $account->update(['member_id' => -999999999]);
                $fail++;
                echo '!!!!!' . $username . '!!!!!' . PHP_EOL;
            }
        }
        return $fail;
    }

    private function createVendorAccount($info, $username)
    {
        try {
            // New players
            $url = $info->API_URL . '/user/register';
            $currency = 'MYR';
            $data = array();
            $data['currency_code'] = $info->CURRENCY;
            $data['limit_money'] = $info->LIMIT;
            $data['register_username'] = $username;
            $md5_str = $this->getmd5str($data);
            $data['auth_key'] = $md5_str;
            $params = $this->encrypt($info, $data);

            $result = $this->decrypt($info, $this->vendor_send->send($this->action, $url, 'POST', $params, ['pch:' . $info->API_PCH]));
            if (is_object($result) && $result->status === 1) {
                return [
                    'code' => 1000,
                    'player_id' => $username,
                ];
            } else {
                return [
                    'code' => 9996,
                    'message' => $result,
                ];
            }
        } catch (\Illuminate\Database\QueryException $e) {
            $info = $e->errorInfo;
            return [
                'code' => $info[0],
                'message' => $info[2],
            ];
        } catch (\Exception $e) {
            return [
                'code' => $e->getCode(),
                'message' => $e->getMessage(),
            ];
        }
    }
    private function getmd5str($data)
    {
        $str = '';
        $time = 0;
        foreach ($data as $key => $row) {
            if ($time == 0) {
                $str .= $key . '=' . $row;
            } else {
                $str .= ',' . $key . '=' . $row;
            }
            $time++;
        }
        $str = md5($str);
        return $str;
    }

    private function encrypt($info, $params)
    {
        $params = json_encode($params);
        $cryptText = openssl_encrypt($params, "aes-256-cbc", $info->API_KEY, OPENSSL_RAW_DATA, $info->API_IV);
        $data = base64_encode($cryptText);
        return $data;
    }

    public function decrypt($info, $params)
    {
        $params = base64_decode($params);
        $cryptText = openssl_decrypt($params, "aes-256-cbc", $info->API_KEY, OPENSSL_RAW_DATA, $info->API_IV);
        return json_decode($cryptText);
    }
}
