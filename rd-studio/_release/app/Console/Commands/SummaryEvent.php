<?php

namespace App\Console\Commands;

use App\Helpers\SQLHelper;
use Illuminate\Console\Command;
use App\Helpers\Common;
use App\Models\EventDetail;
use App\Models\EventProcess;
use App\Models\FirebaseCloudMessage;
use App\Models\PrizeMember;
use App\Models\Order;
use App\Models\Member;
use App\Models\Prize;
use App\Models\SummaryBetRecord;
use App\Models\VendorHourRecord;
use App\Models\LuckyDrawTicket;
use App\Models\WJackpotTicket;
use App\Models\TempLotteryTicket;
use App\Models\SummaryControlLog;
use App\Models\SummaryControlTable;
use App\Models\EventSlotCompetition;
use App\Models\EventSlotCompetitionRecord;
use DB;
use App\REDIS;

class SummaryEvent extends Command
{

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'summary_event {--eventId=}';  //php artisan summary_event --eventId=123

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '統計活動條件達成金額';

    protected $summary_control_table_id = 11002;

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
        echo 'summary_event' . PHP_EOL;
        try {
            $exec_start_time = date('Y-m-d H:i:s');
            echo 'start:' . $exec_start_time . PHP_EOL;

            $start_time = date('Y-m-d H:i:s');
            $end_time = date('Y-m-d H:i:s', strtotime('-1day'));

            $event_id = $this->option('eventId')
                ? $this->option('eventId')
                : 0;

            //Common::log(0, 'summary event start', 'summary_event');

            // 先取活動進行中的detail,統計到活動結束後一天
            $details = DB::table('event_details as ed')
                ->join('events as e', function ($join) {
                    $join->on('e.id', 'ed.event_id')
                        ->where('e.status', 1);
                })
                ->where('ed.status', 1)
                ->where('ed.start_time', '<=', $start_time)
                ->where('ed.end_time', '>=', $end_time);
            if ($event_id > 0) {
                $details = $details->where('ed.event_id', $event_id);
            }
            $details = $details->select(
                'ed.id',
                'ed.action_id',
                'ed.wallet_type_id',
                'ed.event_id',
                'ed.order_type_id',
                DB::raw('IFNULL(ed.vendor_id, 0) as vendor_id'),
                DB::raw('IFNULL(ed.game_type_id, 0) as game_type_id'),
                DB::raw('IFNULL(ed.game_id, 0) as game_id'),
                'ed.game_join_event_used',
                'ed.rate',
                'ed.point',
                'ed.start_time',
                'ed.end_time',
                'e.agent_id'
            )->get();

            $params = '';
            foreach ($details as $row) {
                $detail_start_time = date('Y-m-d H:i:s');
                switch ($row->action_id) {
                    case 1: // 儲值
                        $this->deposit($row);
                        break;
                    case 2: // 投注額
                    case 8: // slot competition
                        $this->bet($row);
                        break;
                    case 3: // 勝分
                        $this->win($row);
                        break;
                    case 4: // 首儲
                        $this->firstDeposit($row);
                        break;
                    case 9: // 輸分
                        $this->lose($row);
                        break;
                }

                # 計算相差之秒數
                $params .= $row->event_id . ':' . (strtotime(date('Y-m-d H:i:s')) - strtotime($detail_start_time)) . ',';
            }

            // 計算
            $data = DB::table('events as e')
                ->join('event_details as d', function ($join) {
                    $join->on('d.event_id', 'e.id')
                        ->where('d.status', 1)
                        ->where('d.action_id', '<', 6)
                        ->where('d.start_time', '<=', date('Y-m-d H:i:s'))
                        ->where('d.end_time', '>=', date('Y-m-d H:i:s', strtotime('-1day')));
                })
                ->join('prizes AS p', 'p.event_id', 'e.id')
                ->leftJoin('event_processes AS ep', 'ep.detail_id', 'd.id')
                ->leftJoin('lucky_draws AS ld', 'ld.event_detail_id', 'd.id')
                ->leftJoin('w_jackpots AS wj', 'wj.event_detail_id', 'd.id')
                ->where('e.status', 1);
            if ($event_id > 0) {
                $data = $data->where('e.id', $event_id);
            }
            $data = $data->groupBy('e.id')
                ->groupBy('ep.member_id')
                ->groupBy('detail_id')
                ->having('number', '>=', 1)
                ->select(
                    'e.id as event_id',
                    'd.id as event_detail_id',
                    'ep.id as process_id',
                    'ep.member_id',
                    'p.id as prize_id',
                    DB::raw('MIN(ep.point/d.point) as number'),
                    'p.is_repeatedly',
                    DB::raw('COUNT(p.id) as process_number'),
                    'd.start_time',
                    'd.end_time',
                    'p.type_id',
                    //判斷是不是抽獎券活動
                    DB::raw('CASE WHEN p.type_id = 5 THEN d.id WHEN p.type_id = 6 THEN d.id ELSE 0 END AS detail_id'),
                    'ld.id AS lucky_draw_id',
                    'wj.id AS w_jackpot_id',
                    'e.title'
                )
                ->get();
            $error_message = '';
            foreach ($data as $row) {
                echo $row->title.'('.$row->event_id.' '.$row->detail_id . ' ' . $row->type_id . ')' . $row->member_id . PHP_EOL;
                $detail = DB::table('events as e')
                    ->join('event_details as d', function ($join) use ($row) {
                        $join->on('d.event_id', 'e.id');
                        if (in_array($row->type_id, [5, 6])) {
                            $join->where('d.id', $row->detail_id);
                        }
                    })
                    ->where('e.id', $row->event_id)
                    ->where('d.status', 1)
                    ->where('d.start_time', '<=', $start_time)
                    ->where('d.end_time', '>=', $end_time)
                    ->count();

                //先確認是否符合條件，進度達成的數量等於detail的數量
                if ($row->process_number == $detail) {
                    // 更新進度狀態
                    $processes = explode(',', $row->process_id);
                    foreach ($processes as $value) {
                        EventProcess::find($value)->update([
                            'status' => 2
                        ]);
                    }

                    $total_number = ($row->is_repeatedly == 0)
                        ? 1 //不可重覆領獎
                        : floor($row->number); //可重覆領獎

                    // 給獎
                    if ($row->type_id == 5) {
                        //驗證給券的數量是否正確
                        $ticket_member_number = LuckyDrawTicket::where('member_id', $row->member_id)
                            ->where('lucky_draw_id', $row->lucky_draw_id)
                            ->count();

                        $remainder = $total_number - $ticket_member_number; //還未給獎的次數

                        if ($remainder > 0) {
                            //當派獎數大於1000時先派1000張
                            $remainder = ($remainder > 5000) ? 5000 : $remainder;
                            //派發抽獎券
                            DB::select('call SP_set_lucky_ticket(' . $row->lucky_draw_id . ', ' . $row->member_id . ', ' . $remainder . ')');
                            echo '派發抽獎券(' . $row->lucky_draw_id . ') ' . $row->member_id . ' => ' . $remainder . PHP_EOL;
                        }
                    } elseif ($row->type_id == 6) {
                        //驗證給券的數量是否正確
                        $ticket_member_number = WJackpotTicket::where('member_id', $row->member_id)
                            ->where('w_jackpot_id', $row->w_jackpot_id)
                            ->count();

                        $remainder = $total_number - $ticket_member_number; //還未給獎的次數

                        if ($remainder > 0) {
                            //當派獎數大於1000時先派1000張
                            $remainder = ($remainder > 5000) ? 5000 : $remainder;
                            //派發抽獎券
                            DB::select('call SP_set_w_jackpot_ticket(' . $row->event_id . ', ' . $row->w_jackpot_id . ', ' . $row->member_id . ', ' . $remainder . ')');
                            echo '派發W Jackpot抽獎券(' . $row->w_jackpot_id . ') ' . $row->member_id . ' => ' . $remainder . PHP_EOL;
                        }
                    } else {
                        //驗證給獎的數量是否正確
                        $prize_member_number = PrizeMember::where("member_id", $row->member_id)
                            ->where('prize_id', $row->prize_id)
                            ->whereBetween('created_at', [$row->start_time, $row->end_time])
                            ->count();

                        $remainder = $total_number - $prize_member_number; //還未給獎的次數
                        if ($remainder > 0) {
                            for ($i = 0; $i < $remainder; $i++) {
                                $prize = [
                                    'member_id' => $row->member_id,
                                    'prize_id' => $row->prize_id
                                ];
                                PrizeMember::create($prize);

                                //新增推播
                                $message = [
                                    'member_id' => $row->member_id,
                                    'title' => '達成兌獎目標',
                                    'content' => '請登入遊戲兌獎',
                                    'url' => env('PUSH_URL') . 'Events'
                                ];
                                //FirebaseCloudMessage::create($message);
                            }
                        } else  {
                            $error_message .= $remainder . '--' . $row->member_id . '--' . $row->prize_id . PHP_EOL;
                        }
                    }
                }
            }

            if ($error_message != '') {
                Common::log(0, $error_message, 'summary_event_ERROR');
            }

            //DB::select('call SP_summary_event_main(' . $event_id . ' )');

            $exec_end_time = date('Y-m-d H:i:s');
            echo '  end:' . $exec_end_time . PHP_EOL;
            echo 'exec time:' . (strtotime($exec_end_time) - strtotime($exec_start_time)) . PHP_EOL;

            $summary_control_table = SummaryControlTable::find($this->summary_control_table_id);
            $data = array();
            $data['stat_start_time'] = $start_time;
            $data['stat_end_time'] = $end_time;
            $data['exec_start_time'] = $exec_start_time;
            $data['exec_end_time'] = $exec_end_time;
            $summary_control_table->update($data);

            $data = array();
            $data['vendor_id'] = $this->summary_control_table_id;
            $data['stat_start_time'] = $start_time;
            $data['stat_end_time'] = $end_time;
            $data['exec_start_time'] = $exec_start_time;
            $data['exec_end_time'] = $exec_end_time;
            $data['params'] = $params;
            SummaryControlLog::create($data);

        } catch (\Illuminate\Database\QueryException $e) {
            $info = $e->errorInfo;
            echo 'code:'. $info[0] . ' message:' . $info[2] . PHP_EOL;
        } catch (\Exception $e) {
            echo $e->getMessage() . '|' .
                $e->getFile() . '|' .
                $e->getLine() . PHP_EOL;
        }
    }

    private function deposit($data)
    {
        //如有用到此活動, 以下SQL要確認
        echo '----------- deposit - ' . $data->id . ' -----------' . PHP_EOL;
        $agent = DB::table('agents')->where('id', $data->agent_id)->first();

        // action id 1 儲值
        $model = DB::table('orders as r')
            ->join('members as m', 'm.id', 'r.member_id')
            ->join('agents as a', function ($join) use ($agent) {
                $join->on('a.id', 'm.parent')
                    ->where('a.hierarchy_path', 'like ', $agent->hierarchy_path . '%');
            })
            ->whereBetween('r.updated_at', [
                $data->start_time,
                $data->end_time
            ])
            ->where('r.status', 1);
        if ($data->order_type_id != '') {
            $model->where('r.order_type_id', $data->order_type_id);
        }
        $result = $model->groupBy('r.member_id')
            ->having('point', '>', 0)
            ->select(
                'r.member_id',
                DB::raw("SUM(r.point) as point")
            )
            ->get();

        foreach ($result as $row) {
            $process = [
                'member_id' => $row->member_id,
                'detail_id' => $data->id,
                'point' => $row->point,
                'status' => ($row->point >= $data->point) ? 1 : 0
            ];
            $this->addProcess($process);
        }
    }

    private function bet($data)
    {
        echo '----------- bet - ' . $data->id . ' ---------------' . PHP_EOL;
        $agent = DB::table('agents')->where('id', $data->agent_id)->first();

        //只統計有參加活動的會員
        $main = DB::table('vendor_hour_records AS r')
            ->join('members AS m', 'm.id', 'r.member_id')
            ->join('agents AS a', function ($join) use ($agent) {
                $join->on('a.id', 'm.parent')
                    ->where('a.hierarchy_path', 'like', $agent->hierarchy_path . '%');
            })
            ->join('vendors as v', function ($join) use ($data) {
                $join->on('v.id', 'r.vendor_id');

                if ($data->game_join_event_used == 0) {
                    //排除豪龍
                    $join->whereRaw('v.valid_bet_rate > 0');
                }

                //舊資料沒有GAME ID，但是是有統計過的，所以可以直接加入
                if ($data->vendor_id > 0) {
                    $join->where('v.id', $data->vendor_id);
                }
            })
            ->leftJoin('games as g', function ($join) use ($data) {
                $join->on('g.id', 'r.game_id');
                if ($data->game_type_id > 0) {
                    $join->where('g.type_id', $data->game_type_id);
                }
            });

        if ($data->game_join_event_used == 1) {
            $main = $main->whereNotNull('g.id');
        }
        $main = $main->whereRaw("r.date_hour between '". $data->start_time . "' and '" . $data->end_time . "'")
            ->groupBy('m.id')
            ->groupBy('r.game_id')
            ->havingRaw('bet > 0')
            ->select(
                'm.id AS member_id',
                'a.id AS agent_id',
                'v.id AS vendor_id',
                'r.game_id',
                'a.hierarchy_path',
                DB::raw('SUM(r.bet) AS bet'),
                DB::raw('MAX(r.last_bet_time) AS last_bet_time')
            );

        $sub = DB::table('agent_vendors AS av')
            ->join('vendor_infos as vi', function ($join) {
                $join->on('vi.id', 'av.vendor_info_id')
                    ->whereRaw('vi.status = 1');
            })
            ->join('agents AS a', function ($join) use ($agent) {
                $join->on('a.id', 'av.agent_id')
                    ->whereRaw('a.host_id = 0')
                    ->whereRaw("a.hierarchy_path like '" . $agent->hierarchy_path . "%'");
            })
            ->whereRaw('av.status = 1')
            ->groupBy('av.vendor_id')
            ->groupBy('av.vendor_info_id')
            ->select(
                'av.vendor_id',
                DB::raw('MIN(a.hierarchy_path) AS hierarchy_path'),
                'av.vendor_info_id',
                DB::raw("MIN(LOCATE(CONCAT('/', av.agent_id), a.hierarchy_path)) AS indexs")
            );

        $model = DB::table(DB::raw("({$main->toSql()}) AS r"))
            ->mergeBindings($main)
            ->join(DB::raw("({$sub->toSql()}) AS av"), 'av.vendor_id', 'r.vendor_id')
            //->mergeBindings($sub)
            ->leftJoin('vendor_info_games AS vig', function ($join) use ($data) {
                $join->on('vig.game_id', 'r.game_id')
                    ->whereRaw('vig.vendor_info_id = av.vendor_info_id');
            });

        if ($data->game_join_event_used == 1) {
            $model = $model->whereRaw('vig.join_bet_event = 1');
        } else {
            //排除對壓遊戲
            $model = $model->where(function ($query) {
                $query->whereNull('vig.valid_bet_rate')
                    ->orWhereRaw('vig.valid_bet_rate > 0');
            });
        }
        $model = $model->where('r.hierarchy_path', 'like', DB::raw("concat(av.hierarchy_path, '%')"))
            ->groupBy('r.member_id')
            ->groupBy('r.game_id')
            ->groupBy('av.vendor_info_id')
            ->select(
                'r.member_id',
                'r.game_id',
                'r.bet',
                'r.last_bet_time'
            );
        $result = DB::table(DB::raw("({$model->toSql()}) AS t"))
            ->mergeBindings($model)
            ->groupBy('t.member_id')
            ->having('point', '>', 0)
            ->select(
                't.member_id',
                DB::raw('SUM(t.bet) as point'),
                DB::raw('MAX(t.last_bet_time) as last_bet_time')
            )
            ->get();

        foreach ($result as $row) {
            $process = [
                'member_id' => $row->member_id,
                'detail_id' => $data->id,
                'point' => $row->point,
                'status' => ($row->point >= $data->point) ? 1 : 0,
                'last_bet_time' => $row->last_bet_time
            ];
            $this->addProcess($process, $data->action_id);
        }
    }

    private function win($data)
    {
        echo '----------- win - ' . $data->id . ' ---------------' . PHP_EOL;
        $agent = DB::table('agents')->where('id', $data->agent_id)->first();
        //如有用到此活動, 以下SQL要確認
        // action id 3 勝分 只統計有參加活動的會員
        $main = DB::table('vendor_hour_records AS r')
            ->join('members AS m', 'm.id', 'r.member_id')
            ->join('agents AS a', function ($join) use ($agent) {
                $join->on('a.id', 'm.parent')
                    ->where('a.wallet_group_id', $agent->wallet_group_id);
            })
            ->join('vendors as v', function ($join) use ($data) {
                $join->on('v.id', 'r.vendor_id');

                if ($data->game_join_event_used == 0) {
                    //排除豪龍
                    $join->whereRaw('v.valid_win_rate > 0');
                }

                //舊資料沒有GAME ID，但是是有統計過的，所以可以直接加入
                if ($data->vendor_id > 0) {
                    $join->where('v.id', $data->vendor_id);
                }
            })
            ->leftJoin('games as g', function ($join) use ($data) {
                $join->on('g.id', 'r.game_id');
                if ($data->game_type_id > 0) {
                    $join->where('g.type_id', $data->game_type_id);
                }
            });

        if ($data->game_join_event_used == 1) {
            $main = $main->whereNotNull('g.id');
        }
        $main = $main->whereRaw("r.date_hour between '". $data->start_time . "' and '" . $data->end_time . "'")
            ->where('r.wallet_type_id', $data->wallet_type_id)
            ->groupBy('m.id')
            ->groupBy('r.wallet_type_id')
            ->groupBy('r.game_id')
            ->groupBy('r.game_id')
            ->havingRaw('win > 0')
            ->select(
                'm.id AS member_id',
                'a.id AS agent_id',
                'r.wallet_type_id AS wallet_type_id',
                'r.vendor_id AS vendor_id',
                'r.game_id',
                'a.vendor_customize_group_id',
                'a.wallet_group_id',
                DB::raw('SUM(r.win) AS win')
            );

        $sub = DB::table('agent_vendors AS av')
            ->join('agents AS a', function ($join) use ($agent) {
                $join->on('a.id', 'av.agent_id')
                    ->whereRaw('a.host_id = 0')
                    ->whereRaw("a.hierarchy_path like '" . $agent->hierarchy_path . "%'");
            })
            ->whereRaw('av.status = 1')
            ->select(
                'av.vendor_id',
                'a.hierarchy_path',
                'av.vendor_info_id',
                DB::raw("LOCATE(CONCAT('/', av.agent_id), a.hierarchy_path) AS indexs")
            );

        $model = DB::table(DB::raw("({$main->toSql()}) AS r"))
            ->mergeBindings($main)
            ->join('game_infos as gi', function ($join) {
                $join->on('gi.game_id', 'r.game_id')
                    ->on('gi.agent_group_id', 'r.vendor_customize_group_id');
            })
            ->leftJoin('vendor_info_games AS vig', function ($join) use ($data) {
                $join->on('vig.game_id', 'r.game_id')
                    ->whereRaw('vig.vendor_info_id = av.vendor_info_id');
            });

        if ($data->game_join_event_used == 1) {
            $model = $model->whereRaw('vig.join_win_event = 1');
        } else {
            //排除對壓遊戲
            $model = $model->where(function ($query) {
                $query->whereNull('vig.valid_win_rate')
                    ->orWhereRaw('vig.valid_win_rate > 0');
            });
        }
        $model = $model->where('r.hierarchy_path', 'like', DB::raw("concat(av.hierarchy_path, '%')"))
            ->groupBy('r.member_id')
            ->groupBy('r.game_id')
            ->groupBy('av.vendor_info_id')
            ->havingRaw('max(av.indexs)')
            ->select(
                'r.member_id',
                'r.game_id',
                'r.bet'
            );
        $result = DB::table(DB::raw("({$model->toSql()}) AS t"))
            ->mergeBindings($model)
            ->groupBy('t.member_id')
            ->having('point', '>', 0)
            ->select('t.member_id',
                DB::raw('SUM(t.bet) as point')
            )
            ->get();

        foreach ($result as $row) {
            $process = [
                'member_id' => $row->member_id,
                'detail_id' => $data->id,
                'point' => $row->point,
                'status' => ($row->point >= $data->point) ? 1 : 0
            ];
            $this->addProcess($process);
        }
    }

    private function firstDeposit($data)
    {
        echo '----------- firstDeposit - ' . $data->id . ' ------' . PHP_EOL;
        //如有用到此活動, 以下SQL要確認
        // action id 4 首儲
        $model = DB::table('orders as r')
            ->join('members as m', function ($join) use ($data) {
                $join->on('m.id', 'r.member_id')
                    ->whereBetween(
                        'm.first_deposit',
                        [
                            $data->start_time,
                            $data->end_time
                        ]
                    );
            })
            ->join('agents as a', function ($join) use ($data) {
                $join->on('a.id', 'm.parent');
                if ($data->agent_id > 0) {
                    $join->where('a.hierarchy', 'like', '%,' . $data->agent_id . ',%');
                }
            })
            ->where('r.status', 1);
        if ($data->order_type_id != '' || $data->order_type_id != 0) {
            $model->where('r.order_type_id', $data->order_type_id);
        }
        $result = $model->groupBy('r.member_id')
            ->having('point', '>', 0)
            ->select(
                'r.member_id',
                DB::raw("SUM(r.point) as point")
            )
            ->get();

        foreach ($result as $row) {
            // 看看是否有指定金流商
            $process = [
                'member_id' => $row->id,
                'detail_id' => $data->id,
                'point' => $row->point,
                'status' => ($row->point >= $data->point) ? 1 : 0
            ];
            $this->addProcess($process);
        }
    }

    // 寫入進度
    private function addProcess($data, $action_id = 0)
    {
        echo 'addProcess:' . $data['member_id'] . '/' . $data['detail_id'] . '/' . $data['point'] . PHP_EOL;
        $process = EventProcess::where('member_id', $data['member_id'])
            ->where('detail_id', $data['detail_id'])
            ->first();
        if (is_object($process)) {
            // 無條件捨去
            if ($action_id == 8) {
                $data['point'] = floor($data['point'] * 100) / 100;
            } else {
                $data['point'] = floor($data['point']);
            }
            if ($process->status == 2) {
                $data['status'] = 2;
            }
            $process->update($data);
        } else {
            EventProcess::create($data);
        }
    }
}