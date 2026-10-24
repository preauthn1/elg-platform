<?php

namespace App\Console\Commands;

use App\AES;
use App\Helpers\VendorSend;
use Illuminate\Console\Command;
use App\Helpers\Common;
use App\Models\TiktokAccount;
use DB;

use function GuzzleHttp\json_encode;

class CreateTtAccount extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'create_tiktok_account';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '預先建立Tiktok帳號';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    protected $vendor_send;

    protected $vendor_id = 28;

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
        $prepared_account_number = 100;
        $prefix = env('VENDOR_ACCOUNT_PREFIX');
        // 取目前剩多少未綁定帳號
        $count = TiktokAccount::where('member_id', 0)->count();
        if ($count < $prepared_account_number) {
            // 取目前帳號最大值
            $max = TiktokAccount::where('username', 'like', $prefix . '00%')
                ->orderBy('username', 'DESC')
                ->pluck('username')
                ->first();
            $max = substr($max, -8) ? substr($max, -8) : 0;
            $number = ($prepared_account_number - $count);
            if ($fail = $this->createAccount($max, $number, $prefix)) {
                // 如果有失敗的就再加開那個數量，這樣下次排程執行時就不會卡在失敗那筆，可以重新補齊
                $this->createAccount(($max + $number), $fail, $prefix);
            }
        }

    }

    private function createAccount($start, $number, $prefix)
    {
        $fail = 0;
        if ($number > 0) {
            //取AES的key跟iv
            // var_dump($key);
            for ($i = 1; $i <= $number; $i++) {
                // 帳號規則，前綴加上8碼數字，總長度不超過13個位元
                $username = $prefix . str_pad(($start + $i), 8, '0', STR_PAD_LEFT);
                $row = array(
                    'media_id' => 1,
                    'username' => $username,
                    'password' => ' ',
                    'member_id' => 0,
                    'admin' => 1
                );
                $account = TiktokAccount::create($row);
                // 密碼規則,6到12碼
                $password = [REDACTED] . strtotime($account->created_at)), 8, 12);
                // 在Tiktok新建帳號
                $result = $this->createVendorAccount($username, $password);
                // 如果失敗就刪除這筆記錄
                if ($result != false && is_array($result)) {
                    if ($result['code'] != 1000) {
                        $account->update(['member_id' => -999999999]);
                        $fail++;
                        echo '!!!!!' . $username . '!!!!!' . $result['code'] . PHP_EOL;
                    } else {
                        $account->update(['password' => $password]);
                        echo '*****' . $username . '*****' . PHP_EOL;
                    }
                } else {
                    $account->update(['member_id' => -999999999]);
                    $fail++;
                    echo '!!!!!' . $username . '!!!!!' . PHP_EOL;
                }
            }
        }
        return $fail;
    }


    private function createVendorAccount($username, $password)
    {
        try {

            // New players
            $url = env('TT_API_URL') . 'auth/create_user';
            $user = array();
            $user['name'] = $username;
            $user['username'] = $username;
            $user['password'] = sha1($password);
            $user['thumbnail'] = ' ';
            $data = array('user' => $user);
            $body = base64_encode(json_encode($data));
            $sign = "";
            $str = chunk_split(env('TT_PRIVATE_KEY'), 64, "\n");
            $key = "[PRIVATE_KEY_BLOCK_REDACTED]\n";
            openssl_sign($body, $sign, $key, 'SHA256');

            $postData = array();
            $postData['sign'] = base64_encode($sign);
            $postData['data'] = $body;
            $postData['apiKey'] = env('TT_API_KEY');

            $result = json_decode($this->vendor_send->send('tiktok_create_account', $url, 'POST', $postData), true);

            if (is_array($result) && $result['status'] == 1) {
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
}