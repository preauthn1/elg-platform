<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class InsertAgentMenu0620 extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        DB::table('agent_menus')->insert(array(
            'code' => 'unlimit_performance',
            'name' => '業績查詢',
            'parent' => '27',
            'url' => 'unlimit/performance',
            'symbol' => '',
            'action' => '',
            'status' => 1,
            'sort' => 40,
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
