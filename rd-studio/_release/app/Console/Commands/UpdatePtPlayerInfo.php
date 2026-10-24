<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Helpers\Common;
use App\Models\VendorInfo;
use App\Models\PtAccount;
use DB;

class UpdatePtPlayerInfo extends Command
{

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'pt_player_info {--viplevel=}'; //pt_player_info --viplevel=7

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '更新PT使用者資訊';

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
        try {

            $viplevel = $this->option('viplevel')? $this->option('viplevel') : '7';

            $data = array();
            $data['viplevel'] = $viplevel;

            $settings = VendorInfo::where('vendor_id', 17)->where('status', 1)->get();

            foreach ($settings as $row) {
                //將遊戲商資訊解密
                $info = Common::decryptVendorInfo($row);
                if (is_object($info)) {
                    $this->updatePlayerInfo($info, $data);
                } else {
                    echo $row->name . '解密失敗' . PHP_EOL;
                }
            }

        } catch (\Illuminate\Database\QueryException $e) {
            $info = $e->errorInfo;
            echo $info[0] . ' : ' . $info[2];
        } catch (\Exception $e) {
            echo $e->getCode() . ' : ' . $e->getMessage();
        }
    }

    private function updatePlayerInfo($info, $data)
    {
        $url = 'player/update/';
        $userdata = PtAccount::where('member_id', '!=', '-999999999')->get();
        foreach ($userdata as $row) {
            $data['playername'] = $row['username'];
            ksort($data);
            $result = $this->post($info, $data, $url);
            if (is_array($result) && !isset($result['errorcode']) && isset($result['result'])) {
                echo '****' . $row['username'] . '****';
            } else {
                echo '!!!!' . $row['username'] . '!!!!';
            }
        }
    }

    private function post($info, $data, $url)
    {
        Common::log(0, $data, 'pt_info_' . $url);
        $path = base_path();
        $header = array();
        $header[] = "Accept:text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8";
        $header[] = "Cache-Control: max-age=0";
        $header[] = "Connection: keep-alive";
        $header[] = "Keep-Alive:timeout=5, max=100";
        $header[] = "Accept-Charset:ISO-8859-1,utf-8;q=0.7,*;q=0.3";
        $header[] = "Accept-Language:es-ES,es;q=0.8";
        $header[] = "Pragma: ";
        $header[] = "X_ENTITY_KEY: " . $info->ENTITY_KEY;
        $tuCurl = curl_init();
        echo $info->HOST . $url . str_replace(['=', '&'], '/', http_build_query($data)) . PHP_EOL;
        curl_setopt($tuCurl, CURLOPT_URL, $info->HOST . $url . str_replace(['=', '&'], '/', http_build_query($data)));
        curl_setopt($tuCurl, CURLOPT_PORT, 443);
        curl_setopt($tuCurl, CURLOPT_VERBOSE, 0);
        curl_setopt($tuCurl, CURLOPT_HTTPHEADER, $header);
        curl_setopt($tuCurl, CURLOPT_SSL_VERIFYPEER, 0);
        curl_setopt($tuCurl, CURLOPT_SSL_VERIFYHOST, 0);
        curl_setopt($tuCurl, CURLOPT_SSLCERT, $path . $info->CERT_PATH . '.pem');
        curl_setopt($tuCurl, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($tuCurl, CURLOPT_SSLKEY, $path . $info->CERT_PATH . '.key');
        $result = curl_exec($tuCurl);
        curl_close($tuCurl);
        Common::log(0, ['request' => $data, 'response' => $result], 'pt_info_' . $url);
        return json_decode($result, true);
    }
}