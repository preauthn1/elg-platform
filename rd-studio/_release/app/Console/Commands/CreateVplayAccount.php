<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Helpers\Common;
use App\Helpers\VendorSend;
use App\Models\VplayAccount;
use App\Models\VendorInfo;
use App\RSA;
use App\AES;
use DB;

use function GuzzleHttp\json_encode;

class CreateVplayAccount extends Command
{

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'create_vplay_account';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '預先建立Vplay帳號';

    protected $vendor_send;
    protected $vendor_id = 8;
    protected $name = 'VPlay';
    protected $action = 'vplay_create_account';

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
            $setting = VendorInfo::where('vendor_id', $this->vendor_id)
                ->where('status', 1)
                ->get();
            foreach ($setting as $row) {
                echo $row->name . PHP_EOL;
                $info = json_decode($row->info);
                if (is_object($info)) {
                    $vendor_info_id = $row->id;
                    $prefix = $row->account_prefix;
                    // 取目前剩多少未綁定帳號
                    $count = VplayAccount::where('vendor_info_id', $vendor_info_id)
                        ->where('member_id', 0)
                        ->count();
                    if ($count < $row->prepared_account_number) {
                        // 取目前帳號最大值
                        $max = VplayAccount::where('vendor_info_id', $vendor_info_id)
                            ->where('username', 'like', $prefix . '00%')
                            ->orderBy('username', 'DESC')
                            ->pluck('username')
                            ->first();
                        $max = substr($max, -8) ? substr($max, -8) : 0;
                        // 建立帳號補齊100個
                        $number = ($row->prepared_account_number - $count);
                        $this->createAccount($prefix, $max, $number, $info, $vendor_info_id);
                    } else {
                        echo 'Account is enough' . PHP_EOL;
                    }
                } else {
                    echo $row->name . '解密失敗' . PHP_EOL;
                }
            }
        }
    }

    private function createAccount($prefix, $start, $number, $info, $vendor_info_id)
    {
        $fail = 0;
        if ($number > 0) {
            //取AES的key跟iv
            $key = $this->getKey($info);
            for ($i = 1; $i <= $number; $i++) {
                // 帳號規則，前綴加上8碼數字，總長度不超過13個位元
                $username = $prefix . str_pad(($start + $i), 8, '0', STR_PAD_LEFT);
                $row = array(
                    'vendor_info_id' => $vendor_info_id,
                    'username' => $username,
                    'admin' => 1
                );
                $account = VplayAccount::create($row);
                // 在Vplay新建帳號
                $result = $this->createVendorAccount($username, $key, $info);
                // 如果失敗就刪除這筆記錄
                if (is_array($result) && isset($result['code']) && $result['code'] == 1000) {
                    $account->update(['player_id' => $result['message']]);
                    echo '*****' . $username . '*****' . PHP_EOL;
                } else {
                    $account->update(['member_id' => -999999999]);
                    $fail++;
                    echo '!!!!!' . $username . '!!!!!' . PHP_EOL;
                }
            }
        }
        return $fail;
    }

    private function createVendorAccount($username, $key, $info)
    {
        try {
            // New players
            $url = 'MobileApp/H5ApiLogin.ashx';
            $data = array();
            $data['GamePlatform'] = $info->GAME_PLATFORM;
            $data['AgentAccount'] = $info->AGENT;
            $data['MemberAccount'] = $username;
            $data['Timestamp'] = date('U');
            $data['Hash'] = $this->getHash($data, $info);
            $aes = new AES($key['key'], 'AES-128-CBC', $key['iv']);
            $data = $aes->encrypt(json_encode($data));
            $result = $this->post(strtoupper($data), $url, $info, $key['cookie']);
            $decode = json_decode($aes->decrypt($result), true);
            if (is_array($decode)) {
                Common::log(0, $decode, $this->action . '_decode');
                if (isset($decode['ResultValue']['Result']) && $decode['ResultValue']['Result'] == 1) {
                    return [
                        'code' => 1000,
                        'message' => $decode['ResultValue']['ImAccount']
                    ];
                } else {
                    return [
                        'code' => 9996,
                        'message' => $decode['ResultValue']['ResultMessage']
                    ];
                }
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

    private function getHash($data, $info)
    {
        return strtoupper(md5($data['AgentAccount'] . $data['MemberAccount'] . $data['Timestamp'] . $info->SECRET));
    }

    private function getKey($info)
    {
        $url = 'MobileApp/GetKey.ashx';
        $data = [
            'key' => $this->getRSAPublicKey($info)
        ];
        $rs = $this->post(json_encode($data), $url, $info);
        $rsa = new RSA('', $info->PRIVATE_KEY_FILE);
        $decrypt = $rsa->decrypt($rs['Data'], 'hex');
        list($key, $iv) = explode('#@$', $decrypt);
        return ['key' => base64_decode($key), 'iv' => base64_decode($iv), 'cookie' => $rs['cookie']];
    }

    private function getRSAPublicKey($info)
    {
        $key_content = file_get_contents($info->PUBLIC_KEY_FILE);
        // 轉base64後，在做把+取代為空白：
        return $this->base64encode($key_content);
    }

    // 返回，取代 '+' 字符為半形空白
    private function base64encode($data, $urlsafe = FALSE)
    {
        $data = base64_encode($data);
        return $urlsafe ? strtr($data, '+/', '-_') : $data;
    }

    private function post($data, $url, $info, $preCookie = '')
    {
        Common::log(0, $data, $this->action);
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $info->API_URL . $url . '?platform=' . $info->PLATFORM . '&timestamp=' . date('U') . '&ver=' . $info->VERSION . '&language=zh_CN');
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Content-Type:application/json'
        ));
        curl_setopt($ch, CURLOPT_POST, TRUE);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_HEADER, true);
        if ($preCookie != '') {
            curl_setopt($ch, CURLOPT_COOKIEFILE, $preCookie);
        } else {
            $cookie_jar = tempnam($info->COOKIE_FILE, 'cookie');
            curl_setopt($ch, CURLOPT_COOKIEJAR, $cookie_jar);
        }
        $result = curl_exec($ch);
        $header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        // $header = substr($result, 0, $header_size);
        curl_close($ch);
        $result = substr($result, $header_size);
        Common::log(0, $result, $this->action);
        $decode = json_decode($result, true);
        if ($decode) {
            $decode['cookie'] = $cookie_jar;
            return $decode;
        } else {
            return $result;
        }
    }
}
