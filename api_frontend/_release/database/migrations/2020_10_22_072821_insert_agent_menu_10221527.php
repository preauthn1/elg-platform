<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class InsertAgentMenu10221527 extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        DB::table("agent_menus")
            ->insert(array(
                'code' => "agent_setting_define",
                'name' => '代理設置鍵值定義與管理',
                'parent' => 1,
                'url' => 'agent/advance_setting_defines',
                'symbol' => '',
                'action' => '',
                'status' => 1,
                'sort' => 75,
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
