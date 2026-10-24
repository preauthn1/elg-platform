<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Helpers\Common;
use App\Helpers\VendorSend;
use App\Models\MtBetRecord;
use App\Models\VendorInfo;
use DB;

class GetMtBetRecord extends Command
{

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'mt_bet_record {--start=}'; //每次跑一個小時 php artisan mt_bet_record --start=2020-03-01

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '更新mt投注紀錄';

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
            $date = $this->option('start')
                ? $this->option('start')
                : date('Y-m-d', strtotime('+7hours+30minutes'));
            echo $date . PHP_EOL;
            $settings = VendorInfo::where('vendor_id', 19)->where('status', 1)->get();
            $used = []; //已執行過的不再執行

            foreach ($settings as $row) {
                //將遊戲商資訊解密
                $info = Common::decryptVendorInfo($row);
                if (is_object($info) && !in_array($info->MT_KEY, $used)) {
                    $records = $this->getBetRecord($info, $date);
                    if (count($records) > 0) {
                        $this->addRecord($records);
                    }
                    $used[] = $info->MT_KEY;
                } else {
                    echo $row->name . '解密失敗' . PHP_EOL;
                }
            }
            
            //total bet record
            echo '===== total bet record Start =====' . PHP_EOL;
            DB::select('call SP_transfer_bet_record_mt(null, null, null)');
            echo '===== total bet record End =====' . PHP_EOL;
        } catch (\Illuminate\Database\QueryException $e) {
            $info = $e->errorInfo;
            echo $info[0] . ' : ' . $info[2];
        } catch (\Exception $e) {
            echo $e->getCode() . ' : ' . $e->getMessage();
        }
    }

    private function getBetRecord($info, $date)
    {
        $records = array();
        $rowID = 0;
        do {
            $nextLoop = false;
            $data = array();
            $data['rowID'] = $rowID;
            $data['startTime'] = $date;
            $data['currency'] = $info->MT_CUR;
            $data = json_encode($data);
            $postData = base64_encode($data);
            $url = $info->MT_API_URL . 'queryMerchantGameRecord3/' . $info->MT_MERCHANID . '/' . $postData;

            $header = array('Content-Type:application/json');
            $result = $this->vendorsend->send('mt_get_bet_record', $url, 'POST', $postData, $header);
            $response = json_decode($result, true);
            if (is_array($response) && $response['resultCode'] === "1") {
                array_push($records, ...$response['transList']);
                if (count($response['transList']) >= 25000) {
                    //一次最多回傳25000筆,如果超過要傳rowID再撈一次
                    $nextLoop = true;
                    $lastRecord = end($response['transList']);
                    $rowID = $lastRecord['rowID'];
                    if ($lastRecord['gameDate'] > $date . ' 23:59:59') {
                        $nextLoop = false;
                    }
                }
            }
        } while ($nextLoop);

        return $records;
    }

    private function addRecord($records)
    {
        $datas = array();
        foreach ($records as $row) {
            $data = array();
            $data['rowID'] = $row['rowID'];
            $data['playerName'] = $row['playerName'];
            $data['gameDate'] = $row['gameDate'];
            $data['gameCode'] = $row['gameCode'];
            $data['gameType'] = $row['gameType'];
            $data['betAmount'] = $row['betAmount'];
            $data['winAmount'] = $row['winAmount'];
            $data['commissionable'] = $row['commissionable'];
            $data['roomFee'] = $row['roomFee'];
            $data['income'] = $row['income'];
            $data['progressive_wins'] = $row['progressive_wins'];
            $data['progressive_share'] = $row['progressive_share'];
            $data['merchantId'] = $row['merchantId'];
            $gamedate = $row['gameDate'];
            $data['utc_gameDate'] = date("Y-m-d H:i:s", strtotime("$gamedate -8hours"));
            $datas[] = $data;
        }
        $create_records = $this->filterRecordsNotExists($datas);
        MtBetRecord::insert($create_records);
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
        $unique_field = 'rowID';
        // 分組取出己經存在的押注記錄
        foreach ($chunks as $chunk) {
            $ids = $chunk->pluck($unique_field)->toArray();
            array_push(
                $exists_ids,
                ...MtBetRecord::whereIn($unique_field, $ids)
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
}
