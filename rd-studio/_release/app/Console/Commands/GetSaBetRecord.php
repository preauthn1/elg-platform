<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Helpers\Common;
use App\Helpers\VendorSend;
use App\Models\VendorInfo;
use App\Models\SaBetRecord;
use DB;

class GetSaBetRecord extends Command
{

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'sa_bet_record {--start=} {--end=}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '更新Sa投注記錄';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    protected $vendor_send;
    protected $vendor_id = 37;
    protected $action = 'sa_get_bet_record';

    public function __construct()
    {
        parent::__construct();
        $this->vendor_send = new VendorSend();
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
            $start_time = $this->option('start')
                ? str_replace('@', ' ', $this->option('start')) . ':00:00'
                : date('Y-m-d H:i:s', strtotime('+7hour -5minute'));
            $end_time = $this->option('end')
                ? str_replace('@', ' ', $this->option('end')) . ':00:00'
                : date('Y-m-d H:i:s', strtotime('+8hour -5minute'));
            echo '時間範圍:' . $start_time . ' to ' . $end_time . PHP_EOL;

            $settings = VendorInfo::where('vendor_id', $this->vendor_id)
                ->where('status', 1)
                ->get();
            $used = []; //已執行過的不再執行

            if (count($settings) > 0) {
                foreach ($settings as $row) {
                    //將遊戲商資訊解密
                    $info = Common::decryptVendorInfo($row);
                    $rate = isset($row->rate) ? $row->rate : 1;
                    if (is_object($info) && !in_array($info->SECRETKEY, $used)) {
                        echo '======' . $row->name . '======' . PHP_EOL;
                        $records = $this->getBetRecord($info, $start_time, $end_time);
                        if (count($records) > 0) {
                            $this->addRecord($records, $rate);
                        }
                        $used[] = $info->SECRETKEY;
                    } else {
                        echo $row->name . '解密失敗' . PHP_EOL;
                    }
                }
            }

            //total bet record
            echo '===== total bet record Start =====' . PHP_EOL;
            DB::select('call SP_transfer_bet_record_sa(null, null, null)');
            echo '===== total bet record End =====' . PHP_EOL;
        } catch (\Illuminate\Database\QueryException $e) {
            $info = $e->errorInfo;
            echo $info[0] . ' : ' . $info[2];
        } catch (\Exception $e) {
            echo $e->getCode() . ' : ' . $e->getMessage();
        }
    }


    private function getBetRecord($info, $start_time, $end_time)
    {
        $record = [];
        $url = $info->RECORD_URL;
        $time = date('YmdHis', time());

        echo 'API執行時間 : ' . $start_time . ' to ' . $end_time . PHP_EOL;

        $data = array();
        $data['method'] = 'GetAllBetDetailsForTimeIntervalDV';
        $data['Key'] = $info->SECRETKEY;
        $data['Time'] = $time;
        $data['FromTime'] = $start_time;
        $data['ToTime'] = $end_time;

        $Qs = $this->params($data);

        $s = md5($Qs . $info->MD5KEY . $time . $info->SECRETKEY);
        $q = $this->sign($info, $Qs);

        $postData = array(
            'q' => $q,
            's' => $s
        );

        $header = array('Content-Type:application/x-www-form-urlencoded');
        $response = $this->vendor_send->send($this->action, $url, 'POST', $postData, $header);
        $result = $this->xmlToArray($response);

        if (is_array($result) && $result['ErrorMsgId'] == 0) {
            if (count($result['BetDetailList']) > 0) {
                //當BetDetail只有1筆時array會不一樣
                if (isset($result['BetDetailList']['BetDetail'][0])) {
                    $record = $result['BetDetailList']['BetDetail'];
                } else {
                    $record = $result['BetDetailList'];
                }
            }
        }

        return $record;
    }

    private function addRecord($records, $rate = 1)
    {
        $datas = array();
        foreach ($records as $row) {
            $data = array();
            $data['bet_id'] = $row['BetID'];
            $data['game_code'] = $row['GameType'];
            $data['username'] = $row['Username'];
            $data['bet'] = floatval($row['BetAmount'] / $rate);
            $data['valid_bet'] = $row['Rolling'];
            $data['win_lose'] = floatval($row['ResultAmount'] / $rate);
            $utcTime = date('Y-m-d H:i:s', strtotime($row['BetTime']));
            $data['bet_time'] = date('Y-m-d H:i:s', strtotime($utcTime . '-8hours'));;
            $data['info'] = json_encode($row);
            $datas[] = $data;
        }
        $create_records = $this->filterRecordsNotExists($datas);
        //直接一次insert
        SaBetRecord::insert($create_records);
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
                ...SaBetRecord::whereIn($unique_field, $ids)
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

    private function params($data)
    {
        $str = '';

        foreach ($data as $key => $val) {
            $str .= $key . '=' . $val;
            if ($val !== end($data)) {
                $str .= '&';
            }
        }

        return $str;
    }

    private function sign($info, $Qs)
    {
        return base64_encode(openssl_encrypt($Qs, 'DES-CBC', $info->ENCRYPTKEY, OPENSSL_RAW_DATA, $info->ENCRYPTKEY));
    }

    //把API回來的XML格式轉成Array
    private function xmlToArray($result)
    {
        $xml = simplexml_load_string($result);
        $json = json_encode($xml);
        return json_decode($json, true);
    }

}