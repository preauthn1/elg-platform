<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class InsertAgentMenusUnlimitTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        DB::table('agent_menus')->insert(array(
            'id' => 27,
            'code' => 'unlimit',
            'name' => '無限代',
            'parent' => '0',
            'url' => '',
            'symbol' => 'ti-id-badge',
            'action' => '',
            'sort' => 60,
            'status' => 1,
            'admin' => 1
        ));
        DB::table('agent_menus')->insert(array(
            'code' => 'unlimit_search',
            'name' => '會員查詢',
            'parent' => '27',
            'url' => 'unlimit/member_search',
            'symbol' => '',
            'action' => '',
            'status' => 1,
            'sort' => 10,
            'admin' => 1
        ));
        DB::table('agent_menus')->insert(array(
            'code' => 'unlimit_record',
            'name' => '提現紀錄',
            'parent' => '27',
            'url' => 'unlimit/withdrawal_record',
            'symbol' => '',
            'action' => '',
            'status' => 1,
            'sort' => 20,
            'admin' => 1
        ));
        DB::table('agent_menus')->insert(array(
            'code' => 'unlimit_bind',
            'name' => '綁定上級',
            'parent' => '27',
            'url' => 'unlimit/bind_to_superior',
            'symbol' => '',
            'action' => '',
            'status' => 0,
            'sort' => 30,
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
        Schema::dropIfExists('agent_menus');
    }
}
