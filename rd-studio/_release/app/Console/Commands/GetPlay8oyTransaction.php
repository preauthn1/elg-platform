<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Helpers\VendorSend;
use App\Helpers\Common;
use App\Helpers\VendorPost;
use App\Models\VendorInfo;
use App\Models\Play8oyTransaction;

class GetPlay8oyTransaction extends Command
{

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'Play8oy_transaction {--start=} {--end=}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '更新Play8oy轉點記錄(報表統計時需要)';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    protected $vendor_send;
    protected $vendor_id = 30;
    protected $action = 'play8oy_get_transaction';
    public function __construct()
    {
        parent::__construct();
        $this->vendor_send = new VendorSend(['json_post'=>true]);
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
            // 交易記錄時間為+8時區，我們SERVER為+0
            $start_time = $this->option('start')
                ? str_replace('@', ' ', $this->option('start')) . ':00:00'
                : date('Y-m-d H:i:s', strtotime('+5hour -5minute'));
            $end_time = $this->option('end')
                ? str_replace('@', ' ', $this->option('end')) . ':00:00'
                : date('Y-m-d H:i:s', strtotime('+8hour -5minute'));
            echo '時間範圍:' . $start_time . ' to ' . $end_time . PHP_EOL;
            $url = 'transaction/log';

            $settings = VendorInfo::where('vendor_id', $this->vendor_id)
                ->where('status', 1)
                ->get();
            $used = []; //已執行過的不再執行

            if (count($settings) > 0) {
                foreach ($settings as $row) {
                    //將遊戲商資訊解密
                    $info = Common::decryptVendorInfo($row);
                    if (is_object($info) && !in_array($info->API_KEY, $used)) {
                        $url = $info->API_URL . $url;
                        //判斷是否為同一天; 如果是就分二個時間段取回資料
                        if (date('Ymd', strtotime($start_time)) != date('Ymd', strtotime($end_time))) {
                            $this->addTransaction(
                                $start_time,
                                date('Y-m-d', strtotime($start_time)) . ' 23:59:59',
                                $info,
                                $url
                            );
                            $this->addTransaction(
                                date('Y-m-d', strtotime($end_time)) . ' 00:00:00',
                                $end_time,
                                $info,
                                $url
                            );
                        } else {
                            $this->addTransaction($start_time, $end_time, $info, $url);
                        }
                        $used[] = $info->API_KEY;
                    } else {
                        echo $row->name . '解密失敗' . PHP_EOL;
                    }
                }
            }
        } catch (\Illuminate\Database\QueryException $e) {
            $info = $e->errorInfo;
            echo $info[0] . ' : ' . $info[2];
        } catch (\Exception $e) {
            echo $e->getCode() . ' : ' . $e->getMessage();
        }
    }

    private function addTransaction($start_time, $end_time, $info, $url, $page = 1)
    {
        $data = array();
        $data['StartTime'] = $start_time;
        $data['EndTime'] = $end_time;
        $data['PageNumber'] = $page;
        ksort($data);

        $header = array(
            'merchantName:' . $info->API_MERCHANT_NAME,
            'Sign:' . $this->sign($data, $info->API_KEY),
            'Content-Type:application/json'
        );

        $request = $this->vendor_send->send($this->action, $url, 'POST', $data, $header);
        $result = json_decode($request, true);
        if (is_array($result) && $result['Code'] == '0') {
            $total_page = $result['Data']['Page']['TotalPage'];

            $datas = array();
            foreach ($result['Data']['Record'] as $row) {
                $data = array();
                $data['transaction_id'] = ($row['OperationMethod'] == 3)
                    ? strtotime($row['CreateTime'])
                    : $row['ExternalTransactionId'];
                $data['type'] = $row['OperationMethod'];
                $data['username'] = $row['PlayerAccount'];
                $data['trans_amount'] = $row['TransAmount'];
                $data['status'] = $row['Result'];
                $data['transaction_time'] = date('Y-m-d H:i:s', strtotime($row['CreateTime'] . ' -8hour'));
                $datas[] = $data;
            }

            $create_records = $this->filterRecordsNotExists($datas);
            //直接一次insert
            Play8oyTransaction::insert($create_records);
            echo 'success: ';
            printf("*recordCount [%d] writeCount [%d] ", count($datas), count($create_records));
            echo PHP_EOL;

            //當還有其他頁數時, 取得下一頁資訊
            if ($total_page > $page) {
                $this->addTransaction($start_time, $end_time, $info, $url, ++$page);
            }
        } else {
            echo 'fail' . PHP_EOL;
        }
    }

    /**
     * 過濾掉己經存在的交易記錄
     * @param $datas
     * @param int $size
     * @return array
     */
    private function filterRecordsNotExists($datas, $size = 100) {
        $exists_ids = array();
        $collect = collect($datas);
        $chunks = $collect->chunk($size); //分組，一組 $size  個
        $unique_field = 'transaction_id';
        // 分組取出己經存在的押注記錄
        foreach ($chunks as $chunk) {
            $ids = $chunk->pluck($unique_field)->toArray();
            array_push(
                $exists_ids,
                ...Play8oyTransaction::whereIn($unique_field, $ids)
                ->select($unique_field)
                ->get()
                ->pluck($unique_field)
                ->toArray()
            );
        }

        // 過濾掉己經存在的押注記錄
        return $collect->filter(function($item) use ($unique_field, $exists_ids) {
                return !in_array($item[$unique_field], $exists_ids);
            })
            ->all();
    }

    private function sign($data, $apiKey)
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