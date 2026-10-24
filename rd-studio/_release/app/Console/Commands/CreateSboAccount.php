<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Helpers\VendorSend;
use App\Helpers\Common;
use App\Models\SboAccount;
use App\Models\VendorInfo;

use function GuzzleHttp\json_encode;

class CreateSboAccount extends Command
{

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'create_sbo_account';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '預先建立sbo帳號';

    protected $vendor_send;
    protected $vendor_id = 18;
    protected $name = 'SBO';
    protected $action = 'sbo_create_accounts';

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
                $prefix = $row->account_prefix; //帳號前綴
                // 取目前剩多少未綁定帳號
                $count = SboAccount::where('member_id', 0)->where('vendor_info_id', $row->id)->count();
                if ($count < $row->prepared_account_number) {
                    //將遊戲商資訊解密
                    $info = Common::decryptVendorInfo($row);
                    if (is_object($info)) {
                        // 取目前帳號最大值
                        $max = SboAccount::where('username', 'like', $prefix . '%')
                            ->where('vendor_info_id', $row->id)
                            ->orderBy('username', 'DESC')
                            ->pluck('username')
                            ->first();
                        $max = substr($max, -8) ? substr($max, -8) : 0;
                        // 建立帳號補齊備用數量
                        $number = ($row->prepared_account_number - $count);
                        $this->createAccount($info, $prefix, $max, $number, $row->id);
                    } else {
                        echo $row->name . '解密失敗';
                    }
                } else {
                    echo 'Account is enough' . PHP_EOL;
                }
            }
        }
    }

    private function createAccount($info, $prefix, $start, $number, $infoId)
    {
        $fail = 0;
        for ($i = 1; $i <= $number; $i++) {
            // 帳號規則，前綴加上8碼數字
            $username = $prefix . str_pad(($start + $i), 8, '0', STR_PAD_LEFT);
            $row = array(
                'vendor_info_id' => $infoId,
                'username' => $username,
                'admin' => 1
            );
            $account = SboAccount::create($row);
            // 在遊戲商新建帳號
            $result = $this->createVendorAccount($info, $username);
            // 如果失敗就刪除這筆記錄
            if (is_array($result) && $result['code'] == 1000) {
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

    private function createVendorAccount($info, $username)
    {
        try {
            // New players
            $url = $info->API_URL . 'Player/Create';
            $data = array();
            $data['secret_key'] = $info->SECRET_KEY;
            $data['operator_token'] = $info->OPERATOR_TOKEN;
            $data['player_name'] = $username;
            $data['currency'] = $info->CURRENCY;
            $result = json_decode($this->vendor_send->send($this->action, $url, 'POST', $data), true);
            if (is_array($result) && $result['data']['msg'] === "Player created") {
                return [
                    'code' => 1000,
                    'player_id' => $username,
                ];
            } else {
                return [
                    'code' => 9996,
                    'message' => $result
                ];
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


    private function post($info, $data, $url)
    {
        Common::log(0, $data, $this->action);
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $info->API_URL . $url);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        curl_setopt($ch, CURLOPT_POST, TRUE);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        $result = curl_exec($ch);
        curl_close($ch);
        Common::log(0, $result, $this->action);
        return json_decode($result, true);
    }
}
