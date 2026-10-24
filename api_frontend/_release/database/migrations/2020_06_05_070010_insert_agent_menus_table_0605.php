<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class InsertAgentMenusTable0605 extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        DB::table('agent_menus')->insert(array(
            'id' => 31,
            'code' => 'report_sr07',
            'name' => 'sr07紅包明細表',
            'parent' => '11',
            'url' => 'report/sr07',
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
        DB::table('agent_menus')->where('id' , '31')->delete();
    }
}
