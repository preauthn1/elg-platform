<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use App\Helpers\Common;
use App\Models\Param;
use App\Models\AgBetRecord;
use App\Models\VendorInfo;
use DB;

class GetAgBetRecordDaily extends Command
{

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'ag_bet_record_daily {--start=} {--platform=}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '寫入AG遊戲歷程(每日補跑一次，避免漏帳)';

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
        Common::log(0, 'start', 'ag_bet_record_daily');

        $settings = VendorInfo::where('vendor_id', 1)->where('status', 1)->get();
        $used = []; //已執行過的不再執行
        foreach ($settings as $row) {
            //將遊戲商資訊解密
            $info = Common::decryptVendorInfo($row);
            if (is_object($info) && !in_array($info->FTP_USERNAME, $used)) {
                echo '======' . $row->name . '======' . PHP_EOL;
                echo 'start:' . $info->FTP_USERNAME . PHP_EOL;
                $this->getRecord($info);
                $used[] = $info->FTP_USERNAME;
                echo 'end:' . $info->FTP_USERNAME . PHP_EOL;
            }
        }
        //total bet record
        echo '===== total bet record Start =====' . PHP_EOL;
        DB::select('call SP_transfer_bet_record_ag(null, null, null)');
        echo '===== total bet record End =====' . PHP_EOL;
        Common::log(0, 'end', 'ag_bet_record_daily');
    }

    private function getRecord($info)
    {
        $ftp = Storage::createFtpDriver([
            'host' => $info->FTP_URL,
            'username' => $info->FTP_USERNAME,
            'password' => $info->FTP_PASSWORD,
            'port' => '21',
            'timeout' => '300',
            // 'passive' => false,
        ]);
        $folders = array(
            'AGIN',
            'MG',
            'SBTA',
            'XIN'
        );
        $date = $this->option('start') ? $this->option('start') : date('Ymd', strtotime('-1day'));
        $platform = $this->option('platform') ? $this->option('platform') : '';
        foreach ($folders as $folder) {
            if ($platform == '' || $platform == $folder) {
                $directory = '/' . $folder . '/' . $date; //AG時區為-4，遊戲資料也差有時差，所以多撈一次，還有另一隻會補撈所有
                // 取當天所有的檔案路徑
                $directories = $ftp->files($directory);
                foreach ($directories as $path) {
                    echo sprintf("*** get file %s ***", $path) . PHP_EOL;
                    // 讀取內容
                    $xml = $ftp->get($path);
                    $array = explode("\n", $xml);
                    echo sprintf("*** get file %s count %d finish ***", $path, count($array)) . PHP_EOL;
                    //var_dump($array);
                    // 每行轉成object
                    foreach ($array as $row) {
                        $row = (array) simplexml_load_string($row);

                        //轉換比率; 當幣別是VND, rate直接設定為0.001, 不取vendor_infos的rate
                        $rate = (isset($row['@attributes']['currency']) && trim($row['@attributes']['currency']) == 'VND')
                            ? 0.001
                            : 1;
                        $data = array();
                        // 寫入數據庫
                        //'beforeAmount' => floatval($r['beforeAmount'] / $row->rate),
                        if (isset($row['@attributes'])) {
                            $data['data_type'] = isset($row['@attributes']['dataType']) ? $row['@attributes']['dataType'] : '';
                            $data['bill_no'] = isset($row['@attributes']['billNo']) ? $row['@attributes']['billNo'] : '';
                            $data['player_name'] = isset($row['@attributes']['playerName']) ? $row['@attributes']['playerName'] : '';
                            $data['agent_code'] = isset($row['@attributes']['agentCode']) ? $row['@attributes']['agentCode'] : '';
                            $data['game_code'] = isset($row['@attributes']['gameCode']) ? $row['@attributes']['gameCode'] : '';
                            $data['net_amount'] = isset($row['@attributes']['netAmount'])
                                ? floatval($row['@attributes']['netAmount'] / $rate)
                                : '';
                            $data['bet_time'] = isset($row['@attributes']['betTime']) ? $row['@attributes']['betTime'] : '';
                            $data['utc_bet_time'] = isset($row['@attributes']['betTime']) ? date('Y-m-d H:i:s', strtotime($row['@attributes']['betTime'] . ' +4hour')) : '';
                            $data['game_type'] = isset($row['@attributes']['gameType']) ? $row['@attributes']['gameType'] : '';
                            $data['bet_amount'] = isset($row['@attributes']['betAmount'])
                                ? floatval($row['@attributes']['betAmount'] / $rate)
                                : '';
                            $data['valid_bet_amount'] = isset($row['@attributes']['validBetAmount'])
                                ? floatval($row['@attributes']['validBetAmount'] / $rate)
                                : '';
                            $data['flag'] = isset($row['@attributes']['flag']) ? $row['@attributes']['flag'] : '';
                            $data['play_type'] = isset($row['@attributes']['playType']) ? $row['@attributes']['playType'] : '';
                            $data['currency'] = isset($row['@attributes']['currency']) ? $row['@attributes']['currency'] : '';
                            $data['table_code'] = isset($row['@attributes']['tableCode']) ? $row['@attributes']['tableCode'] : '';
                            $data['login_ip'] = isset($row['@attributes']['loginIP']) ? $row['@attributes']['loginIP'] : '';
                            $data['recalcu_time'] = isset($row['@attributes']['recalcuTime']) ? $row['@attributes']['recalcuTime'] : '';
                            $data['utc_recalcu_time'] = isset($row['@attributes']['recalcuTime']) ? date('Y-m-d H:i:s', strtotime($row['@attributes']['recalcuTime'] . ' +4hour')) : '';
                            $data['platform_type'] = isset($row['@attributes']['platformType']) ? $row['@attributes']['platformType'] : '';
                            $data['remark'] = isset($row['@attributes']['remark']) ? $row['@attributes']['remark'] : '';
                            $data['round'] = isset($row['@attributes']['round']) ? $row['@attributes']['round'] : '';
                            $data['result'] = isset($row['@attributes']['result']) ? $row['@attributes']['result'] : '';
                            $data['before_credit'] = (isset($row['@attributes']['beforeCredit']) && trim($row['@attributes']['beforeCredit']) != '')
                                ? floatval($row['@attributes']['beforeCredit'] / $rate)
                                : '';
                            $data['device_type'] = isset($row['@attributes']['deviceType']) ? $row['@attributes']['deviceType'] : '';
                            $data['game_category'] = isset($row['@attributes']['gameCategory']) ? $row['@attributes']['gameCategory'] : '';
                            $data['cancel_reason'] = isset($row['@attributes']['cancelReason']) ? $row['@attributes']['cancelReason'] : '';
                            $data['admin'] = 0;
                            $record = AgBetRecord::where('bill_no', $data['bill_no'])->first();
                            if (!is_object($record)) {
                                AgBetRecord::create($data);
                                echo '*****' . $data['bill_no'] . '*****' . PHP_EOL;
                            } else {
                                // 如果有重新派彩同一個單號會有新的結算時間則需要更新
                                if (strtotime($record->recalcu_time) < strtotime($data['recalcu_time'])) {
                                    $record->update($data);
                                    echo '!!!!!!!' . $data['bill_no'] . '!!!!!!!' . PHP_EOL;
                                }
                                // 如果記錄中是未結算的就更新(不包含轉帳)
                                if ($record->recalcu_time == '0000-00-00 00:00:00' && $record->data_type != 'TR') {
                                    $record->update($data);
                                    echo '!!!!!!!' . $data['bill_no'] . '!!!!!!!' . PHP_EOL;
                                } else if ($data['flag'] == '-8' && $record->flag != '-8') { //如果後來遊戲被取消
                                    $record->update($data);
                                    echo '!!!!!!!' . $data['bill_no'] . '!!!!!!!' . PHP_EOL;
                                }
                            }
                        }
                    }
                }
            }
        }
    }
}
