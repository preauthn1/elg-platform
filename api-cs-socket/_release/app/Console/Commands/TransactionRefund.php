<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Helpers\Common;
use App\Transaction;
use App\ThirdParty;
use DB;

class TransactionRefund extends Command
{

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'transaction_refund';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '將未確認洗分的記錄退回';

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
        Common::cron_log(0, 'start-' . date('Y-m-d H:i:s'), 'transaction_refund');
        echo 'start-' . date('Y-m-d H:i:s');
        //查詢10分鐘尚未回應的洗分記錄
        $transaction = Transaction::leftJoin('members as m', 'm.id', 'transactions.member_id')
            ->where('transactions.status', 4)->where('transactions.created_at', '<', date('Y-m-d H:i:s', strtotime('-10 minutes')))
            ->select('transactions.id', 'm.user_id', 'transactions.member_id', 'transactions.amount')
            ->get();
        foreach ($transaction as $row) {
            //更新狀態
            $update = Transaction::where('id', $row->id)->update(['status' => 3]);
            if ($update) {
                //退還預扣的金額
                $third_party = new ThirdParty($row->user_id);
                $result = $third_party->transaction($row->member_id, 1, $row->amount, 12, 'system refund' . $row->id, 0, 0);
                if ($result) {
                    //轉點成功
                    $msg = 'success';
                    //更新用戶餘額
                    $member = $third_party->getInfo($row->member_id);
                    Transaction::where('id', $row->id)->update(['member_balance' => $member['balance']]);
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
        Common::cron_log(0, 'total row-' . count($transaction), 'transaction_refund');
        Common::cron_log(0, 'end-' . date('Y-m-d H:i:s'), 'transaction_refund');
        echo 'end-' . date('Y-m-d H:i:s');
    }
}