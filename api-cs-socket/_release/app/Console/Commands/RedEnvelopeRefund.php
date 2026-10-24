<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Helpers\Common;
use App\RedEnvelope;
use App\ThirdParty;
use DB;

class RedEnvelopeRefund extends Command
{

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'red_envelope_refund';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '回收過期紅包';

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
        Common::cron_log(0, 'start-' . date('Y-m-d H:i:s'), 'red_envelope_refund');
        echo 'start-' . date('Y-m-d H:i:s');
        //查詢已過期又還沒被領完的紅包
        $messages = RedEnvelope::leftJoin('red_envelope_receivers as r', function ($join) {
            $join->on('r.red_envelope_id', '=', 'red_envelopes.id')
                ->where('r.status', 1);
        })
            ->leftJoin('members as m', 'm.id', 'red_envelopes.member_id')
            ->where('red_envelopes.status', 1)->where('red_envelopes.cancel_time', '<', date('Y-m-d H:i:s'))
            ->select('red_envelopes.id', 'm.user_id', 'red_envelopes.member_id', 'red_envelopes.amount', DB::raw('SUM(r.amount) as minus'))
            ->groupBy('red_envelopes.id')
            ->get();
        foreach ($messages as $row) {
            //更新狀態
            $update = RedEnvelope::where('id', $row->id)->update(['status' => 4]);
            if ($update) {
                //退還尚未領取完畢的紅包差額
                $amount = ($row->amount - $row->minus);
                $third_party = new ThirdParty($row->user_id);
                $result = $third_party->transaction($row->member_id, 1, $amount, 9, 'system refund' . $row->id, 0, 0);
                if ($result) {
                    //轉點成功
                    $msg = 'success';
                } else {
                    //轉點失敗
                    $msg = 'transation fail';
                }
            } else {
                $msg = 'update fail';
            }
            echo $msg;
            Common::cron_log(0, $msg . $row->id, 'transaction_refund');
        }
        Common::cron_log(0, 'total row-' . count($messages), 'red_envelope_refund');
        Common::cron_log(0, 'end-' . date('Y-m-d H:i:s'), 'red_envelope_refund');
        echo 'end-' . date('Y-m-d H:i:s');
    }
}