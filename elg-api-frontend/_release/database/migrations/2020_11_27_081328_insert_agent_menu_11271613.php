<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class InsertAgentMenu11271613 extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        DB::table("agent_menus")
            ->insert(array(
                'code' => "member_vendor_accounts",
                'name' => '會員遊戲帳號',
                'parent' => 7,
                'url' => 'member/vendor_accounts',
                'symbol' => '',
                'action' => '',
                'status' => 1,
                'sort' => 35,
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
        //
    }
}
