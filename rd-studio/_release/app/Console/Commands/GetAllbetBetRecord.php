<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Helpers\Common;
use App\Helpers\VendorSend;
use App\Models\AllbetBetRecord;
use App\Models\VendorInfo;
use App\Models\TransactionLog;
use DB;

class GetAllbetBetRecord extends Command
{

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'allbet_bet_record {--start=} {--end=}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '更新Allbet投注記錄';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    protected $vendor_send;
    protected $vendor_id = 35;
    protected $action = 'allbet_get_bet_record';

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
                foreach ($settings as $setting) {
                    //將遊戲商資訊解密
                    $info = Common::decryptVendorInfo($setting);
                    if (is_object($info) && !in_array($info->MD5_KEY, $used)) {
                        $userNameData = $this->getUserNames($setting->vendor_id, $start_time, $end_time);
                        foreach ($userNameData as $userName) {
                            $accountRecord = $this->getAccountBetRecord($info, $userName, $start_time, $end_time);
                            if (count($accountRecord) > 0) {
                                $this->addRecord($accountRecord, $setting->rate);
                            }
                            $used[] = $info->MD5_KEY;
                        }
                    } else {
                        echo $setting->name . '解密失敗' . PHP_EOL;
                    }
                }
            }

            //total bet record
            echo '===== total bet record Start =====' . PHP_EOL;
            DB::select('call SP_transfer_bet_record_allbet(null, null, null)');
            echo '===== total bet record End =====' . PHP_EOL;
        } catch (\Illuminate\Database\QueryException $e) {
            $info = $e->errorInfo;
            echo $info[0] . ' : ' . $info[2];
        } catch (\Exception $e) {
            echo $e->getCode() . ' : ' . $e->getMessage();
        }
    }

    //獲取目標時間段前一小時到後一小時
    private function getAccountBetRecord($info, $userName, $sDate, $eDate)
    {
        $records = array();
        $pageIndex = 1;
        do {
            $nextLoop = false;
            $pageSize = 100;
            $result = $this->getAccountBetRecordByPage($info, $userName, $sDate, $eDate, $pageIndex);
            if (is_array($result) && $result['message'] == 'ok') {
                array_push($records, ...$result['page']['datas']);
                if (intval($result['page']['count']) > ($pageIndex * $pageSize)) {
                    $nextLoop = true;
                    $pageIndex++;
                }
            }
        } while ($nextLoop);
        return $records;
    }

    private function getAccountBetRecordByPage($info, $userName, $start_time, $end_time, $pageIndex)
    {
        $url = $info->API_URL . '/client_betlog_query';
        $param = array();
        $param['random'] = mt_rand();
        $param['client'] = $userName;
        $param['startTime'] = $start_time;
        $param['endTime'] = $end_time;
        $param['pageIndex'] = $pageIndex;
        $param['pageSize'] = 100;

        $data = $this->data($info, $param);
        $sign = $this->sign($info, $data);

        $postData = array();
        $postData['data'] = $data;
        $postData['sign'] = $sign;
        $postData['propertyId'] = $info->PROPERY_ID;

        $response = $this->vendor_send->send($this->action, $url, 'GET', $postData);
        return json_decode($response, true);
    }

    private function addRecord($records, $rate)
    {
        $datas = array();
        foreach ($records as $row) {
            $data = array();
            $data['bet_id'] = $row['betNum'];
            $data['game_code'] = $row['gameType'];
            $data['username'] = $row['client'];
            $data['bet'] = floor(strval($row['betAmount'] * 10000 / $rate)) / 10000;
            $data['win_lose'] = floor(strval($row['winOrLoss'] * 10000 / $rate)) / 10000;
            $data['bet_time'] = date('Y-m-d H:i:s', strtotime($row['betTime'] . ' -8hour'));
            $data['info'] = json_encode($row);
            $datas[] = $data;
        }

        $create_records = $this->filterRecordsNotExists($datas);
        //直接一次insert
        AllbetBetRecord::insert($create_records);
        echo 'success: ';
        printf("*recordCount [%d] writeCount [%d] ", count($datas), count($create_records));
        echo PHP_EOL;
    }

    private function getUserNames($vendor_id, $start_time, $end_time)
    {
        $result = TransactionLog::join('allbet_accounts as v', 'v.member_id', 'transaction_logs.member_id')
            ->where('transaction_logs.type', 1)
            ->where('transaction_logs.vendor_id', $vendor_id)
            ->where('transaction_logs.created_at'
                , '>'
                , date('Y-m-d H:i:s', strtotime($start_time . ' -48hour'))
            )
            ->where('transaction_logs.created_at'
                , '<='
                , date('Y-m-d H:i:s', strtotime($end_time))
            )
            ->distinct()
            ->pluck('v.username')
            ->toArray();
        return $result;
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
                ...AllbetBetRecord::whereIn($unique_field, $ids)
                ->select($unique_field)
                ->get()
                ->pluck($unique_field)
                ->toArray()
            );
        }
        // 過濾掉己經存在的押注記錄
        return $collect->filter(function ($item) use ($unique_field, $exists_ids) {
            return !in_array($item[$unique_field], $exists_ids);
        })->all();
    }

    private function data($info, $param)
    {
        $real_param = http_build_query($param);
        $key = base64_decode($info->DES_KEY);
        $pad = 8 - (strlen($real_param) % 8);
        $string = $real_param . str_repeat(chr($pad), $pad);
        $data = openssl_encrypt(
            $string,
            'DES-EDE3-CBC',
            $key,
            OPENSSL_RAW_DATA | OPENSSL_NO_PADDING,
            base64_decode("AAAAAAAAAAA=")
        );
        $data = base64_encode($data);
        return $data;
    }

    private function sign($info, $data)
    {
        return base64_encode(md5($data . $info->MD5_KEY, true));
    }

}