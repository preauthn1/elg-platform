<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Helpers\Common;
use App\Helpers\VendorSend;
use App\Models\VsportBetRecord;
use App\Models\VendorInfo;
use DB;

class GetVsportBetRecord extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'vsport_bet_record';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'V-sport 投注記錄';
    protected $vendor_id = 53;
    protected $action = 'vsport_get_bet_record';

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
            echo $this->description . PHP_EOL;
            // V-Sport注單, 不需時間段要標記己抓取
            $settings = VendorInfo::where('vendor_id', $this->vendor_id)
                ->where('status', 1)
                ->get();
            $used = []; //已執行過的不再執行
            if (count($settings) > 0) {
                foreach ($settings as $setting) {
                    //將遊戲商資訊解密
                    $info = Common::decryptVendorInfo($setting);
                    if (is_object($info) && !in_array($info->SECRET, $used)) {
                        $records = $this->getBetRecord($info);
                        if (is_array($records) && count($records) > 0) {
                            $this->addRecord($info, $records);
                        } else {
                            echo 'no records';
                        }
                        $used[] = $info->SECRET;
                    } else {
                        echo $setting->name . '解密失敗' . PHP_EOL;
                    }
                }
            }

            //total bet record
            echo '===== total bet record Start =====' . PHP_EOL;
            DB::select('call SP_transfer_bet_record_vsport(null, null, null)');
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
        $url = $info->API_URL;

        $data = array();
        $data['secret'] = $info->SECRET;
        $data['action'] = 'fetch';
        $data['agent'] = $info->AGENT;

        $vendorSend = new VendorSend();
        $response = $vendorSend->send($this->action, $url, 'GET', $data);
        $result = json_decode($response, true);
        if (is_array($result) && $result['errcode'] == 0) {
            if (is_array($result['result'])) {
                $records = $result['result']['ticket'];
            }
        }

        return $records;
    }

    private function addRecord($info, $records)
    {
        $write_record = $update_record = 0;
        $ids = array();
        foreach ($records as $row) {
            $data = array();
            $data['bet_id'] = $row['id'];
            $data['game_code'] = 'sport';
            $data['username'] = $row['u'];
            $data['bet'] = $row['b'];
            $data['win_lose'] = $row['w'];
            $data['created_time'] = date('Y-m-d H:i:s', strtotime($row['trandate'] . '-8hour'));
            $data['bet_time'] = date('Y-m-d H:i:s', strtotime($row['t'] . '-8hour'));
            $data['work_date'] = date('Y-m-d H:i:s', strtotime($row['workdate'] . '-8hour'));
            $data['ticket_status'] = ($row['res'] == 'P') ? 0 : 1;
            $data['info'] = json_encode($row);

            //因為他們bet_id會重複,所以先採用update的方式取代掉舊的注單
            $record = VsportBetRecord::where('bet_id', $data['bet_id'])->first();
            if (!is_object($record)) {
                VsportBetRecord::create($data);
                echo '*****' . $data['bet_id'] . '*****';
                $write_record++;
            } else {
                if ($record->ticket_status == 1) {
                    $data['ticket_status'] = 2;
                }
                $record->update($data);
                echo '!!!!!!!' . $data['bet_id'] . '!!!!!!!';
                $update_record++;
            }
            $ids[] = $row['fid'];
        }
        $this->removeBetRecordsBatch($info, $ids);
        echo PHP_EOL;
        echo 'success: ';
        printf("*writeCount [%d] updateCount [%d]", $write_record, $update_record);
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
                ...VsportBetRecord::whereIn($unique_field, $ids)
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

    private function removeBetRecordsBatch($info, $record_ids)
    {
        $collect = collect($record_ids);
        $chunks = $collect->chunk(500)->toArray(); // 分組，一組 $size  個
        foreach ($chunks as $chunk) {
            $this->removeBetRecords($info, $chunk);
        }
    }

    private function removeBetRecords($info, $record_ids)
    {
        $url = $info->API_URL;
        $ids = '';
        foreach ($record_ids as $val) {
            $ids .= $val;
            if ($val != end($record_ids)) {
                $ids .= ',';
            }
        }
        $data = array();
        $data['secret'] = $info->SECRET;
        $data['action'] = 'mark_fetched';
        $data['agent'] = $info->AGENT;
        $data['fetch_ids'] = $ids;

        $vendorSend = new VendorSend();
        $response = $vendorSend->send($this->action, $url, 'GET', $data);
        return json_decode($response, true);
    }
}
