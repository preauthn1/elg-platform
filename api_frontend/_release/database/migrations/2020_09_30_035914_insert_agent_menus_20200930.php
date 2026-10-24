<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class InsertAgentMenus20200930 extends Migration
{ 
    protected $table_name = 'agent_menus';      

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        DB::table($this->table_name)
        ->insert(array(
            'code' => 'agent_edit_mobile_validate',
            'name' => '允許編輯手機驗證開關',
            'parent' => 4,
            'url' => '',
            'symbol' => '',
            'action' => '',
            'sort' => -999,
            'status' => 1,
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