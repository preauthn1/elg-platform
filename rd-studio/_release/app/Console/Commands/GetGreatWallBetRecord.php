<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Helpers\BatchInsertHelper;
use App\Helpers\VendorSend;
use App\Helpers\Common;
use App\Helpers\VendorPost;
use App\Models\VendorInfo;
use App\Models\GreatWallBetRecord;
use DB;

class GetGreatWallBetRecord extends Command
{

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'great_wall_bet_record {--start=} {--end=} {--page=}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '更新GW99投注記錄';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    protected $vendor_send;
    protected $today;
    protected $api_version = 1;  //當前版本
    protected $version = array(
        array('version' => 7.8, 'update_time' => '2022-05-01 16:00:00'),
        array('version' => 7, 'update_time' => '2021-01-01 16:00:00')
    );

    public function __construct()
    {
        parent::__construct();

        //判斷使用哪一種版本
        $this->today = date('Y-m-d H:i:s');
        foreach ($this->version as $key => $ver) {
            if ($this->today > $ver['update_time']) {
                $this->api_version = $ver['version'];
                break;
            }
        }

        $this->vendor_send = new VendorSend(['json_post' => true]);
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        echo 'api version:' . $this->api_version . PHP_EOL;
        $start_time = $this->option('start')
            ? str_replace('@', ' ', $this->option('start'))
            : date('Y-m-d H:i:s', strtotime('+7hour'));
        $end_time = $this->option('end')
            ? str_replace('@', ' ', $this->option('end'))
            : date('Y-m-d H:i:s', strtotime('+8hour'));
        $page = $this->option('page') ? $this->option('page') : 1;
        echo $start_time . ' to ' . $end_time;
        if ($page > 1) echo ' Page:' . $page;
        echo PHP_EOL;

        $settings = VendorInfo::where('vendor_id', 5)->where('status', 1)->get();
        $used = []; //已執行過的不再執行
        foreach ($settings as $row) {
            //將遊戲商資訊解密
            $info = Common::decryptVendorInfo($row);
            if (is_object($info) && !in_array($info->API_MERCHANT_NAME, $used)) {
                echo '======' . $row->name . '======' . PHP_EOL;
                switch ($this->api_version) {
                    case 7.8:
                        $records = $this->getBetRecord_7_8($info, $start_time, $end_time, $page);
                        break;
                    default:
                        $records = $this->getBetRecord($info, $start_time, $end_time);
                        break;
                }

                if (count($records) > 0) {
                    $this->addRecord($records);
                } else {
                    echo 'no records';
                }
                $used[] = $info->API_MERCHANT_NAME;
            }
        }

        //total bet record
        echo '===== total bet record Start =====' . PHP_EOL;
        DB::select('call SP_transfer_bet_record_gw99(null, null, null)');
        echo '===== total bet record End =====' . PHP_EOL;
    }

    private function getBetRecord($info, $start_time, $end_time)
    {
        $url = $info->API_URL . 'Api/IGame/Game/Log/Record';
        // 投注記錄時間為+8時區，我們SERVER為+0
        $data = array();
        $data['CreatedDateFrom'] = $start_time;
        $data['CreatedDateTo'] = $end_time;
        ksort($data);

        $header = array(
            'merchantName:' . $info->API_MERCHANT_NAME,
            'signature:' . $this->GreatWallSign($data, $info->API_KEY),
            'Content-Type:application/json'
        );

        $response = $this->vendor_send->send('great_wall_get_bet_record', $url, 'POST', $data, $header);
        $result = json_decode($response, true);

        $records = array();
        if (is_array($result) && isset($result['result']['code']) && $result['result']['code'] == '0') {
            array_push($records, ...$result['data']['record']);
        }
        return $records;
    }

