<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class InsertAgentMenu11041705 extends Migration
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
                'code' => "member_vendor_account_summary",
                'name' => '會員遊戲帳號總覽',
                'parent' => 7,
                'url' => 'member/vendor_account_summary',
                'symbol' => '',
                'action' => '',
                'status' => 1,
                'sort' => 10,
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
