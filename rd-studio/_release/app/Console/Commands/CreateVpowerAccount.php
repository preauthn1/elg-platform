<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Helpers\Common;
use App\Helpers\VendorSend;
use App\Models\VendorInfo;
use App\Models\VpowerAccount;
use DB;

class CreateVpowerAccount extends Command
{

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'create_vpower_account';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '預先建立Vpower帳號';

    protected $vendor_send;
    protected $vendor_id = 22;
    protected $name = 'VPower';
    protected $action = 'vpower_create_account';
    protected $account_length = 8;

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
            $settings = DB::table('vendor_infos as vi')
                ->join('wallet_types as wt', function ($join) {
                    $join->on('wt.id', 'vi.wallet_type_id')
                        ->where('wt.status', 1);
                })
                ->where('vi.vendor_id', $this->vendor_id)
                ->where('vi.status', 1)
                ->select(
                    'vi.id',
                    'vi.name',
                    'vi.type',
                    'vi.vendor_id',
                    'vi.wallet_type_id',
                    'vi.currency',
                    'vi.rate',
                    'vi.transfer_min_amount',
                    'example.invalid',
                    'vi.prepared_account_number',
                    'vi.account_prefix',
                    'vi.cost',
                    'vi.status',
                    DB::raw('wt.code AS wallet_prefix')
                )
                ->get();
            if (is_object($settings)) {
                foreach ($settings as $row) {
                    echo '<<' . $row->name . '>>' . PHP_EOL;
                    //將遊戲商資訊解密
                    $info = Common::decryptVendorInfo($row);
                    if (is_object($info)) {
                        // 取目前剩多少未綁定帳號
                        $count = VpowerAccount::where('member_id', 0)->where('vendor_info_id', $row->id)->count();
                        if ($count < $row->prepared_account_number) {
                            //帳號前綴
                            $prefix = $row->account_prefix . $row->wallet_prefix;

                            // 取目前帳號最大值
                            $max = VpowerAccount::where('username', 'like', $prefix . '%')
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

    private function createAccount($info, $prefix, $start, $number, $infoId)
    {
        $fail = 0;
        for ($i = 1; $i <= $number; $i++) {
            // 帳號規則，前綴加上數字，總長度不超過13個位元
            $username = $prefix . str_pad(($start + $i), $this->account_length, '0', STR_PAD_LEFT);

            $row = array(
                'vendor_info_id' => $infoId,
                'username' => $username,
                'admin' => 1,
            );
            $account = VpowerAccount::create($row);
            // 在遊戲商新建帳號
            $result = $this->createVendorAccount($info, $username);
            // 如果失敗就不使用這筆記錄
            if (is_array($result) && isset($result['ErrorCode']) && $result['ErrorCode'] == 1) {
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
            $AppID = $info->API_APPID;
            $time = time();
            $Req = "Act=getacc&Timestamp=$time&Username=$username&AppId=$AppID";
            $Signature = rawurlencode($this->hmac_sha1($Req, $info->API_KEY));
            $url = $url . "?" . $Req . "&Signature=" . $Signature;

            //記錄LOG
            $data = array();
            $data['Act'] = 'getacc';
            $data['Timestamp'] = $time;
            $data['Username'] = $username;
            $data['AppId'] = $AppID;
            $data['Signature'] = $Signature;

            $header = array('Content-Type:application/json');
            $result = json_decode($this->vendor_send->send($this->action, $url, 'GET', [], $header), true);
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

    private function hmac_sha1($str, $key)
    {
        $signature = "";
        if (function_exists('hash_hmac')) {
            $signature = base64_encode(hash_hmac("sha1", $str, $key, true));
        } else {
            $blocksize = 64;
            $hashfunc = 'sha1';
            if (strlen($key) > $blocksize) {
                $key = pack('H*', $hashfunc($key));
            }
            $key = str_pad($key, $blocksize, chr(0x00));
            $ipad = str_repeat(chr(0x36), $blocksize);
            $opad = str_repeat(chr(0x5c), $blocksize);
            $hmac = pack(
                'H*', $hashfunc(
                    ($key ^ $opad) . pack(
                        'H*', $hashfunc(
                            ($key ^ $ipad) . $str
                        )
                    )
                )
            );
            $signature = base64_encode($hmac);
        }
        return $signature;
    }
}
