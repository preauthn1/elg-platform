<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Helpers\Common;
use App\Services\TelegramService;
use App\Models\SmsSmsGet;
use App\SmsInfobipApi;

class WarningSmsGetBalance extends Command
{

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'sms_get_balance';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'sms-get餘額查詢';

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
            $result = $this->postToSmsGet('api_query_credit.php', []);
            if (is_array($result) && isset($result['error_msg'])) {
                list($username, $balance) = explode('|', $result['error_msg']);
                if ($balance < 100) {
                    // 886922066886(Eddie)，886937027825(Rory)
                    //$mobiles = ['886922066886','886937027825'];
                    $mobiles = [];
                    $content = '【SMS-Get餘額不足】 目前餘額為：' . $balance;
                    //SMS
                    $sms = new SmsInfobipApi();
                    foreach ($mobiles as $mobile) {
                        $result = $sms->send($mobile, $content);
                    }
                    echo $content . PHP_EOL;
                    //IM
                    $this->tgService->sendMessage($content, 'info');
                }
            }
        } catch (\Illuminate\Database\QueryException $e) {
            $info = $e->errorInfo;
            echo $info[0] . ' : ' . $info[2];
        } catch (\Exception $e) {
            echo $e->getCode() . ' : ' . $e->getMessage();
        }
    }

    private function postToSmsGet($url, $data)
    {
        $data['username'] = env('SMS_GET_USERNAME');
        $data['password'] = env('SMS_GET_PASSWORD');

        Common::log(0, $data, 'sms-get');

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, env('SMS_GET_HOST') . $url);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        curl_setopt($ch, CURLOPT_POST, TRUE);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        $result = curl_exec($ch);
        Common::log(0, ['request' => $data, 'response' => $result], 'sms-get');
        curl_close($ch);
        return json_decode($result, true);
    }
}