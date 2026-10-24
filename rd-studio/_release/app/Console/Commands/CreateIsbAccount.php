<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Helpers\VendorSend;
use App\Helpers\Common;
use App\Models\VendorInfo;
use App\Models\IsbAccount;
use App\DES;
use DB;

class CreateIsbAccount extends Command
{

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'create_isb_account';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '預先建立iSB帳號';

    protected $vendor_send;
    protected $vendor_id = 14;
    protected $name = 'ISB';
    protected $action = 'isb_create_account';

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
                    $prefix = $row->account_prefix; //帳號前綴
                    // 取目前剩多少未綁定帳號
                    $count = IsbAccount::where('member_id', 0)->where('vendor_info_id', $row->id)->count();
                    if ($count < $row->prepared_account_number) {
                        // 取目前帳號最大值
                        $max = IsbAccount::where('username', 'like', $prefix . '%')
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
            $player_id = date('U') . rand(10, 99);
            $row = array(
                'vendor_info_id' => $infoId,
                'player_id' => $player_id,
                'username' => $username,
                'admin' => 1
            );
            $account = IsbAccount::create($row);
            // 在遊戲商新建帳號
            $result = $this->createVendorAccount($info, $username, $player_id);
            // 如果失敗就修改這筆記錄
            if ($result['code'] != 1000 || !is_array($result)) {
                $account->update(['member_id' => -999999999]);
                $fail++;
                echo '!!!!!' . $username . '!!!!!' . PHP_EOL;
            } else {
                echo '*****' . $username . '*****' . PHP_EOL;
            }
        }
        return $fail;
    }

    // 確認或建立帳號
    private function createVendorAccount($info, $username, $playerId)
    {
        try {
            // Ensure user account
            $data = array();
            $data['operator'] = $info->OPERATOR; //0為MASTER，operator需於找遊戲商設定
            $data['playerid'] = $playerId;
            $data['username'] = $username;
            $data['currency'] = $info->CURRENCY;
            $data['action'] = [
                'command' => 'addPlayer',
                'parameters' => [
                    'real' => true,
                ]
            ];
            $hash = hash_hmac('SHA256', json_encode($data), $info->KEY);
            $url = $info->HOST . $info->VERSION .  '/' . $info->LICENSEE_ID . '?hash=' . $hash;
            $header = array('Content-Type:application/json;charset=utf-8');
            $result = json_decode($this->vendor_send->send($this->action, $url, 'POST', $data, $header), true);
//            $result = $this->post($info, $data);
            if ($result['status'] == 'success') {
                return [
                    'code' => 1000,
                    'message' => $result['balance']
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
}
