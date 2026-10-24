<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class InsertAgentMenu10281638 extends Migration
{
    protected $menus = array(
        array(
            'code' => 'auto_withdrawal',
            'name' => '自助洗分',
            'parent' => '0',  //直接用parent的code
            'url' => '',
            'symbol' => 'ti-credit-card',
            'action' => '',
            'sort' => 80,
            'status' => 1,
            'admin' => 0
        ),
        array(
            'code' => 'withdrawal_bank',
            'name' => '洗分銀行管理',
            'parent' => 'auto_withdrawal',
            'url' => 'withdrawal/bank_list',
            'symbol' => '',
            'action' => '',
            'sort' => 10,
            'status' => 1,
            'admin' => 0
        ),
        array(
            'code' => 'withdrawal_order_review',
            'name' => '洗分訂單審核',
            'parent' => 'auto_withdrawal',
            'url' => 'withdrawal/index',
            'symbol' => '',
            'action' => '',
            'sort' => 20,
            'status' => 1,
            'admin' => 0
        ),
        array(
            'code' => 'sr16_manual_draw',
            'name' => '抽獎券手動開獎',
            'parent' => 'report_sr16',
            'url' => '',
            'symbol' => '',
            'action' => '',
            'sort' => -999,
            'status' => 1,
            'admin' => 0
        )
    );
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        foreach ($this->menus as $menu) {
            DB::insert("call ADMIN_insert_agent_menus(" .
                "'" . $menu['code'] . "', " .
                "'" . $menu['name'] . "', " .
                "'" . $menu['parent'] . "', " .
                "'" . $menu['url'] . "', " .
                "'" . $menu['symbol'] . "', " .
                "'" . $menu['action'] . "', " .
                "" . $menu['status'] . ", " .
                "" . $menu['sort'] . ", " .
                "" . $menu['admin'] .
                ")");
        }
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
