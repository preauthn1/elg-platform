<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Helpers\Common;
use App\Services\TelegramService;
use DB;

class CheckMemberBet extends Command
{

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'check_member_bet'; //php artisan check_member_bet，每次跑前一小時記錄

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '檢查玩家投注情形';

    protected $tgService;

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
        $this->tgService = new TelegramService();
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $data = array();
        $end = date('Y-m-d H:00:00', strtotime('-1hour'));
        $hour = date('H', strtotime($end));
        $start = ($hour < 16)
            ? date('Y-m-d 16:00:00', strtotime('-1day'))
            : date('Y-m-d 16:00:00', strtotime($end));
        $data['StartTime'] = $start;
        $data['EndTime'] = $end;
        $data['Today'] = date('Y-m-d', strtotime('+8hour'));
        $this->checkMemberBet($data);
    }

    private function checkMemberBet($postData)
    {	
		try {
            echo '-checkMemberBetWinlose-' . PHP_EOL;
            echo '-range- ' . $postData['StartTime'] . ' ~ ' . $postData['EndTime'] . PHP_EOL;
            echo '=============================================' . PHP_EOL;

            $agents = json_decode(env('TG_SUMMARY_REPORT_AGENTS'), true) ? : [];
            foreach($agents as $agent) {
                echo $agent['title'] . PHP_EOL;
                $data = array(
                    'today' => $postData['Today'],
                    'start' => $postData['StartTime'],
                    'end' => $postData['EndTime'],
                    'title' => $agent['title'],
                    'currency' => $agent['currency'],
                    'path' => $agent['path'],
                    'warn_amount' => (isset($agent['warn_amount']) ? $agent['warn_amount'] : env('WARN_AMOUNT', 100000))
                );
                $this->getMemberTotalBet($data);
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

	private function getMemberTotalBet($data)
    {
        //撈出今天投注額
        $model = DB::table('summary_bet_records as b')
            ->join('members as m', 'm.id', 'b.member_id')
            ->join('agents as a', function ($join) use ($data) {
                $join->on('a.id', 'm.parent')
                    ->where('a.hierarchy_path', 'like', $data['path'] . '%');
            })
            ->whereBetween('b.summary_time', [$data['start'], $data['end']])
            ->groupBy('m.id')
            ->orderBy('bet', 'desc')
            ->select(
                'a.username as agent_username',
                'a.id as agent_id',
                'm.username as member_username',
                'b.member_id',
                DB::raw('SUM(b.bet) AS bet'),
                DB::raw('SUM(b.win_lose) AS win_lose')
            )
            ->offset(0)
            ->limit(10);

        /*
         * // 大額玩家
        $model = DB::table(DB::raw("({$main->toSql()}) AS z"))
            ->mergeBindings($main)
            ->where('z.bet', '>', $data['warn_amount']);
        */

        $count = $model->count();
        $model = $model->get();

        if (is_object($model) && $count > 0) {
            $message = '<pre>[' . $data['title'] . ' 投注額前十玩家 ' . $data['today'] . ']</pre> ';
            $message .= '<pre>----------------------- </pre> ';
            foreach($model as $row) {
                $message .= '<pre>' . $row->member_username . '(' . $row->agent_username . ')/' .
                    number_format($row->bet, 2) . '/' .
                    number_format($row->win_lose, 2) . '</pre> ';
                echo $row->member_username . ' => ' .
                    number_format($row->bet, 2) . ' / ' .
                    number_format($row->win_lose, 2) .
                    PHP_EOL;
            }
            //發送telegram通知
            $this->tgService->sendMessage($message, 'SUMMARY', 'HTML');  //Markdown OR HTML
        }
        echo PHP_EOL;
    }
}