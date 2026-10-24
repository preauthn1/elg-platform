<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class InsertAgentMenu12151746 extends Migration
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
                'code' => "deposit_vendor_management",
                'name' => '支付渠道管理',
                'parent' => 1,
                'url' => 'deposit/index',
                'symbol' => ' ',
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
        DB::table('agent_menus')->where('code', "deposit_vendor_management")->delete();
    }
}
