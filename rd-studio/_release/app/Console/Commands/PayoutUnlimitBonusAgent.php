<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Helpers\Common;
use App\Models\UnlimitBonusreportAgent;
use App\Models\TransactionLogAgent;
use DB;

class PayoutUnlimitBonusAgent extends Command
{

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'payout_unlimit_bonus_agent {--date=} {--name=}'; //php artisan payout_unlimit_bonus_agent --date=2019-10-03 --name=agent，每次跑一天記錄

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '代理無限代獎勵發放';

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
        $date = $this->option('date') ? $this->option('date') : date('Y-m-d 00:00:00', strtotime('-1day'));
        $agent_name = $this->option('name') ? $this->option('name') : null;
        $data['date'] = $date;
        $data['agent_name'] = $agent_name;
        $this->checkFlowLimit($data);
    }

    private function checkFlowLimit($postData)
    {	
		try {
            echo '-Payout Unlimit Bonus Agent-' . PHP_EOL;
            echo '-Date- '.$postData['date'] . PHP_EOL;
            echo '=============================================' . PHP_EOL;
			//撈出當天未發放的無限代代理獎勵
            $data = DB::table('unlimit_bonusreport_agents as u')
                ->join('agents as a', function($join) use ($postData) {
                    $join->on('a.id', 'u.agent_id')
                        ->where('a.agent_mode', 1);
                    if ($postData['agent_name'] != null) {
                        $join->where('a.username', $postData['agent_name']);
                    }
                })
                ->where('u.summary_date', $postData['date'])
                ->whereNotIn('u.status', [1000,2041])  //已完成不處理
				->orderBy('u.id', 'asc')
				->select(
				    'u.id',
                    DB::raw("'unlimit' as from_username"),
                    DB::raw('a.username as to_username'),
					'u.agent_id',
					DB::raw('CONVERT(u.bonus_direct + u.bonus_team, DECIMAL(16, 2)) AS amount'),
                    DB::raw('CONVERT(u.bonus_direct, DECIMAL(16, 2)) as bonus_direct'),
                    DB::raw('CONVERT(u.bonus_team, DECIMAL(16, 2)) as bonus_team')
				)
                ->get();

            echo '-------- Unlimit Bonus Agent Count ' . Count($data) . ' -----------------' . PHP_EOL;
			foreach($data as $row) {
			    //發到CS API做處理
                $res = $this->post($row);
                //更新紀錄
                if (isset($res['code'])) {
                    $result = UnlimitBonusreportAgent::find($row->id)->update([
                        'status' => $res['code'],
                        'payout_time' => date('Y-m-d H:i:s'),
                        'remark' => $res['message']
                    ]);
                    echo 'id:' . $row->id .
                        ' from:' . $row->from_username .
                        ' to:' . $row->to_username .
                        ' amount:' . $row->amount .
                        ' code:' . $res['code'] .
                        ' message:' . $res['message'] .
                        ' result:' . $result .
                        PHP_EOL;
                }
			}
        } catch (\Illuminate\Database\QueryException $e) {
            $info = $e->errorInfo;
            print_r($info);
            echo PHP_EOL;
            return response()->json([
                'code' => $info[0],
                'message' => $info[2]
            ]);
        } catch (\Exception $e) {
            echo $e->getMessage() . PHP_EOL;
            return response()->json([
                'code' => $e->getCode(),
                'message' => $e->getMessage()
            ]);
        }
	}

    private function post($data)
    {
        $action = 'agentUnlimitBonus';
        Common::log(0, (array)$data, $action);
        $url = env('CS_API') . $action;
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL,  $url);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_POST, TRUE);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Content-Type:application/json;charset=utf-8',
        ));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        $result = curl_exec($ch);
        curl_close($ch);
        Common::log(0, ['url' => $url, 'request' => $data, 'response' => $result], $action);
        return json_decode($result, true);
    }
}