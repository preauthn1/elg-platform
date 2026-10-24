<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class InsertAgentMenu0611 extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        DB::table('agent_menus')->insert(array(
            'code' => 'unlimit_agent_search',
            'name' => '原代理業績',
            'parent' => '27',
            'url' => 'unlimit/agent_search',
            'symbol' => '',
            'action' => '',
            'status' => 1,
            'admin' => 1
        ));
        DB::table('agent_menus')->insert(array(
            'code' => 'unlimit_sr02',
            'name' => '原代理獎勵紀錄',
            'parent' => '27',
            'url' => 'unlimit/sr02',
            'symbol' => '',
            'action' => '',
            'status' => 1,
            'admin' => 1
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
