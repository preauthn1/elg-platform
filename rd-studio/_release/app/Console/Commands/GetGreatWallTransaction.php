<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Helpers\VendorSend;
use App\Helpers\Common;
use App\Helpers\VendorPost;
use App\Models\VendorInfo;
use App\Models\GreatWallTransaction;

class GetGreatWallTransaction extends Command
{

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'great_wall_transaction {--start=} {--end=}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '更新GW99轉點記錄(報表統計時需要)';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    protected $vendorsend;
    public function __construct()
    {
        parent::__construct();
        $this->vendorsend = new VendorSend(['json_post'=>true]);
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        try {
            $from = $this->option('start')
                ? str_replace('@', ' ', $this->option('start'))
                : date('Y-m-d H:i:s', strtotime('+6hour'));
            $end = $this->option('end')
                ? str_replace('@', ' ', $this->option('end'))
                : date('Y-m-d H:i:s', strtotime('+8hour'));
            echo $from . ' to ' . $end . PHP_EOL;
            $url = 'Api/IGame/Transaction/Log';
            // 投注記錄時間為+8時區，我們SERVER為+0
            $data = array();
            $data['CreatedDateFrom'] = $from;
            $data['CreatedDateTo'] = $end;
            ksort($data);

            $settings = VendorInfo::where('vendor_id', 5)->where('status', 1)->get();
            $used = []; //已執行過的不再執行
            foreach ($settings as $row) {
                //將遊戲商資訊解密
                $info = Common::decryptVendorInfo($row);
                if (is_object($info) && !in_array($info->API_MERCHANT_NAME, $used)) {
                    $url = $info->API_URL . $url;
                    $header = array(
                        'merchantName:' . $info->API_MERCHANT_NAME,
                        'signature:' . $this->GreatWallSign($data, $info->API_KEY),
                        'Content-Type:application/json'
                    );
                    $response = $this->vendorsend->send('great_wall_get_transaction', $url, 'POST', $data, $header);
                    $result = json_decode($response, true);
                    if (is_array($result) && isset($result['result']['code']) && $result['result']['code'] == '00000') {
                        foreach ($result['data']['list'] as $row) {
                            $data = [
                                'externalTransactionId' => $row['externalTransactionId'],
                                'operationMethod' => $row['operationMethod'],
                                'playerAccount' => $row['playerAccount'],
                                'transAmount' => $row['transAmount'],
                                'result' => $row['result'],
                                'createTime' => $row['createTime'],
                                'utc_created_time' => date('Y-m-d H:i:s', strtotime($row['createTime'] . ' -8hour'))
                            ];
                            $record = GreatWallTransaction::where('playerAccount', $data['playerAccount'])
                                ->where('utc_created_time', $data['utc_created_time'])
                                ->where('externalTransactionId', $data['externalTransactionId'])
                                ->first();
                            if (!is_object($record)) {
                                GreatWallTransaction::create($data);
                                echo '------' . $row['externalTransactionId'] . '-----' . PHP_EOL;
                            }
                        }
                        echo 'success' . PHP_EOL;
                    } else {
                        echo 'fail' . PHP_EOL;
                    }
                    $used[] = $info->API_MERCHANT_NAME;
                } else {
                    echo $row->name . '解密失敗' . PHP_EOL;
                }
            }
        } catch (\Illuminate\Database\QueryException $e) {
            $info = $e->errorInfo;
            echo $info[0] . ' : ' . $info[2];
        } catch (\Exception $e) {
            echo $e->getCode() . ' : ' . $e->getMessage();
        }
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
