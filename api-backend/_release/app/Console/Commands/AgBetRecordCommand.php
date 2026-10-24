<?php
namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use App\AgBetRecord;

class AgBetRecordCommand extends Command
{

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'ag_bet_record';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '寫入AG遊戲歷程';

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
        $ftp = Storage::createFtpDriver([
            'host' => env('AG_FTP_URL', null),
            'username' => env('AG_FTP_USERNAME', null),
            'password' => env('AG_FTP_PASSWORD', null),
            'port' => '21',
            'timeout' => '300'
        ]);
        $folders = array(
            'AGIN',
            'MG',
            'SBTA',
            'XIN'
        );
        foreach ($folders as $folder) {
            $directory = '/' . $folder . '/' . date('Ymd', strtotime('-1days'));
            // 取當天所有的檔案路徑
            $directories = $ftp->files($directory);
            foreach ($directories as $path) {
                // 讀取內容
                $xml = $ftp->get($path);
                $array = explode("\n", $xml);
                // 每行轉成object
                foreach ($array as $row) {
                    $row = (array) simplexml_load_string($row);
                    $data = array();
                    // 寫入數據庫
                    if (isset($row['@attributes'])) {
                        $data['data_type'] = isset($row['@attributes']['dataType']) ? $row['@attributes']['dataType'] : '';
                        $data['bill_no'] = isset($row['@attributes']['billNo']) ? $row['@attributes']['billNo'] : '';
                        $data['player_name'] = isset($row['@attributes']['playerName']) ? $row['@attributes']['playerName'] : '';
                        $data['agent_code'] = isset($row['@attributes']['agentCode']) ? $row['@attributes']['agentCode'] : '';
                        $data['game_code'] = isset($row['@attributes']['gameCode']) ? $row['@attributes']['gameCode'] : '';
                        $data['net_amount'] = isset($row['@attributes']['netAmount']) ? $row['@attributes']['netAmount'] : '';
                        $data['bet_time'] = isset($row['@attributes']['betTime']) ? $row['@attributes']['betTime'] : '';
                        $data['bet_amount'] = isset($row['@attributes']['betAmount']) ? $row['@attributes']['betAmount'] : '';
                        $data['valid_bet_amount'] = isset($row['@attributes']['validBetAmount']) ? $row['@attributes']['validBetAmount'] : '';
                        $data['flag'] = isset($row['@attributes']['flag']) ? $row['@attributes']['flag'] : '';
                        $data['play_type'] = isset($row['@attributes']['playType']) ? $row['@attributes']['playType'] : '';
                        $data['currency'] = isset($row['@attributes']['currency']) ? $row['@attributes']['currency'] : '';
                        $data['table_code'] = isset($row['@attributes']['tableCode']) ? $row['@attributes']['tableCode'] : '';
                        $data['login_ip'] = isset($row['@attributes']['loginIP']) ? $row['@attributes']['loginIP'] : '';
                        $data['recalcu_time'] = isset($row['@attributes']['recalcuTime']) ? $row['@attributes']['recalcuTime'] : '';
                        $data['platform_type'] = isset($row['@attributes']['platformType']) ? $row['@attributes']['platformType'] : '';
                        $data['remark'] = isset($row['@attributes']['remark']) ? $row['@attributes']['remark'] : '';
                        $data['round'] = isset($row['@attributes']['round']) ? $row['@attributes']['round'] : '';
                        $data['result'] = isset($row['@attributes']['result']) ? $row['@attributes']['result'] : '';
                        $data['before_credit'] = isset($row['@attributes']['beforeCredit']) ? $row['@attributes']['beforeCredit'] : '';
                        $data['device_type'] = isset($row['@attributes']['deviceType']) ? $row['@attributes']['deviceType'] : '';
                        $data['game_category'] = isset($row['@attributes']['gameCategory']) ? $row['@attributes']['gameCategory'] : '';
                        $data['cancel_reason'] = isset($row['@attributes']['cancelReason']) ? $row['@attributes']['cancelReason'] : '';
                        $data['admin'] = 0;
                        AgBetRecord::create($data);
                        var_dump($data['bill_no']);
                    }
                }
            }
        }
    }
}
