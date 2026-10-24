<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class InsertAgentMenu10151720 extends Migration
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
                'code' => "auto_deposit",
                'name' => '自助上分',
                'parent' => 0,
                'url' => '',
                'symbol' => 'ti-money',
                'action' => '',
                'status' => 1,
                'sort' => 70,
                'admin' => 0
            ));
        DB::table('agent_menus')->where('code', 'bank')->update(['parent' => '51']);
        DB::table('agent_menus')->where('code', 'order_review')->update(['parent' => '51']);
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
