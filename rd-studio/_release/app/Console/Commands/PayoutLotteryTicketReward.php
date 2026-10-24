<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Helpers\Common;
use App\Models\LuckyDrawTicket;
use App\Models\PrizeMember;
use App\Services\TelegramService;
use DB;

class PayoutLotteryTicketReward extends Command
{

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'payout_lottery_ticket_reward {--dateHour=}';
    //php artisan payout_lottery_ticket_reward --dateHour=2019-10-03@11, 每次跑一天記錄

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '抽獎券獎勵發放';
    protected $tgService;

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
        $this->tgService = New TelegramService();
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $data = array();
        $draw_date = $this->option('dateHour')
            ? str_replace('@', ' ', $this->option('dateHour')) . ':00:00'
            : date('Y-m-d H:00:00');
        $data['draw_date'] = $draw_date;
        $this->payoutLotteryTicketReward($data);
    }

    private function payoutLotteryTicketReward($postData)
    {	
		try {
            echo '-Payout Lottery Ticket Reward-' . PHP_EOL;
            echo '-Draw Date- ' . $postData['draw_date'] . PHP_EOL;
            echo '=============================================' . PHP_EOL;
            $reward_list = DB::select("select l.id as lucky_draw_id, r.level, TRUNCATE(r.bonus, 2) as bonus, 
case when r.level = 1 then l.draw_number 
when r.level = 2 then CONCAT(SUBSTRING_INDEX(l.draw_number, '-', 1), '%') 
else CONCAT(LEFT(SUBSTRING_INDEX(l.draw_number, '-', 1), (8 - r.level)), '%') end as l_no, 
case when r.level > 2 then CONCAT('%', RIGHT(SUBSTRING_INDEX(l.draw_number, '-', 1), (8 - r.level)), '-%') ELSE '' end as r_no,
p.id as prize_id,
r.name as draw_name,
pc.id as prize_content_id
from lucky_draws as l
join reward_items as r
join prizes as p on p.event_id = l.event_id
join prize_contents as pc on pc.prize_id = p.id and pc.point = r.bonus
where l.draw_time <= ? and l.status = 0 and l.draw_number is not null
order by r.level", array($postData['draw_date']));

            if (count($reward_list) > 0) {
                $lucky_draw_id = 0;
                $message = '<pre>[抽獎券開奬日期 ' . date('Y-m-d H:00:00', strtotime($postData['draw_date'] . '+8 Hour')) . ']</pre>  ';
                $message .= '-----------------------  ';
                foreach ($reward_list as $row) {
                    if ($row->level == 1) {
                        $message .= '<pre>開奬號碼:' . $row->l_no . '</pre>  ';
                    }
                    echo 'lucky_draw_id:' . $row->lucky_draw_id .
                        ',level:' . $row->level .
                        ',bonus:' . $row->bonus .
                        ',l_no:' . $row->l_no .
                        ',r_no' . $row->r_no .
                        ',prize_id:' . $row->prize_id .
                        PHP_EOL;
                    $lucky_draw_id = $row->lucky_draw_id;

                    DB::update('update lucky_draws set status = ? WHERE id = ?', array(2, $lucky_draw_id));

                    $data = LuckyDrawTicket::where('lucky_draw_id', $row->lucky_draw_id)
                        ->where('reward_item_id', 0)
                        ->where(function ($query) use ($row) {
                            $query->where('ticket_number', 'like', $row->l_no);
                           if ($row->r_no != '') {
                               $query->orWhere('ticket_number', 'like', $row->r_no);
                           }
                        })
                        ->select(
                            'id',
                            'member_id',
                            'ticket_number'
                        )
                        ->get();
                    $count = 0;
                    foreach($data as $r) {
                        DB::update(
                            'update lucky_draw_tickets set reward_item_id = ?, point = ?, type = 1 WHERE id = ?',
                            array($row->level, $row->bonus, $r->id)
                        );

                        //prize_contents sent + 1
                        DB::update(
                            'update prize_contents set sent = sent + 1 WHERE id = ?',
                            array($row->prize_content_id)
                        );

                        $prize = [
                            'member_id' => $r->member_id,
                            'prize_id' => $row->prize_id,
                            'content_id' => $row->prize_content_id,
                            'lottery_number' => $r->id,
                            'admin' => 0
                        ];
                        PrizeMember::create($prize);
                        $count++;
                    }
                    $message .= '<pre>' . $row->draw_name . '|' . $row->bonus . '|' . $count . '</pre>  ';
                }
                DB::update('update lucky_draws set status = ? WHERE id = ?', array(1, $lucky_draw_id));
                $this->tgService->sendMessage($message, 'warn', 'HTML');
            }
            echo '=============================================' . PHP_EOL;
            echo 'END' . PHP_EOL;
        } catch (\Illuminate\Database\QueryException $e) {
            $info = $e->errorInfo;
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
}