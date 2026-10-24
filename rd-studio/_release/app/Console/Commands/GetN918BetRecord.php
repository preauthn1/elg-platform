<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Helpers\Common;
use App\Helpers\VendorSend;
use App\Models\N918BetRecord;
use App\Models\VendorInfo;
use App\Models\TransactionLog;
use DB;

class GetN918BetRecord extends Command
{

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'n918_bet_record {--start=} {--end=}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '更新N918投注記錄';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    protected $vendor_send;
    protected $vendor_id = 33;
    protected $action = 'n918_get_bet_record';

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
                    if (is_object($info) && !in_array($info->SECRET_KEY, $used)) {
                        $userNameData = $this->getUserNames($setting->vendor_id, $start_time);
                        foreach ($userNameData as $userName) {
                            $accountRecord = $this->getAccountBetRecord($info, $userName, $start_time, $end_time);
                            if (count($accountRecord) > 0) {
                                $this->addRecord($userName, $accountRecord);
                            }
                            $used[] = $info->SECRET_KEY;
                        }
                    } else {
                        echo $setting->name . '解密失敗' . PHP_EOL;
                    }
                }
            }

            //total bet record
            echo '===== total bet record Start =====' . PHP_EOL;
            DB::select('call SP_transfer_bet_record_n918(null, null, null)');
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
            if (is_array($result) && isset($result['results'])) {
                array_push($records, ...$result['results']);
                if (intval($result['total']) > ($pageIndex * $pageSize)) {
                    $nextLoop = true;
                    $pageIndex++;
                }
            }
        } while ($nextLoop);
        return $records;
    }

    private function getAccountBetRecordByPage($info, $userName, $start_time, $end_time, $pageIndex)
    {
        $time = time() . '000';
        $sign = $this->getsign($info->AUTH_CODE . $info->AGENT_ID . $time . $info->SECRET_KEY);
        $data = array();
        $data['action'] = 'GameLog';
        $data['userName'] = $userName;
        $data['pageIndex'] = $pageIndex;
        $data['sDate'] = $start_time;
        $data['eDate'] = $end_time;
        $data['time'] = $time;
        $data['authcode'] = $info->AUTH_CODE;
        $data['sign'] = $sign;
        $result = $this->vendor_send->send('n918_get_bet_record', $info->API_URL, 'GET', $data);
        return json_decode($result, true);
    }

    private function addRecord($username, $records)
    {
        $datas = array();
        foreach ($records as $row) {
            // -1是轉帳紀錄
            if ($row['GameID'] != -1) {
                $data = array();
                $data['bet_id'] = $row['uuid'];
                $data['game_code'] = $row['GameID'];
                $data['username'] = $username;
                $data['bet'] = $row['bet'];
                $data['win'] = $row['Win'];
                $data['win_lose'] = ($row['Win'] - $row['bet']);
                $data['bet_time'] = date('Y-m-d H:i:s', strtotime($row['CreateTime'] . ' -8hour'));
                $data['info'] = json_encode($row);
                $datas[] = $data;
            }
        }

        $create_records = $this->filterRecordsNotExists($datas);
        //直接一次insert
        N918BetRecord::insert($create_records);
        echo 'success: ';
        printf("*userName [%d] recordCount [%d] writeCount [%d] ", $username, count($datas), count($create_records));
        echo PHP_EOL;
    }

    private function getUserNames($vendor_id, $start_time)
    {
        $result = TransactionLog::join('n918_accounts as v', 'v.member_id', 'transaction_logs.member_id')
            ->where('transaction_logs.type', 1)
            ->where('transaction_logs.vendor_id', $vendor_id)
            ->where('transaction_logs.created_at'
                , '>'
                , date('Y-m-d H:i:s', strtotime($start_time . ' -48hour'))
            )
            ->distinct()
            ->pluck('v.player_id')
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
                ...N918BetRecord::whereIn($unique_field, $ids)
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

    private function getSign($string)
    {
        return strtoupper(md5(strtolower($string)));
    }
}