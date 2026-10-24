<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Helpers\VendorSend;
use App\Helpers\Common;
use App\Models\VendorInfo;
use App\Models\Ace333BetRecord;
use DB;

class GetAce333BetRecord extends Command
{

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'ace333_bet_record {--start=}'; //php artisan ace333_bet_record --start=2019-10-03@05

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '撈取ace333投注記錄';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    protected $vendorsend;
    public function __construct()
    {
        parent::__construct();
        $this->vendorsend = new VendorSend();
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        try {
            $timepoint = $this->option('start')
                ? date('U000', strtotime(str_replace('@', ' ', $this->option('start')) . ':00:00'))
                : date('U000', strtotime('-20minute'));
            echo '-----' . $this->option('start') . '-----' . PHP_EOL;
            echo '-----' . $timepoint . '-----' . PHP_EOL;

            $settings = VendorInfo::where('vendor_id', 6)->where('status', 1)->get();
            $used = []; //已執行過的不再執行

            if (count($settings) > 0) {
                foreach ($settings as $setting) {
                    $info = json_decode($setting->info);
                    echo $setting->name . PHP_EOL;
                    if (is_object($info) && !in_array($info->EXTERNAL_TOKEN, $used)) {
                        $url = 'api/accounttransactions2';
                        $url = $info->API_URL . $url;
                        $data = array('timepoint' => $timepoint);
                        $header = array('token:' . $info->TOKEN);
                        $result = explode(PHP_EOL, $this->vendorsend->send('ace333_get_bet_record', $url, 'GET', $data, $header));
//            $result = $this->post($url, $timepoint);
                        if (is_array($result)) {
                            $i = 0;
                            foreach ($result as $row) {
                                //第一行是time point, 第二行是title, 第三行開始才是資料
                                if ($i > 1) {
                                    //playerID,extPlayerID,gameID,transactionType,referenceID,status,created,updated,betAmount,winAmount,jackpotModule,jackpotContributionAmt,currency,resultUrl,roundDetails,platform
                                    $row = explode(',', $row);
                                    $data = array();
                                    $data = [
                                        'playerID' => $row[0],
                                        'extPlayerID' => $row[1],
                                        'gameID' => str_replace('"', '', $row[2]),
                                        'transactionType' => str_replace('"', '', $row[3]),
                                        'referenceID' => str_replace('"', '', $row[4]),
                                        'status' => str_replace('"', '', $row[5]),
                                        'created' => date('Y-m-d H:i:s', strtotime(str_replace('"', '', $row[6]))),
                                        'updated' => date('Y-m-d H:i:s', strtotime(str_replace('"', '', $row[7]))),
                                        'betAmount' => $row[8],
                                        'winAmount' => $row[9],
                                        'jackpotModule' => $row[10],
                                        'jackpotContributionAmt' => $row[11],
                                        'currency' => str_replace('"', '', $row[12]),
                                        'resultUrl' => str_replace('"', '', $row[13]),
                                        'roundDetails' => str_replace('"', '', $row[14]),
                                        'platform' => str_replace('"', '', $row[15])
                                    ];
                                    $record = Ace333BetRecord::where('referenceID', str_replace('"', '', $row[4]))->first();
                                    if (!is_object($record)) {
                                        Ace333BetRecord::create($data);
                                        echo '******' . $data['referenceID'] . '******' . PHP_EOL;
                                    }
                                    echo '------' . $data['referenceID'] . '-----' . PHP_EOL;
                                }
                                $i++;
                            }
                        } else {
                            var_dump($result);
                        }
                    }
                    $used[] = $info->EXTERNAL_TOKEN;
                }
            }
            //total bet record
            echo '===== total bet record Start =====' . PHP_EOL;
            DB::select('call SP_transfer_bet_record_ace333(null, null, null)');
            echo '===== total bet record End =====' . PHP_EOL;
        } catch (\Illuminate\Database\QueryException $e) {
            $info = $e->errorInfo;
            echo $info[0] . ' : ' . $info[2];
        } catch (\Exception $e) {
            echo $e->getCode() . ' : ' . $e->getMessage();
        }
    }

//    private function post($url, $timepoint)
//    {
//        Common::log(0, $url, 'get_ace333_bet_record');
//        $ch = curl_init();
//        curl_setopt($ch, CURLOPT_URL, env('ACE333_URL') . $url . '?' . http_build_query(['timepoint' => $timepoint]));
//        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
//            'token:' . env('ACE333_TOKEN'),
//        ));
//        curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
//        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
//        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
//        $result = curl_exec($ch);
//        Common::log(0, $result, 'get_ace333_bet_record');
//        curl_close($ch);
//        return explode(PHP_EOL, $result); //依換行符號切成array
//    }
}