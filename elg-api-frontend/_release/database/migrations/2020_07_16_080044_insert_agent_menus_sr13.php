<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class InsertAgentMenusSr13 extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        if (!DB::table('agent_menus')->where("code", "sr13")->first()) {
            DB::table('agent_menus')
                ->insert(array(
                    'code' => 'report_sr13',
                    'name' => 'SR13 代理分數紀錄',
                    'parent' => '11',
                    'url' => 'report/sr13',
                    'symbol' => '',
                    'action' => '',
                    'status' => 1,
                    'sort' => 130,
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