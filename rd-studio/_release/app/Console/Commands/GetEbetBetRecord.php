<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Helpers\VendorSend;
use App\Helpers\Common;
use App\Models\VendorInfo;
use App\Models\EbetBetRecord;
use App\RSA;
use DB;

use function GuzzleHttp\json_encode;

class GetEbetBetRecord extends Command
{

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'ebet_bet_record {--start_date=}'; //ebet_bet_record --start_date=2019-10-01

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '更新Ebet投注記錄';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    protected $vendorsend;

    public function __construct()
    {
        parent::__construct();
        $this->vendorsend = new VendorSend(['json_post' => true]);
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        try {
            //用UTC時間取資料
            // $start = $this->option('start_date') ? str_replace('@', ' ', $this->option('start_date') . ' 00:00:00') : date('Y-m-d H:i:s', strtotime('-1hour'));
            // $end = $this->option('start_date') ? str_replace('@', ' ', $this->option('start_date') . ' 23:59:59') : date('Y-m-d H:i:s');
            $start = $this->option('start_date')
                ? str_replace('@', ' ', $this->option('start_date') . ' 00:00:00')
                : date('Y-m-d H:i:s', strtotime('+7hour'));
            $end = $this->option('start_date')
                ? str_replace('@', ' ', $this->option('start_date') . ' 23:59:59')
                : date('Y-m-d H:i:s', strtotime('+8hour'));
            echo $start . ' to ' . $end . PHP_EOL;

            //取出相關設定
            $settings = VendorInfo::where('vendor_id', 13)->where('status', 1)->get();
            $used = []; //已執行過的不再執行
            foreach ($settings as $row) {
                $info = Common::decryptVendorInfo($row);
                $rate = isset($row->rate) ? $row->rate : 1;
                if (is_object($info)) {
                    if (!in_array($info->CHANNEL_ID, $used)) {
                        echo '======' . $row->name . '======' . PHP_EOL;
                        $data = array();
                        $data['channelId'] = $info->CHANNEL_ID;
                        // $data['startTimeStr'] = date('Y-m-dTH:i:sz', strtotime($start));
                        // $data['endTimeStr'] = date('Y-m-dTH:i:sz', strtotime($end));
                        $data['startTimeStr'] = $start;
                        $data['endTimeStr'] = $end;
                        $data['username'] = ''; //空字串導出所有用戶記錄
                        $data['betStatus'] = 1;
                        $data['judgeTime'] = 0; //派彩時間
                        $this->addRecord($info, $data, 1, $rate);
                        $used[] = $info->CHANNEL_ID;
                    }
                } else {
                    echo $row->name . '解密失敗' . PHP_EOL;
                }
            }

            //total bet record
            echo '===== total bet record Start =====' . PHP_EOL;
            DB::select('call SP_transfer_bet_record_ebet(null, null, null)');
            echo '===== total bet record End =====' . PHP_EOL;
        } catch (\Illuminate\Database\QueryException $e) {
            $info = $e->errorInfo;
            echo $info[0] . ' : ' . $info[2];
        } catch (\Exception $e) {
            echo $e->getCode() . ' : ' . $e->getMessage();
        }
    }

    private function addRecord($info, $postData, $page, $rate = 1)
    {
        $timestamp = date('U');
        $postData['timestamp'] = $timestamp;
        $postData['signature'] = $this->getSign($timestamp, $info->PRIVATE_KEY);
        $postData['pageNum'] = $page;
        $postData['pageSize'] = 1000; //上限為5000
        $url = $info->API_URL . 'userbethistory';
        $header = array('Content-Type:application/json');
        $result = json_decode($this->vendorsend->send('Ebet_get_bet_record', $url, 'POST', $postData, $header), true);
        if (is_array($result) && $result['status'] == '200') {
            foreach ($result['betHistories'] as $row) {
                $data = array();
                $data['username'] = $row['username'];
                $data['userId'] = $row['userId'];
                //issues_918 新增老虎機
                $data['gameType'] = $row['gameType'] == 5 ? $row['gameName'] : $row['gameType'];
                $data['gameName'] = $row['gameName'];
                $data['betHistoryId'] = $row['betHistoryId'];
                $data['bet'] = floatval($row['bet'] / $rate);
                $data['validBet'] = floatval($row['validBet'] / $rate);
                $data['payout'] = floatval($row['payout'] / $rate);
                $data['balance'] = floatval($row['balance'] / $rate);
                $data['payoutWithoutholding'] = isset($row['payoutWithoutholding']) ? floatval($row['payoutWithoutholding'] / $rate) : 0;
                $data['niuniuWithHoldingTotal'] = isset($row['niuniuWithHoldingTotal']) ? floatval($row['niuniuWithHoldingTotal'] / $rate) : 0;
                $data['betMap'] = isset($row['betMap']) ? json_encode($row['betMap']) : '';
                $data['roundNo'] = isset($row['roundNo']) ? $row['roundNo'] : '';
                $data['payoutDetail'] = isset($row['payoutDetail']) ? json_encode($row['payoutDetail']) : '';
                $data['judgeResult'] = isset($row['judgeResult']) ? json_encode($row['judgeResult']) : '';
                $data['playerCards'] = isset($row['playerCards']) ? json_encode($row['playerCards']) : '';
                $data['playerResult'] = isset($row['playerResult']) ? $row['playerResult'] : 0;
                $data['bankerCards'] = isset($row['bankerCards']) ? json_encode($row['bankerCards']) : '';
                $data['bankerResult'] = isset($row['bankerResult']) ? $row['bankerResult'] : 0;
                $data['allDices'] = isset($row['allDices']) ? json_encode($row['allDices']) : '';
                $data['dragonCard'] = isset($row['dragonCard']) ? $row['dragonCard'] : 0;
                $data['tigerCard'] = isset($row['tigerCard']) ? $row['tigerCard'] : 0;
                $data['number'] = isset($row['number']) ? $row['number'] : 0;
                $data['niuniuResult'] = isset($row['niuniuResult']) ? json_encode($row['niuniuResult']) : '';
                $data['niuniuWithHoldingDetail'] = isset($row['niuniuWithHoldingDetail']) ? json_encode($row['niuniuWithHoldingDetail']) : '';
                $data['createTime'] = $row['createTime'];
                $data['utc_create_time'] = date('Y-m-d H:i:s', $row['createTime']);
                $data['payoutTime'] = $row['payoutTime'];
                $data['utc_payout_time'] = date('Y-m-d H:i:s', $row['payoutTime']);
                $data['platform'] = $row['platform'];

                $record = EbetBetRecord::where('betHistoryId', $row['betHistoryId'])->first();
                if (!is_object($record)) {
                    EbetBetRecord::create($data);
                    echo  '****' . $row['betHistoryId'] . '****';
                }
            }
            if ($result['count'] > ($page * $postData['pageSize'])) {
                $this->addRecord($info, $postData, ($page + 1), $rate);
            }
        } else {
            echo $result;
        }
    }

    private function getSign($string, $key)
    {
        $rsa = new RSA('', str_replace('\\n', "\n", $key));
        return $rsa->sign($string);
    }

    private function post($info, $data, $url)
    {
        $requestbody = json_encode($data);
        Common::log(0, $data, 'ebet_bet_record');

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $info->API_URL . $url);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $requestbody);
        curl_setopt($ch, CURLOPT_POST, TRUE);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Content-Type:application/json'
        ));
        $result = curl_exec($ch);
        curl_close($ch);
        Common::log(0, $result, 'ebet_bet_record');
        return json_decode($result, true);
    }
}