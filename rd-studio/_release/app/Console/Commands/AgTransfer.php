<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Member;
use App\Models\AgAccount;
use App\Models\TransactionLog;
use DB;

class AgTransfer extends Command
{

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'ag_transfer';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'ag代理商轉移';

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
        //查詢有餘額的會員資料
        $members = AgAccount::select('username', 'balance', 'member_id')
            ->where('balance', '>', 0)
            ->where('member_id', '>', 0)
            ->get();

        foreach ($members as $row) {
            echo "********* $row->username ----( $row->member_id )---- $row->balance ******";
            if ($row->member_id > 0) {
                //更新AG會員表
                $result = AgAccount::where('member_id', $row->member_id)
                    ->update(['member_id' => DB::raw("CONCAT('-',member_id)")]);
                //更新平台會員表
                $member = Member::find($row->member_id);
                $before_balance = $member->balance;
                if (is_object($member)) {
                    $result = $member->increment('balance', $row->balance);
                    if ($result) {
                        $member = Member::find($row->member_id);
                        //新增轉點記錄，將AG餘額轉回平台
                        $log = [
                            'transaction_no' => date('U'),
                            'agent_id' => $member->parent,
                            'member_id' => $member->id,
                            'vendor_id' => 1,
                            'type' => 2,
                            'rate' => 1,
                            'vendor_point' => $row->balance,
                            'vendor_balance' => 0,
                            'point' => $row->balance,
                            'before_balance' => $before_balance,
                            'balance' => $member->balance,
                            'status' => 1000,
                            'remark' => '系統轉移AG餘額',
                            'operator' => 'system',
                        ];
                        TransactionLog::create($log);
                        echo '成功';
                    } else {
                        echo '點數新增失敗';
                    }
                } else {
                    echo '帳號不存在';
                }
            } else {
                echo '帳號錯誤';
            }
        }
    }
}