<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class InsertAgentMenusEventTable extends Migration
{

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        DB::table('agent_menus')->insert(array(
            'id' => 17,
            'code' => 'event',
            'name' => '專屬優惠管理',
            'parent' => '0',
            'url' => '',
            'symbol' => 'ti-announcement',
            'action' => '',
            'status' => 1,
            'sort' => 50,
            'admin' => 0
        ));
        DB::table('agent_menus')->insert(array(
            'id' => 18,
            'code' => 'event_list',
            'name' => '活動管理',
            'parent' => '17',
            'url' => 'event/index',
            'symbol' => '',
            'action' => '',
            'status' => 1,
            'admin' => 1
        ));
    }
}