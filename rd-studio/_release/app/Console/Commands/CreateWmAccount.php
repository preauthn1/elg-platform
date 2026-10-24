<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Helpers\VendorSend;
use App\Helpers\Common;
use App\Helpers\VendorPost;
use App\Models\WmAccount;
use App\Models\VendorInfo;

use function GuzzleHttp\json_encode;

class CreateWmAccount extends Command
{

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'create_wm_account';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '預先建立wm帳號';

    protected $vendor_send;
    protected $vendor_id = 16;
    protected $name = 'WM';
    protected $action = 'wm_create_account';

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
                    $count = WmAccount::where('member_id', 0)->where('vendor_info_id', $row->id)->count();
                    if ($count < $row->prepared_account_number) {
                        // 取目前帳號最大值
                        $max = WmAccount::where('username', 'like', $prefix . '%')
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
                    echo $row->name . '解密失敗';
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
            $user_id = date('U') . rand(100, 999);
            $row = array(
                'vendor_info_id' => $infoId,
                'username' => $username,
                'user_id' => $user_id,
                'admin' => 1
            );
            $account = WmAccount::create($row);
            $result = $this->createVendorAccount($info, $username, $user_id);
            if (is_array($result) && $result['code'] == 1000) {
                $account->update(['player_id' => $result['player_id']]);
                echo '*****' . $username . '*****';
            } else {
                // 如果失敗就刪除這筆記錄
                $account->update(['member_id' => -999999999]);
                $fail++;
                echo '!!!!!' . $username . '!!!!!';
            }
        }
        return $fail;
    }

    private function createVendorAccount($info, $username, $userId)
    {
        try {
            //如果需要创建两次, 回应将会是相同, 但 WAPI 仅在第一次使用时生成新玩家。
            //如果玩家已经存在, 但创建的资料 (如玩家名、代理/下线 或货币) 不对应, 则操作将返回错误 USER_MISMATCH。
            // New players
            $url = $info->SERVER_NAME . 'platform/feed/wallet/user/' . $info->LICENSEE_TOKEN;
            $data = array();
            $data['UserID'] = $userId; // 呼叫API會用到的
            $data['UserName'] = $username;
            $data['Skin'] = $info->SKIN;
            $data['Currency'] = $info->CURRENCY;
            $data['IPv4'] = '0.0.0.0';
            $data['TestUser'] = 'FALSE';

            $response = $this->vendor_send->send($this->action, $url, 'POST', $data, ['Content-Type:application/json']);
            $result = json_decode($response, true);
            //$data = json_encode($data);
            //$result = VendorPost::WmPost($info, $url, $data);
            if (is_array($result) && $result['Status'] === "ENABLED") {
                return [
                    'code' => 1000,
                    'player_id' => $result['UserToken'],
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
