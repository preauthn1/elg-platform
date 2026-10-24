<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Helpers\Common;
use App\Services\TelegramService;
use DB;

class TGSummaryReport extends Command
{

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'tg_summary_report'; //php artisan tg_summary_report

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '';

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
        // 預設取前一個小時當天的數據
        $today = date('Y-m-d', strtotime('+7hour'));
        $start = date('Y-m-d H:i:s', strtotime($today . ' -8 hours'));
        $end = date('Y-m-d H:i:s', strtotime($start . ' +1 day'));

        $data = array(
            'today' => $today,
            'start' => $start,
            'end'   => $end
        );

        $this->tgService->sendMessage('－－－－－－－－－－－－－', 'summary', 'HTML');  //Markdown OR HTML

        $tg_agents = json_decode(env('TG_SUMMARY_REPORT_AGENTS'), true) ? : [];
        var_dump(json_last_error_msg());
        var_dump($tg_agents);
        foreach($tg_agents as $agent) {
            $this->summaryInfo($agent['title'], $agent['currency'], $agent['path'], $data);
        }
    }


	private function summaryInfo($title, $currency, $agent_path, $data) {
        $query = DB::table('members as m')
            ->join('agents as a', 'm.parent', 'a.id')
            ->where('m.created_at', '>=', $data['start'])
            ->where('m.created_at', '<', $data['end'])
            ->where('a.hierarchy_path', 'like', $agent_path . '%')
            ->select( DB::raw('count(0) as total_reg, sum(IF(m.mobile_validate = 1, 1, 0)) as total_reg_mobile'));
        $member_info = $query->first();
        //var_dump($member_info);

        $query = DB::table('transaction_logs as t')
            ->join('agents as a', 't.agent_id', 'a.id')
            ->where('t.status', 1000)
            ->whereIn('t.type', [3, 4])
            ->where('t.created_at', '>=', $data['start'])
            ->where('t.created_at', '<', $data['end'])
            ->where('a.hierarchy_path', 'like', $agent_path . '%')
            ->select(DB::raw('sum(t.point) as total_deposit'));
        $deposit = $query->first();
        $query = DB::table('transaction_logs as t')
            ->join('agents as a', 't.agent_id', 'a.id')
            ->where('t.status', 1000)
            ->whereIn('t.type', [5])
            ->where('t.created_at', '>=', $data['start'])
            ->where('t.created_at', '<', $data['end'])
            ->where('a.hierarchy_path', 'like', $agent_path . '%')
            ->select(DB::raw('sum(t.point) as total_withdraw'));
        $withdraw = $query->first();
        //var_dump($deposit);

        $query = DB::table('summary_bet_records as s')
            ->join('members as m', 's.member_id', 'm.id')
            ->join('agents as a', 'm.parent', 'a.id')
            ->where('s.summary_time', '>=', $data['start'])
            ->where('s.summary_time', '<', $data['end'])
            ->where('a.hierarchy_path', 'like', $agent_path . '%')
            ->select(DB::raw('sum(s.bet) as total_bet, sum(s.win_lose) as total_win_lose'));
        $bet_info = $query->first();
        //var_dump($bet_info);

        $text = sprintf(
            "\n%s 《 *%s* 》 \n注冊人數 *%d* ( 綁定 *%d* )\n總開分 *%s* %s 總洗分 *%s* %s 洗分率 *%.2f* %%\n總投注 *%s* %s 總輸贏 *%s* %s 殺數 *%.2f* %%\n",
            $data['today'],
            $title,
            $member_info->total_reg,
            $member_info->total_reg_mobile,
            number_format($deposit->total_deposit),
            $currency,
            number_format($withdraw->total_withdraw),
            $currency,
            ($deposit->total_deposit) > 0 ? $withdraw->total_withdraw / $deposit->total_deposit * 100:0,
            number_format($bet_info->total_bet),
            $currency,
            number_format($bet_info->total_win_lose),
            $currency,
            ($bet_info->total_bet > 0)?-1 * $bet_info->total_win_lose / $bet_info->total_bet * 100:0

        );
        $text = urlencode($text);
        $this->tgService->sendMessage($text, 'summary', 'Markdown');  //Markdown OR HTML
        echo $text;
    }
}