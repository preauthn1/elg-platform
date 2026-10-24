<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class InsertAgentMenus extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        if (!DB::table('agent_menus')->where("code", "vendor_maintenances")->first()) {
            DB::table('agent_menus')
                ->insert(array(
                    'code' => 'vendor_maintenances',
                    'name' => '遊戲商維護',
                    'parent' => '1',
                    'url' => 'vendor_maintenances/index',
                    'symbol' => '',
                    'action' => '',
                    'sort' => 100,
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