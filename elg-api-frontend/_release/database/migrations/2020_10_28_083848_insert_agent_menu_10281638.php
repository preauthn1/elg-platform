<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class InsertAgentMenu10281638 extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        $parent = DB::table("agent_menus")
            ->insert(array(
                'code' => "auto_withdrawal",
                'name' => '自助洗分',
                'parent' => 0,
                'url' => '',
                'symbol' => 'ti-credit-card',
                'action' => '',
                'status' => 1,
                'sort' => 80,
                'admin' => 0
            ));
        DB::table('agent_menus')
            ->insert(array(
                'code' => 'withdrawal_bank',
                'name' => '洗分銀行管理',
                'parent' => $parent,
                'url' => 'withdrawal/bank_list',
                'symbol' => ' ',
                'action' => ' ',
                'sort' => 10,
                'status' => 1,
                'admin' => 0
                ));
        DB::table('agent_menus')
            ->insert(array(
                'code' => 'withdrawal_order_review',
                'name' => '洗分訂單審核',
                'parent' => $parent,
                'url' => 'withdrawal/index',
                'symbol' => ' ',
                'action' => ' ',
                'sort' => 20,
                'status' => 1,
                'admin' => 0
            ));
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        DB::table('agent_menus')->where('code', "auto_withdrawal")->delete();
        DB::table('agent_menus')->where('code', "withdrawal_bank")->delete();
        DB::table('agent_menus')->where('code', "withdrawal_order_review")->delete();
    }
}
