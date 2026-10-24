<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Helpers\VendorSend;
use App\Helpers\Common;
use App\Models\VsportAccount;
use App\Models\VendorInfo;

class CreateVsportAccount extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'create_vsport_account';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '預先建立V-Sport帳號';

    protected $vendor_send;
    protected $vendor_id = 53;
    protected $name = 'VSport';
    protected $action = 'vsport_create_accounts';

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
                    $count = VsportAccount::where('member_id', 0)->where('vendor_info_id', $row->id)->count();
                    if ($count < $row->prepared_account_number) {
                        // 取目前帳號最大值
                        $max = VsportAccount::where('username', 'like', $prefix . '%')
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
        //注意!! 他們username要最大只能到11位,所以前綴只能3個以內
        for ($i = 1; $i <= $number; $i++) {
            // 帳號規則，前綴加上8碼數字，總長度不超過13個位元
            $username = $prefix . str_pad(($start + $i), 8, '0', STR_PAD_LEFT);
            $row = array(
                'vendor_info_id' => $infoId,
                'username' => $username,
            );

            $account = VsportAccount::create($row);
            // 在遊戲商新建帳號
            $result = $this->createVendorAccount($info, $username);
            // 如果失敗就不使用這筆記錄
            if (is_array($result) && $result['errcode'] === 0) {
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
            $url = $info->API_URL;

            $data = array();
            $data['secret'] = $info->SECRET;
            $data['action'] = 'create';
            $data['agent'] = $info->AGENT;
            $data['username'] = $username;

            $vendorSend = new VendorSend();
            $response = $vendorSend->send($this->action, $url, 'GET', $data);
            $result = json_decode($response, true);
            if (is_array($result) && $result['errcode'] === 0) {
                $updateAccount = $this->updateAccount($info, $username);
                if (is_array($updateAccount) && $updateAccount['errcode'] === 0) {
                    return $result;
                } else {
                    echo $updateAccount['errtext'] . PHP_EOL;
                }
            } else {
                echo $result['errtext'] . PHP_EOL;
            }
            return false;
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

    private function updateAccount($info, $username)
    {
        $url = $info->API_URL;

        $data = array();
        $data['secret'] = $info->SECRET;
        $data['action'] = 'update';
        $data['agent'] = $info->AGENT;
        $data['username'] = $username;
        $data['max1'] = $info->LIMIT->max[0];
        $data['max2'] = $info->LIMIT->max[1];
        $data['max3'] = $info->LIMIT->max[2];
        $data['max4'] = $info->LIMIT->max[3];
        $data['max5'] = $info->LIMIT->max[4];
        $data['lim1'] = $info->LIMIT->lim[0];
        $data['lim2'] = $info->LIMIT->lim[1];
        $data['lim3'] = $info->LIMIT->lim[2];
        $data['lim4'] = $info->LIMIT->lim[3];
        $data['lim5'] = $info->LIMIT->lim[4];
        $data['comtype'] = $info->COMTYPE;
        $data['com1'] = $info->COM[0];
        $data['com2'] = $info->COM[1];
        $data['com3'] = $info->COM[2];
        $data['com4'] = $info->COM[3];
        $data['suspend'] = $info->SUSPEND;

        $vendorSend = new VendorSend();
        $result = $vendorSend->send($this->action, $url, 'GET', $data);

        return json_decode($result, true);
    }
}
