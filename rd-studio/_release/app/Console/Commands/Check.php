<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Helpers\Common;
use Illuminate\Support\Facades\Storage;
use DB;

class Check extends Command
{

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'check_hole';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '檢查金流是否有什麼問題';

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

        $start_time = '2019-10-01 04:00:00';
        $end_time =  '2020-10-31 00:00:00';

        // 先取點數有異動記錄的帳號
        $data = DB::table('transaction_logs as l')
            ->leftJoin('members as m', 'm.id', 'l.member_id')
            ->leftJoin('agents as a', 'a.id', 'm.parent')
            // ->where('a.hierarchy', 'like', '%,33893,%')
            ->whereBetween('l.created_at', [$start_time, $end_time])
            ->where('l.status', 1000)
            ->whereIn('l.type', [1, 2])
            ->select('m.id as member_id', DB::raw('GROUP_CONCAT(DISTINCT l.vendor_id ORDER BY l.vendor_id) as vendor_ids'))
            ->groupBy('m.id')
            ->offset(0)->limit(10)
            ->get();

        // 輸𦝠記錄只有整天的遊戲商
        $vendor_array = [3, 4, 10];
        foreach ($data as $row) {
            $vendors = explode(',', $row->vendor_ids);
            foreach ($vendors as $vendor_id) {
                // 查詢各遊戲商的起始值
                $start = DB::table('transaction_logs')
                    ->where('member_id', $row->member_id)
                    ->where('vendor_id', $vendor_id)
                    ->whereIn('type', [1, 2])
                    ->where('status', 1000)
                    ->whereBetween('created_at',  [$start_time, $end_time])
                    ->select('point', 'type', 'vendor_balance', 'created_at')
                    ->orderBy('created_at', 'ASC')
                    ->first();

                // 查詢各遊戲商的結尾
                $end = DB::table('transaction_logs')
                    ->where('member_id', $row->member_id)
                    ->where('vendor_id', $vendor_id)
                    ->whereIn('type', [1, 2])
                    ->where('status', 1000)
                    ->whereBetween('created_at',  [$start_time, $end_time])
                    ->select('point', 'type', 'vendor_balance', 'created_at')
                    ->orderBy('created_at', 'DESC')
                    ->first();

                //如果起始跟結尾一樣的話代表無法驗證，因為只有一筆記錄
                if (is_object($start) && is_object($end) && $start->created_at != $end->created_at) {
                    // 查詢起始跟結尾期間的輸贏金額
                    $model = DB::table('summary_bet_records')
                        ->where('member_id', $row->member_id)
                        ->where('vendor_id', $vendor_id);
                    if (in_array($vendor_id, $vendor_array)) {
                        //記錄只有整天的遊戲商，就取整天
                        $model->whereBetween('summary_time', [date('Y-m-d 00:00:00', strtotime($start->created_at)), date('Y-m-d 00:00:00', strtotime($end->created_at))]);
                    } else {

                        $model->whereBetween('summary_time', [date('Y-m-d H:00:00', strtotime($start->created_at)), date('Y-m-d H:00:00', strtotime($end->created_at))]);
                    }

                    $bet = $model->select(DB::raw('SUM(win_lose) as win_lose'))
                        ->first();

                    // 查詢這段期間轉入轉出的金額
                    $transation = DB::table('transaction_logs')
                        ->where('member_id', $row->member_id)
                        ->where('vendor_id', $vendor_id)
                        ->whereIn('type', [1, 2])
                        ->where('status', 1000)
                        ->whereBetween('created_at', [$start->created_at, $end->created_at])
                        ->select(DB::raw('SUM(CASE WHEN type=1 THEN point ELSE 0 END) as total_in'), DB::raw('SUM(CASE WHEN type=2 THEN point ELSE 0 END) as total_out'))
                        ->first();

                    // 驗證期間的轉點金額跟輸贏是否正確
                    $start_balance =  $start->vendor_balance;
                    if ($end->type == 1) {
                        //如果是轉入，就把剛轉入的金額扣掉
                        $end_balance =  $end->vendor_balance - $end->point;
                    } else {
                        $end_balance =  $end->vendor_balance;
                    }
                    $win_lose = number_format($bet->win_lose, 2);
                    $in = $transation->total_in;
                    $out = $transation->total_out;
                    $diff = $in - $out + $win_lose;
                    if ($diff < 0) {
                        // echo '<span style="color:red">';
                    }
                    echo '【 玩家編號：' . $row->member_id . ' 】';
                    echo '【 遊戲商編號：' . $vendor_id . ' 】';
                    echo $start->created_at . ' to ' . $end->created_at;
                    echo '【起始餘額：' . $start_balance . '】';
                    echo '【最後餘額：' . $end_balance . '】';
                    echo '【輸贏：' . $win_lose . '】 【轉入：' . $in . '】 【轉出：' . $out . '】';
                    echo '【差額：' . $diff . '】';
                    echo '</br>';
                    if ($diff < 0) {
                        // echo '</span>';
                    }
                }
            }

            // 驗證開洗分數字、遊戲勝分(統計區間內的)、目前餘額(平台拿目前的，遊戲商拿轉點進去最後一筆的)是否對照的起來

        }
    }
}