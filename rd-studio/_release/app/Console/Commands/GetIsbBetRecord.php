<?php

namespace App\Console\Commands;

use App\Helpers\VendorSend;
use Illuminate\Console\Command;
use App\Helpers\Common;
use App\Models\VendorInfo;
use App\Models\IsbBetRecord;
use DB;

class GetIsbBetRecord extends Command
{

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'isb_bet_record {--start=}'; //php artisan isb_bet_record --start=2019-10-03@05:00，每次跑十分鐘的記錄

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '撈取iSB投注記錄';

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
            $start = $this->option('start')
                ? date('Y-m-d H:i:00', strtotime(str_replace('@', ' ', $this->option('start')) . ':00'))
                : date('Y-m-d H:i:00', strtotime('-30 minutes'));
            Common::log(0, 'start:' . $start, 'isb_bet_record');
            $settings = VendorInfo::where('vendor_id', 14)->where('status', 1)->get();
            $used = []; //已執行過的不再執行
            foreach ($settings as $row) {
                $info = Common::decryptVendorInfo($row);
                if (is_object($info) && !in_array($info->LICENSEE_ID, $used)) {
                    echo '======' . $row->name . '======' . PHP_EOL;
                    echo 'start:' . $info->LICENSEE_ID . PHP_EOL;
                    // $start = '2020-01-14 03:06:00';
                    // $start = '2020-01-14 07:00:22';
                    // $start = '2020-01-14 07:23:00';
                    // $start = '2020-01-14 09:39:00';
                    //一次跑十分鐘
                    for ($i = 0; $i < 30; $i++) {
                        $time = date('Y-m-d H:i:00', strtotime($start . '+' . $i . ' minutes'));
                        var_dump($time);
                        // $data['operator'] = 0; //0為MASTER，operator需於後台做設定
                        $data['action'] = [
                            'command' => 'wagersFeed',
                            'parameters' => [
                                'datetime' => $time,
                                'page' => 1
                            ]
                        ];
                        $this->addRecord($info, $data);
                    }
                    $used[] = $info->LICENSEE_ID;
                    echo 'end:' . $info->LICENSEE_ID . PHP_EOL;
                }
            }

            //total bet record
            echo '===== total bet record Start =====' . PHP_EOL;
            DB::select('call SP_transfer_bet_record_isb(null, null, null)');
            echo '===== total bet record End =====' . PHP_EOL;

            Common::log(0, 'start:' . $start, 'isb_bet_record');
        } catch (\Illuminate\Database\QueryException $e) {
            $info = $e->errorInfo;
            echo $info[0] . ' : ' . $info[2];
        } catch (\Exception $e) {
            echo $e->getCode() . ' : ' . $e->getMessage();
        }
    }

    private function addRecord($info, $postData)
    {
        $hash = hash_hmac('SHA256', json_encode($postData), $info->KEY);
        $url = $info->HOST . $info->VERSION .  '/' . $info->LICENSEE_ID . '?hash=' . $hash;
        $header = array('Content-Type:application/json;charset=utf-8');
        $result = json_decode($this->vendorsend->send('isb_get_bet_record', $url, 'POST', $postData, $header), true);
        if (is_array($result) && $result['status'] == 'success') {
            foreach ($result['report'] as $row) {
                $data = array();
                $data['playerid'] = $row['playerid'];
                $data['operator'] = $row['operator'];
                $data['currency'] = $row['currency'];

                foreach ($row['sessions'] as $session) {
                    $data['sessionid'] = $session['sessionid'];
                    $data['gameid'] = $session['gameid'];
                    foreach ($session['rounds'] as $round) {
                        $data['roundid'] = $round['roundid'];
                        $data['status'] = $round['status'];
                        $data['channel'] = $round['channel'];
                        foreach ($round['transactions'] as $transaction) {
                            $data['type'] = $transaction['type'];
                            $data['transactionid'] = $transaction['transactionid'];
                            $data['time'] = $transaction['time'];
                            $data['amount'] = $transaction['amount'];
                            $data['balance'] = $transaction['balance'];
                            $data['jpc'] = isset($transaction['jpc']) ? $transaction['jpc'] : 0;
                            $data['jpw'] = isset($transaction['jpw']) ? $transaction['jpw'] : 0;
                            $data['jpw_jpc'] = isset($transaction['jpw_jpc']) ? $transaction['jpw_jpc'] : 0;
                            $record = IsbBetRecord::where('transactionid', $transaction['transactionid'])->first();
                            if (!is_object($record)) {
                                IsbBetRecord::create($data);
                                echo '****' . $transaction['transactionid'] . '****' . PHP_EOL;
                            }
                        }
                    }
                }
            }
            //如果目前頁數少於總頁數，需再取下一頁資料
            if ($result['currentpage'] < $result['pages']) {
                $postData['action']['parameters']['page']++;
                $this->addRecord($info, $postData);
            }
        }
    }

    private function post($info, $data)
    {
        Common::log(0, $data, 'isb_bet_record');
        $hash = hash_hmac('SHA256', json_encode($data), $info->KEY);
        $url = $info->HOST . $info->VERSION .  '/' . $info->LICENSEE_ID . '?hash=' . $hash;

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL,  $url);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_POST, TRUE);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Content-Type:application/json;charset=utf-8',
        ));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        $result = curl_exec($ch);
        curl_close($ch);
        Common::log(0, ['url' => $url, 'request' => $data, 'response' => $result], 'isb_bet_record');
        return json_decode($result, true);
    }
}