<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Helpers\Common;
use App\Models\Game;
use App\Models\GameType;
use App\Models\VendorInfo;
use DB;

class GetJokerJackpot extends Command
{

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'get_joker_jackpot';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '更新Joker遊戲彩金';

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
            $settings = VendorInfo::where('vendor_id', 2)->where('status', 1)->first();
            $used = []; //已執行過的不再執行
            $info = json_decode($settings->info);
            foreach ($settings as $row) {
                $info = Common::decryptVendorInfo($row);
                if (is_object($info) && !in_array($info->APP_ID, $used)) {
                    echo '======' . $row->name . '======' . PHP_EOL;
                    // 取新遊戲
                    $data = array();
                    $data['Method'] = 'GJP';
                    $data['Timestamp'] = date('U');
                    ksort($data);
                    $this->postToVendor($data, $info, 0);
                    $used[] = $info->APP_ID;
                }
            }
        } catch (\Illuminate\Database\QueryException $e) {
            $info = $e->errorInfo;
            echo $info[0] . ' : ' . $info[2];
        } catch (\Exception $e) {
            echo $e->getCode() . ' : ' . $e->getMessage();
        }
    }

    private function postToVendor($postData, $info, $num)
    {
        $result = $this->post($postData, $info);
        if (is_array($result) && isset($result['Jackpots'])) {
            foreach ($result['Jackpots'] as $row) {
                $game = Game::where('game_type', $row['GameCode'])->first();
                if (is_object($game)) {
                    $game->update(['lottery_pool' => $row['Amount']]);
                    echo $row['GameCode'];
                }
            }
        } else {
            //超過3次, 不再請求
            if ($num < 3) {
                echo $result['Message'];
                sleep(1);
                $postData['Timestamp'] = date('U');
                ksort($postData);
                $this->postToVendor($postData, $info, ++$num);
            }
        }
    }
    /**
     * -取簽名
     */
    private function getSign($data, $info)
    {
        $string = '';
        foreach ($data as $key => $value) {
            $string .= $key . '=' . $value . '&';
        }
        $string = substr($string, 0, -1);
        $hash_hmac = hash_hmac("sha1", $string, $info->SECRET, true);
        return base64_encode($hash_hmac);
    }

    private function post($data, $info)
    {
        Common::log(0, $data, 'get_joker_jackpot');
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $info->API_URL . '?AppID=' . $info->APP_ID . '&Signature=' . $this->getSign($data, $info));
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        curl_setopt($ch, CURLOPT_POST, TRUE);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        $result = curl_exec($ch);
        curl_close($ch);
        Common::log(0, $result, 'get_joker_jackpot');
        return json_decode($result, true);
    }
}