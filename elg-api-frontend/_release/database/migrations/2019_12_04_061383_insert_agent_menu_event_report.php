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
            'status' => 0,
            'admin' => 1
        ));
    }
}