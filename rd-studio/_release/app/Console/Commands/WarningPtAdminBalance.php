<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Helpers\Common;
use App\Services\TelegramService;
use App\Models\VendorInfo;
use App\SmsInfobipApi;

class WarningPtAdminBalance extends Command
{

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'pt_admin_balance';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'PT營運商餘額查詢';

    protected $tgService;

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
        $this->tgService = New TelegramService();
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        try {
            $url = 'admin/list/';
            $settings = VendorInfo::where('vendor_id', 17)->where('status', 1)->get();

            foreach ($settings as $row) {
                //將遊戲商資訊解密
                $info = Common::decryptVendorInfo($row);
                if (is_object($info)) {
                    $result = $this->post($info, $url);
                    if (is_array($result) && isset($result['result'])) {
                        if ($result['result'][0]['depositbalance'] < 9888) {
                            $content = '【PT餘額不足】 ' . $result['result'][0]['entityname'] . '目前餘額為：' . $result['result'][0]['depositbalance'];
                            $this->tgService->sendMessage($content, 'info');
                            echo $content . PHP_EOL;
                        }
                    }
                } else {
                    echo $row->name . '解密失敗'. PHP_EOL;
                }
            }
        } catch (\Illuminate\Database\QueryException $e) {
            $info = $e->errorInfo;
            echo $info[0] . ' : ' . $info[2];
        } catch (\Exception $e) {
            echo $e->getCode() . ' : ' . $e->getMessage();
        }
    }

    private function post($info, $url)
    {
        Common::log(0, '查詢餘額：' . $info->KIOSK_NAME, 'pt_' . $url);
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
        curl_setopt($tuCurl, CURLOPT_URL, $info->HOST . $url);
        curl_setopt($tuCurl, CURLOPT_PORT, 443);
        curl_setopt($tuCurl, CURLOPT_VERBOSE, 0);
        curl_setopt($tuCurl, CURLOPT_HTTPHEADER, $header);
        curl_setopt($tuCurl, CURLOPT_SSL_VERIFYPEER, 0);
        curl_setopt($tuCurl, CURLOPT_SSL_VERIFYHOST, 0);
        curl_setopt($tuCurl, CURLOPT_SSLCERT, $path . $info->CERT_PATH . '.pem');
        curl_setopt($tuCurl, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($tuCurl, CURLOPT_SSLKEY, $path . $info->CERT_PATH .  '.key');
        $result = curl_exec($tuCurl);
        curl_close($tuCurl);
        Common::log(0, ['request' => '查詢餘額：' . $info->KIOSK_NAME, 'response' => $result], 'pt_' . $url);
        return json_decode($result, true);
    }
}