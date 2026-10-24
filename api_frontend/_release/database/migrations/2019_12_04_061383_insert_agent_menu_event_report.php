<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class InsertAgentMenuEventReport extends Migration
{

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        DB::table('agent_menus')->insert(array(
            'id' => 25,
            'code' => 'event_report',
            'name' => '活動獎勵領取記錄報表',
            'parent' => 17,
            'url' => 'event/prize_report',
            'symbol' => '',
            'action' => '',
            'status' => 1,
            'admin' => 1
        ));
        DB::table('agent_menus')->insert(array(
            'id' => 26,
            'code' => 'login_event_list',
            'name' => '登入活動管理',
            'parent' => 17,
            'url' => 'loginevent/index',
            'symbol' => '',
            'action' => '',
            'status' => 1,
            'sort' => 0,
            'admin' => 1
        ));
    }
}