    private function getBetRecord_7_8($info, $start_time, $end_time, $page = 1)
    {
        $url = $info->API_URL . 'Api/IGame/Game/Log/Agent';
        // 投注記錄時間為+8時區，我們SERVER為+0
        $data = array();
        $data['PageNumber'] = $page;
        $data['StartDate'] = $start_time;
        $data['EndDate'] = $end_time;
        ksort($data);

        $header = array(
            'merchantName:' . $info->API_MERCHANT_NAME,
            'signature:' . $this->GreatWallSign($data, $info->API_KEY),
            'Content-Type:application/json'
        );

        $response = $this->vendor_send->send('great_wall_get_bet_record_v7_8', $url, 'POST', $data, $header);
        $result = json_decode($response, true);

        $records = array();
        if (is_array($result)) {
            if (isset($result['result']['code']) && $result['result']['code'] == '0') {
                if ($page == 1) echo 'TotalSize:' . $result['data']['page']['totalSize'] . PHP_EOL;
                echo 'Page:' . $result['data']['page']['pageNumber'] . '/' . $result['data']['page']['totalPage'] . PHP_EOL;
                array_push($records, ...$result['data']['record']);

                if ($result['data']['page']['totalPage'] > $page) {
                    $betRecord = $this->getBetRecord_7_8($info, $start_time, $end_time, ++$page);
                    array_push($records, ...$betRecord);
                }
            } else {
                echo '*** ERROR *****' . PHP_EOL;
                echo '[code]' . $result['result']['code'] . PHP_EOL;
                echo '[message]' . $result['result']['message'] . PHP_EOL;
            }
        } else {
            echo '*** ERROR *****' . PHP_EOL;
            print_r($result);
            echo PHP_EOL;
        }
        return $records;
    }

    private function addRecord($records)
    {
        $record_start_time = null;
        $record_end_time = null;
        $datas = array();
        foreach ($records as $row) {
            $data = array();
            $data['themeId'] = $row['themeId'];
            $data['gameNo'] = $row['gameNo'];
            $data['gameSerialId'] = $row['gameSerialId'];
            $data['accountId'] = $row['accountId'];
            $data['userName'] = $row['userName'];
            $data['valueBefore'] = $row['valueBefore'];
            $data['valueAfter'] = $row['valueAfter'];
            $data['betCoin'] = $row['betCoin'];
            $data['winAmount'] = $row['winAmount'];
            $data['availTotalBet'] = $row['availTotalBet'];
            $data['availTotalWin'] = $row['availTotalWin'];
            $data['remoteIP'] = $row['remoteIP'];
            $data['deviceType'] = $row['deviceType'];
            $data['historyCreatedTime'] = $row['historyCreatedTime'];
            $data['utc_created_time'] = date('Y-m-d H:i:s', strtotime($row['historyCreatedTime'] . ' -8hour'));
            $datas[] = $data;

            if ($data['utc_created_time'] < $record_start_time || $record_start_time == null) {
                $record_start_time = $data['utc_created_time'];
            }
            if ($data['utc_created_time'] > $record_end_time || $record_end_time == null) {
                $record_end_time = $data['utc_created_time'];
            }
        }
        $records = $this->filterRecordsNotExists($datas, 100, $record_start_time, $record_end_time);
        $batchInsertHelper = new BatchInsertHelper();
        $writeCount = $batchInsertHelper->insertBatch($records['createData'], "great_wall_bet_records");
        printf(
            "*recordCount [%d] writeCount [%d]",
            count($datas),
            $writeCount
        );
        echo PHP_EOL . 'success' . PHP_EOL;
    }

    /**
     * 過濾掉己經存在的押注記錄
     * @param $datas
     * @param int $size
     * @return array
     */
    private function filterRecordsNotExists($datas, $size = 100, $record_start_time, $record_end_time)
    {
        $exists_ids = array();
        $collect = collect($datas);
        $chunks = $collect->chunk($size); // 分組，一組 $size  個
        $unique_field = 'gameSerialId';
        // 分組取出己經存在的押注記錄
        foreach ($chunks as $chunk) {
            $ids = $chunk->pluck($unique_field)->toArray();
            array_push(
                $exists_ids,
                ...GreatWallBetRecord::whereBetween('utc_created_time', [$record_start_time, $record_end_time])
                    ->whereIn($unique_field, $ids)
                    ->pluck($unique_field)
                    ->toArray()
            );
        }

        // 分開己經存在的押注記錄
        $createData = $collect->filter(function ($item) use ($unique_field, $exists_ids) {
            return !in_array($item[$unique_field], $exists_ids);
        })->all();

        return ['createData' => $createData];
    }

    private function GreatWallSign($data, $apiKey)
    {
        $string = '';
        if (count($data) > 0) {
            foreach ($data as $key => $value) {
                $string .= $key . '=' . $value . '&';
            }
        }
        $string .= 'PrivateKey=' . $apiKey;
        $hash_hmac = hash('sha256', $string, true);
        return base64_encode($hash_hmac);
    }
}
