<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class InsertAgentMenu11091026 extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        $parent = DB::table('agent_menus')->where('code', 'statistics')->first();
        DB::table('agent_menus')
            ->insert(array(
                'code' => 'report_sr08',
                'name' => 'SR08 補點紀錄',
                'parent' => $parent->id,
                'url' => 'report/sr08',
                'symbol' => ' ',
                'action' => ' ',
                'sort' => 55,
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
        DB::table('agent_menus')->where('code', "report_sr08")->delete();
    }
}
