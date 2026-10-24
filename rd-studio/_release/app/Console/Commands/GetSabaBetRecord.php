<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Helpers\Common;
use App\Helpers\VendorSend;
use App\Models\VendorInfo;
use App\Models\SabaBetRecord;
use DB;

class GetSabaBetRecord extends Command
{

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'saba_bet_record {--version_key=}'; //每次跑一個小時 php artisan saba_bet_record

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '更新Saba投注紀錄';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    protected $vendor_send;
    protected $vendor_id = 44;
    protected $action = 'saba_get_bet_record';
    protected $currency_id = ['MYR' => 2, 'VND' => 51, 'BND' => 46];
    protected $currency_path = array();

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
        ini_set('memory_limit','1024M');
        try {
            echo $this->description . PHP_EOL;
            $settings = VendorInfo::where('vendor_id', $this->vendor_id)
                ->where('status', 1)
                ->get();
            // 先依商戶把幣值列出來
            foreach ($settings as $row) {
                $this->currency_path[$this->currency_id[$row->currency]] = floatval($row->rate);
            }
            $used = []; //已執行過的不再執行
            if (count($settings) > 0) {
                foreach ($settings as $setting) {
                    $info = json_decode($setting->info);
                    if (is_object($info) && !in_array($info->VENDOR_ID, $used)) {
                        $records = $this->getBetRecord($info);
                        if (count($records) > 0) {
                            $this->addRecord($records);
                        }
                        $used[] = $info->VENDOR_ID;
                    }
                }
            }

            //total bet record
            echo '===== total bet record Start =====' . PHP_EOL;
            DB::select('call SP_transfer_bet_record_saba(null, null, null)');
            echo '===== total bet record End =====' . PHP_EOL;
        } catch (\Illuminate\Database\QueryException $e) {
            $info = $e->errorInfo;
            echo $info[0] . ' : ' . $info[2];
        } catch (\Exception $e) {
            echo $e->getCode() . ' : ' . $e->getMessage();
        }
    }

    private function getBetRecord($info)
    {
        $records = array();
        $loop_time = 0;
        if (is_numeric($this->option('version_key'))) {
            $version_key = $this->option('version_key');
        } else {
            $version_key = SabaBetRecord::orderBy('updated_at', 'desc')->pluck('last_version_key')->first();
        }
        do {
            $nextLoop = false;
            $result = $this->getBetRecordByPage($info, $version_key);
            if (is_array($result) && $result['error_code'] === 0) {
                if (isset($result['Data']['BetDetails'])) {
                    array_push($records, ...$result['Data']['BetDetails']);
                    $loop_time++;
                }
                if (isset($result['Data']['BetNumberDetails'])) {
                    array_push($records, ...$result['Data']['BetNumberDetails']);
                }
                if (isset($result['Data']['BetVirtualSportDetails'])) {
                    array_push($records, ...$result['Data']['BetVirtualSportDetails']);
                }
                if ($result['Data']['last_version_key'] > $version_key) {
                    echo '本次last_version_key : ' . $result['Data']['last_version_key'] . PHP_EOL;
                    $nextLoop = true;
                    //以防跑太久,超過10次的話就等下一批再撈
                    if ($loop_time >= 10) {
                        $nextLoop = false;
                    }
                }
            }
            $version_key = $result['Data']['last_version_key'];
        } while ($nextLoop);
        $records = array(
            'data' => $records,
            'last_version_key' => $version_key
        );

        return $records;
    }

    private function getBetRecordByPage($info, $version_key = 0)
    {
        $url = $info->API_URL . '/api/GetBetDetail';

        $data = array(
            'vendor_id' => $info->VENDOR_ID,
            'version_key' => $version_key,
        );

        $header = array('Content-Type: application/x-www-form-urlencoded');

        $response = $this->vendor_send->send($this->action, $url, 'POST', $data, $header);
        $result = json_decode($response, true);

        return $result;
    }

    private function addRecord($records)
    {
        $datas = array();
        foreach ($records['data'] as $list) {
            //判斷注單是哪種幣值,來決定兌換比例
            $rate = isset($this->currency_path[$list['currency']]) ? $this->currency_path[$list['currency']] : 1;

            $data = array();
            $data['bet_id'] = $list['trans_id'];
            $data['username'] = $list['vendor_member_id'];
            if ($list['sport_type'] == 43) {
                $data['game_code'] = 'esports';
            } elseif ($list['sport_type'] == 171) {
                $data['game_code'] = $list['bet_type'];
            } else {
                $data['game_code'] = 'Saba';
            }
            $winlost_time = str_replace('T', " ", $list['winlost_datetime']);;
            $data['winlost_datetime'] = date('Y-m-d H:i:s', strtotime($winlost_time . ' +4hours'));
            $bet_time = isset($list['settlement_time']) == true
                ? str_replace('T', " ", $list['settlement_time'])
                : str_replace('T', " ", $list['transaction_time']);
            $data['bet_time'] = date('Y-m-d H:i:s', strtotime($bet_time . ' +4hours'));
            $created_time = str_replace('T', " ", $list['transaction_time']);
            $data['created_time'] = date('Y-m-d H:i:s', strtotime($created_time . ' +4hours'));
            $data['ticket_status'] = $list['ticket_status'];
            $data['last_version_key'] = $records['last_version_key'];
            $data['bet'] = ($list['stake'] / $rate);
            $data['win_lose'] = ($list['winlost_amount'] / $rate);
            $data['info'] = json_encode($list);
            $datas[] = $data;

            // 有的注單會有CashOutData要額外加進去
            if (isset($list['CashOutData'])) {
                $cashOut = $list['CashOutData'];
                foreach ($cashOut as $cashOutList) {
                    $cashOutData = array();
                    $cashOutData['bet_id'] = $cashOutList['cashout_id'];
                    $cashOutData['username'] = $list['vendor_member_id'];
                    $cashOutData['game_code'] = 'CashOut';
                    $cash_out_winlost_time = str_replace('T', " ", $cashOutList['winlost_datetime']);;
                    $cashOutData['winlost_datetime'] = date('Y-m-d H:i:s', strtotime($cash_out_winlost_time . ' +4hours'));
                    $cash_out_bet_time = str_replace('T', " ", $cashOutList['transdate']);
                    $cashOutData['bet_time'] = date('Y-m-d H:i:s', strtotime($cash_out_bet_time . ' +4hours'));
                    $cashOutData['created_time'] = date('Y-m-d H:i:s', strtotime($cash_out_bet_time . ' +4hours'));
                    $cashOutData['ticket_status'] = $cashOutList['ticket_status'];
                    $cashOutData['last_version_key'] = $records['last_version_key'];
                    $cashOutData['bet'] = 0;
                    $cashOutData['win_lose'] = (($cashOutList['buyback_amount'] - $cashOutList['real_stake']) / $rate);
                    $cashOutData['info'] = json_encode($cashOutList);
                    $datas[] = $cashOutData;
                }
            }
        }
        $records = $this->filterRecordsNotExists($datas);
        SabaBetRecord::insert($records['createData']);
        foreach ($records['updateData'] as $data) {
            SabaBetRecord::where('bet_id', strval($data['bet_id']))->update($data);
        }
        printf("*recordCount [%d] writeCount [%d] updateCount [%d]",
            count($datas), count($records['createData']), count($records['updateData']));
        echo PHP_EOL . 'success' . PHP_EOL;
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
                ...SabaBetRecord::whereIn($unique_field, $ids)
                ->select($unique_field)
                ->get()
                ->pluck($unique_field)
                ->toArray()
            );
        }

        // 分開己經存在的押注記錄
        $createData = $collect->filter(function ($item) use ($unique_field, $exists_ids) {
            return !in_array($item[$unique_field], $exists_ids);
        })->all();
        $updateData = $collect->reject(function ($item) use ($unique_field, $exists_ids) {
            return !in_array($item[$unique_field], $exists_ids);
        })->all();

        return ['createData' => $createData, 'updateData' => $updateData];
    }


}
