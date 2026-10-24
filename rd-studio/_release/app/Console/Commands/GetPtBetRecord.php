<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Helpers\Common;
use App\Models\PtBetRecord;
use App\Models\VendorInfo;
use DB;

class GetPtBetRecord extends Command
{

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'pt_bet_record {--start=}'; //pt_bet_record --start=2019-10-01@00:00

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '更新PT投注記錄(一次撈半小時)';

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
            // 一次只能跑半小時, +8時區
            if ($this->option('start')) {
                $start = str_replace('@', ' ', $this->option('start')) . ':00';
            }
            $from = $this->option('start')
                ? $start
                : date('Y-m-d H:i:00', strtotime('+7hour +20minute'));
            $end = $this->option('start')
                ? date('Y-m-d H:i:59', strtotime($start . ' +29minute'))
                : date('Y-m-d H:i:59', strtotime('+7hour +49minute'));
            echo $from . ' to ' . $end . PHP_EOL;

            $data = array();
            $data['reportname'] = 'PlayerGames';
            $data['startdate'] = $from;
            $data['enddate'] = $end;
            $data['frozen'] = 'all';
            $data['timeperiod'] = 'specify';

            $settings = VendorInfo::where('vendor_id', 17)->where('status', 1)->get();
            $used = []; //已執行過的不再執行

            foreach ($settings as $row) {
                //將遊戲商資訊解密
                $info = Common::decryptVendorInfo($row);
                if (is_object($info) && !in_array($info->ADMIN_NAME, $used)) {
                    $this->addRecord($info, $data, 1);
                    $used[] = $info->ADMIN_NAME;
                } else {
                    echo $row->name . '解密失敗' . PHP_EOL;
                }
            }

            //total bet record
            echo '===== total bet record Start =====' . PHP_EOL;
            DB::select('call SP_transfer_bet_record_pt(null, null, null)');
            echo '===== total bet record End =====' . PHP_EOL;
        } catch (\Illuminate\Database\QueryException $e) {
            $info = $e->errorInfo;
            echo $info[0] . ' : ' . $info[2];
        } catch (\Exception $e) {
            echo $e->getCode() . ' : ' . $e->getMessage();
        }
    }

    private function addRecord($info, $postData, $page)
    {
        $postData['page'] = $page;
        $postData['perPage'] = 1000;
        $url = 'customreport/getdata/';
        $result = $this->post($info, $postData, $url);

        if (is_array($result) && !isset($result['errorcode']) && isset($result['result'])) {
            foreach ($result['result'] as $row) {
                $data = array();
                $data['player_name'] = $row['PLAYERNAME'];
                $data['window_code'] = $row['WINDOWCODE'];
                $data['game_id'] = $row['GAMEID'];
                $data['game_code'] = $row['GAMECODE'];
                $data['game_type'] = $row['GAMETYPE'];
                $data['game_name'] = $row['GAMENAME'];
                $data['session_id'] = $row['SESSIONID'];
                $data['currency_code'] = $row['CURRENCYCODE'];
                $data['bet'] = $row['BET'];
                $data['win'] = $row['WIN'];
                $data['progress_ive_bet'] = $row['PROGRESSIVEBET'];
                $data['progress_ive_win'] = $row['PROGRESSIVEWIN'];
                $data['balance'] = $row['BALANCE'];
                $data['current_bet'] = $row['CURRENTBET'];
                $data['game_date'] = $row['GAMEDATE'];
                $data['utc_game_date'] = date('Y-m-d H:i:s', strtotime($row['GAMEDATE'] . ' -8hour'));
                $data['info'] = isset($row['INFO']) ? $row['INFO'] : '';
                $data['rnum'] = $row['RNUM'];
                $data['live_network'] = $row['LIVENETWORK'];
                $record = PtBetRecord::where('game_code', $row['GAMECODE'])->first();
                if (!is_object($record)) {
                    PtBetRecord::create($data);
                    echo '****' . $row['GAMECODE'] . '****' . PHP_EOL;
                }
            }
            if (isset($result['pagination']['totalPages']) && $result['pagination']['totalPages'] > $page) {
                $this->addRecord($info, $postData, ($page + 1));
            }
        } else {
            echo $result . PHP_EOL;
        }
    }


    private function post($info, $data, $url)
    {
        Common::log(0, $data, 'pt_' . $url);
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
        // curl_setopt($tuCurl, CURLOPT_URL, 'https://example.invalid/customreport/getdata/reportname/PlayerGames/startdate/2020-02-19 11:05:00/enddate/2020-02-19 11:15:00/frozen/all/timeperiod/specify/page/1/perPage/500');
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
        Common::log(0, ['request' => $data, 'response' => $result], 'pt_' . $url);
        return json_decode($result, true);
    }
}