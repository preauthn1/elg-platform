<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Helpers\Common;
use App\Models\VendorInfo;
use App\Models\NptBetRecord;
use DB;
use SebastianBergmann\CodeCoverage\Report\PHP;

class GetNptBetRecord extends Command
{

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'npt_bet_record {--start=}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '更新Npt投注記錄';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    protected $vendor_id = 41;
    protected $action = 'npt_get_bet_record';

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
            echo $this->description . PHP_EOL;
            // 一次只能跑半小時, +8時區
            if ($this->option('start')) {
                $start = str_replace('@', ' ', $this->option('start')) . ':00';
            }
            $start_time = $this->option('start')
                ? $start
                : date('Y-m-d H:i:s', strtotime('+7hour +30minute'));
            $end_time = $this->option('start')
                ? date('Y-m-d H:i:s', strtotime($start . ' +30minute'))
                : date('Y-m-d H:i:s', strtotime('+8hour'));

            echo $start_time . ' to ' . $end_time . PHP_EOL;

            $settings = VendorInfo::where('vendor_id', $this->vendor_id)
                ->where('status', 1)
                ->get();
            $used = []; //已執行過的不再執行
            if (count($settings) > 0) {
                foreach ($settings as $setting) {
                    //將遊戲商資訊解密
                    $info = Common::decryptVendorInfo($setting);
                    if (is_object($info) && !in_array($info->ADMIN_NAME, $used)) {
                        $records = $this->getBetRecord($info, $start_time, $end_time);
                        echo sprintf("%s (%s) records %d" . PHP_EOL, $setting->name, $info->ADMIN_NAME, count($records));
                        if (count($records) > 0) {
                            $this->addRecord($records);
                        }
                        $used[] = $info->ADMIN_NAME;
                    } else {
                        echo $setting->name . '解密失敗' . PHP_EOL;
                    }
                }
                //total bet record
                echo '===== total bet record Start =====' . PHP_EOL;
                DB::select('call SP_transfer_bet_record_npt(null, null, null)');
                echo '===== total bet record End =====' . PHP_EOL;
            }
        } catch (\Illuminate\Database\QueryException $e) {
            $info = $e->errorInfo;
            echo $info[0] . ' : ' . $info[2];
        } catch (\Exception $e) {
            echo $e->getCode() . ' : ' . $e->getMessage();
        }
    }

    //獲取目標時間段前一小時到後一小時
    private function getBetRecord($info, $start_time, $end_time)
    {
        $records = array();
        $pageIndex = 1;
        do {
            $nextLoop = false;
            $result = $this->getBetRecordByPage($info, $start_time, $end_time, $pageIndex);
            if (is_array($result) && !isset($result['error'])) {
                array_push($records, ...$result['result']);
                if (($result['pagination']['totalPages']) > $pageIndex) {
                    $nextLoop = true;
                    $pageIndex++;
                }
            }
        } while ($nextLoop);
        return $records;
    }

    private function getBetRecordByPage($info, $start_time, $end_time, $pageIndex)
    {
        $url = $info->API_URL . '/game/flow';

        $data = array(
            "startdate" => $start_time,
            "enddate" => $end_time,
            "page" => $pageIndex,
            "perPage" => 1000,
            "showgameshortname" => 1

        );

        $response = $response = $this->post($data, $info, $url);

        return $response;
    }

    private function addRecord($records)
    {
        $datas = array();
        foreach ($records as $row) {
                $data = array();
                $data['bet_id'] = $row['GAMECODE'];
                $data['game_code'] = $row['SHORTNAME'];
                $data['username'] = $row['PLAYERNAME'];
                $data['bet'] = $row['BET'];
                $data['win_lose'] = ($row['WIN'] - $row['BET']);
                $data['bet_time'] = date('Y-m-d H:i:s', strtotime($row['GAMEDATE'] . '-8hour'));
                $data['info'] = json_encode($row);
                $datas[] = $data;
        }
        $create_records = $this->filterRecordsNotExists($datas);
        //直接一次insert
        NptBetRecord::insert($create_records);
        echo 'success: ';
        printf("*recordCount [%d] writeCount [%d] ", count($datas), count($create_records));
        echo PHP_EOL;

    }

    /**
     * 過濾掉己經存在的押注記錄
     * @param $datas
     * @param int $size
     * @return array
     */
    private function filterRecordsNotExists($datas, $size = 100)
    {
        $exists_ids = array();
        $collect = collect($datas);
        $chunks = $collect->chunk($size); // 分組，一組 $size  個
        $unique_field = 'bet_id';
        // 分組取出己經存在的押注記錄
        foreach ($chunks as $chunk) {
            $ids = $chunk->pluck($unique_field)->toArray();
            array_push(
                $exists_ids,
                ...NptBetRecord::whereIn($unique_field, $ids)
                ->select($unique_field)
                ->get()
                ->pluck($unique_field)
                ->toArray()
            );
        }

        // 過濾掉己經存在的押注記錄
        return $collect->filter(function ($item) use ($unique_field, $exists_ids) {
            return !in_array($item[$unique_field], $exists_ids);
        })
            ->all();
    }

    private function post($data, $info, $url)
    {
        Common::log(0, ['data' => $data, 'info' => $info], 'npt_' . $url);

        $postFile = http_build_query($data);

        $path = base_path();
        $header[] = "X_ENTITY_KEY: " . $info->ENTITY_KEY;
        $header[] = "Content-Type: application/x-www-form-urlencoded";

        $tuCurl = curl_init();
        curl_setopt($tuCurl, CURLOPT_URL, $url);
        curl_setopt($tuCurl, CURLOPT_VERBOSE, 0);
        curl_setopt($tuCurl, CURLOPT_HTTPHEADER, $header);
        curl_setopt($tuCurl, CURLOPT_SSL_VERIFYPEER, 0);
        curl_setopt($tuCurl, CURLOPT_SSL_VERIFYHOST, 0);
        curl_setopt($tuCurl, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($tuCurl, CURLOPT_POSTFIELDS, $postFile);
        curl_setopt($tuCurl, CURLOPT_SSLCERT, $path . $info->CERT_PATH . '.pem');
        curl_setopt($tuCurl, CURLOPT_SSLKEY, $path . $info->CERT_PATH . '.key');
        $result = curl_exec($tuCurl);
        curl_close($tuCurl);

        Common::log(0, ['request' => $data, 'response' => $result, 'header' => $header, 'info' => $info], 'npt_' . $url);
        return json_decode($result, true);
    }
}