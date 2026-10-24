<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Helpers\Common;
use App\Models\Member;
use App\Models\FlowLimit;
use App\Models\TransactionLog;
use DB;

class CheckFlowLimit extends Command
{

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'check_flow_limit {--start=} {--end=}'; //php artisan check_flow_limit --start=2019-10-03@05:00 --end=2019-10-01@01:00，每次跑一小時前記錄

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '檢查稽核流水';

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
        $data = array();
        $start = $this->option('start') ? str_replace('@', ' ', $this->option('start')) : date('Y-m-d H:00', strtotime('-1hour'));
        $end = $this->option('end') ? str_replace('@', ' ', $this->option('end')) : date('Y-m-d H:00');
        $data['StartTime'] = $start;
        $data['EndTime'] = $end;
        $this->checkFlowLimit($data);
    }

    private function checkFlowLimit($postData)
    {	
		try {
            echo '-checkFlowLimit-' . PHP_EOL;
            echo '-range- '.$postData['StartTime'] . ' ~ ' . $postData['EndTime'] . PHP_EOL;
            echo '=============================================' . PHP_EOL;
			//撈出前一個小時前未通過的稽核流水單據
			$model = new FlowLimit();
			$data = $model->where('status', 0)
				->where('created_at', '<=', $postData['EndTime'])
				->orderBy('member_id', 'asc')
				->orderBy('id', 'asc')
				->select(
					'id', 
					'member_id', 
					'flow_limit', 
					'reach',
					'status'
				)
				->get();
				
			$member_id = 0;
			$valid_bet = 0;
            echo '-------- Flow Limit Count '. Count($data).' -----------------' . PHP_EOL;
			foreach($data as $row) {
				if ($member_id != $row['member_id']) {
					$member_id = $row['member_id'];
					$valid_bet_data = DB::table('summary_bet_records as s')
						->where('s.member_id', $member_id)
						->whereBetween('s.summary_time', [$postData['StartTime'], $postData['EndTime']])
						->select(
							DB::raw('IFNULL(SUM(CONVERT(s.bet, decimal(16,2))), 0) as valid_bet')
						)
						->first();
					$valid_bet = (is_object($valid_bet_data)) ? $valid_bet_data->valid_bet : 0;
            		echo '---------------------------------------------' . PHP_EOL;
					echo 'member_id:' . $member_id . ' valid_bet:'. $valid_bet . PHP_EOL;
				}
				
				if ($valid_bet > 0) {
					//該筆稽核流水差額
					$gap = $row['flow_limit'] - $row['reach'];
					//差額大於流水
					if ($gap > $valid_bet) {
						$reach = $row['reach'] + $valid_bet;
						$valid_bet = 0; 
						$status = 0;
					} else {
						$reach = $row['flow_limit'];
						$valid_bet = $valid_bet - $gap;
						$status = 1;
					}
					
					//更新該筆資訊
					$record = FlowLimit::lockForUpdate()->find($row['id']);
					$data = array();
					$data['reach'] = $reach;
					$data['status'] = $status;
					$result = $record->update($data);
					Common::log(0, ['request' => $data, 'response' => $result], 'checkFlowLimit');
					echo '[UPDATE]'.
						' id:' . $row['id'] . 
						' member_id:' . $row['member_id'] . 
						' flow_limit:' . $row['flow_limit'] . 
						' reach:' . $row['reach'] . '->' . $reach . 
						' status:' . $row['status'] . '->' . $status . 
						' valid_bet:' . $valid_bet . 
						PHP_EOL;
				}
			}
			
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
}