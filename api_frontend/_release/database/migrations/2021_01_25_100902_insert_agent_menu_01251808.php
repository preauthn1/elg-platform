<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class InsertAgentMenu01251808 extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        $query_parent_id = DB::table("agent_menus")->where("code" , "member")->first()->id;

        DB::table("agent_menus")
            ->insert(array(
                'code' => "member_list_flow_limit",
                'name' => '查看稽核流水',
                'parent' => $query_parent_id ,
                'url' => '',
                'symbol' => 'tag_invisible',
                'action' => '',
                'status' => 1,
                'sort' => -999,
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