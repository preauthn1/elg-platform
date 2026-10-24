<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class InsertAgentMenus0707 extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        if (!DB::table('agent_menus')->where("code", "agent_vendor_force_create")->first()) {
            DB::table('agent_menus')
                ->insert(array(
                    'code' => 'agent_vendor_force_create',
                    'name' => '強制新增代理遊戲商',
                    'parent' => '1',
                    'url' => '',
                    'symbol' => '',
                    'action' => '',
                    'sort' => -999,
                    'status' => 1,
                    'admin' => 0
                ));
        }
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
