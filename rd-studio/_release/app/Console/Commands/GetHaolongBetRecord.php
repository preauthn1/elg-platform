<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Helpers\BatchInsertHelper;
use App\Helpers\VendorSend;
use App\Helpers\Common;
use App\Models\VendorInfo;
use App\Models\HaolongBetRecord;
use DB;

class GetHaolongBetRecord extends Command
{

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'haolong_bet_record {--date=}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '取豪龍投注記錄';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    protected $vendorsend;

    public function __construct()
    {
        parent::__construct();
        $this->vendorsend = new VendorSend();
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        try {
            $from = $this->option('date') ? $this->option('date') : date('Y-m-d');
            // $end = $this->option('end') ? $this->option('end') : date('Y-m-d', strtotime('+1day'));
            echo $from . PHP_EOL;
            $data = array();
            $data['dateFrom'] = $from; // 開彩日期
            $data['dateTo'] = $from; // 因為可投注開彩日期是今天至6天後，先撈會有取消注單的問題，所以不先撈取

            //撈新資料前要刪除舊資料
            HaolongBetRecord::where('draw_date', $from)->delete();
            $settings = VendorInfo::where('vendor_id', 4)->where('status', 1)->get();
            $used = []; //已執行過的不再執行
            foreach ($settings as $row) {
                $info = json_decode($row->info);
                if (is_object($info) && !in_array($info->HAOLONG_USER, $used)) {
                    echo '======' . $row->name . '======' . PHP_EOL;
                    $this->addRecord($data, 1, $info);
                    $used[] = $info->HAOLONG_USER;
                }
            }
            //total bet record
            echo '===== total bet record Start =====' . PHP_EOL;
            DB::select('call SP_transfer_bet_record_4D(null, null, null)');
            echo '===== total bet record End =====' . PHP_EOL;
        } catch (\Illuminate\Database\QueryException $e) {
            $info = $e->errorInfo;
            return response()->json([
                'code' => $info[0],
                'message' => $info[2]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'code' => $e->getCode(),
                'message' => $e->getMessage()
            ]);
        }
    }

    private function addRecord($postData, $page, $info, $tryAgain = true)
    {
        $url = $info->API_URL . 'betListPage.aspx';
        $postData['page'] = $page;
        $postData['apiUser'] = $info->API_USER;
        $postData['apiPass'] = $info->API_PASSWORD;
        $postData['user'] = $info->HAOLONG_USER;
        $postData['pass'] = $info->HAOLONG_PASSWORD;
        $result = simplexml_load_string($this->vendorsend->send('Haolong_get_bet_record', $url, 'POST', $postData));
        if (is_object($result)) {
            if ($result->errorCode == 0) {
                $datas = array();
                foreach ($result->betData as $row) {
                    $data = array();
                    $data['login_id'] = $row->loginID;
                    $data['bet_id'] = $row->betID;
                    $data['bet_time'] = $row->dateTime;
                    $data['utc_bet_time'] = date('Y-m-d H:i:s', strtotime($row->dateTime . ' -8hour'));
                    //drawType={0:全部, 1:Magnum, 2: PMP, 3: Toto, 4: Singapore, 5: Sabah, 6: Sandakan, 7: Sarawak, 8: GD (豪龙)}
                    $data['draw_type'] = $row->drawType;
                    $data['draw_date'] = $row->drawDate;
                    $data['bet_number'] = $row->betNumber;
                    $data['bet_position'] = $row->betPosition;
                    $data['bet_amount'] = str_replace(',', '', $row->bet);
                    $datas[] = $data;
                }
                $create_records = $this->filterRecordsNotExists($datas);
                $batchInsertHelper = new BatchInsertHelper();
                $writeCount = $batchInsertHelper->insertBatch($create_records, "haolong_bet_records");
                printf("*recordCount [%d] writeCount [%d] ", count($datas), $writeCount);
                if (count($result->betData) > 0) {
                    $this->addRecord($postData, ($page + 1), $info);
                }
            } else {
                echo 'Error:' . $result->desc . PHP_EOL;
            }
        } else {
            if ($tryAgain) {
                $this->addRecord($postData, $page, $info, false);
            }
            echo 'fail' . PHP_EOL;
        }
    }

    /**
     * 過濾掉己經存在的押注記錄
     * @param $datas
     * @param int $size
     * @return array
     */
    private function filterRecordsNotExists($datas, $size = 100) {
        $exists_ids = array();
        $collect = collect($datas);
        $chunks = $collect->chunk($size); // 分組，一組 $size  個
        $unique_field = 'bet_id';
        // 分組取出己經存在的押注記錄
        foreach ($chunks as $chunk) {
            $ids = $chunk->pluck($unique_field)->toArray();
            array_push(
                $exists_ids,
                ...HaolongBetRecord::whereIn($unique_field, $ids)
                ->select($unique_field)
                ->get()
                ->pluck($unique_field)
                ->toArray()
            );
        }

        // 過濾掉己經存在的押注記錄
        return $collect->filter(function($item) use($unique_field, $exists_ids){
            return !in_array($item[$unique_field], $exists_ids);
        })->all();
    }
}