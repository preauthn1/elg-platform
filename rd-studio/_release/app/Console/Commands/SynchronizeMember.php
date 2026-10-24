<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Helpers\Common;
use App\Models\Agent;
use App\Models\Member;
use App\AES;
use DB;
use Hash;

class SynchronizeMember extends Command
{

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'synchronize_member';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '會員到客服系統建立帳號';

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
        //查詢未綁定帳號
        $members = Member::leftJoin('agents as a', 'a.id', 'members.parent')
            ->select('a.CS_API_USER', 'a.CS_API_KEY', 'members.username',)
            ->get();
        echo '總數：' . count($members);
        $i = 0;
        foreach ($members as $row) {
            $data = [
                'API_USER' => $row->CS_API_USER,
                'API_KEY' => $row->CS_API_KEY,
                'TIMESTAMP' => date('U'),
                'THIRD_PARTY_ID' => $row->username,
            ];
            $result = $this->post($data);
            $i++;
            echo '***' . $i . '-' . $row->username . '***';
        }
    }

    private function sign($data)
    {
        $string = $data['API_USER'] . env('CS_API_MD5_KEY') . $data['API_KEY'] . env('CS_API_IV') . $data['TIMESTAMP'] . env('CS_API_AES_KEY') . $data['THIRD_PARTY_ID'];
        return md5($string);
    }

    public static function encrypt($key, $iv, $data)
    {
        // openssl 實現的 AES 加密
        $cryptText = openssl_encrypt($data, "aes-256-cbc", md5($key), OPENSSL_RAW_DATA, $iv);
        return base64_encode($cryptText);
    }

    private function post($data)
    {
        $params = $this->encrypt(env('CS_API_AES_KEY'), env('CS_API_IV'), json_encode($data));
        $array = array();
        $array['params'] = $params;
        $array['key'] = md5($params . env('CS_API_MD5_KEY'));
        Common::log(0, $data, 'synchronize_member');
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, env('CS_API') . 'member/self');
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($array));
        curl_setopt($ch, CURLOPT_POST, TRUE);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Authorization: Signature ' . $this->sign($data)
        ));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
        $result = curl_exec($ch);

        curl_close($ch);
        // var_dump($result);
        Common::log(0, $result, 'synchronize_member_result');
        return json_decode($result, true);
    }
}