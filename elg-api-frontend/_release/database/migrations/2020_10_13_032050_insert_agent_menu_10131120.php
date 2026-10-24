<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class InsertAgentMenu10131120 extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {       
        $targetMenuID = 1;

        DB::table("agent_menus")
            ->insert(array(
                'code' => "announcement_message_create",
                'name' => '新增群發訊息管理',
                'parent' => $targetMenuID,
                'url' => '',
                'symbol' => 'tag_invisible',
                'action' => '',
                'status' => 1,
                'sort' => 51,
                'admin' => 0
            ));

        DB::table("agent_menus")
            ->insert(array(
                'code' => "announcement_message_delete",
                'name' => '刪除群發訊息管理',
                'parent' => $targetMenuID,
                'url' => '',
                'symbol' => 'tag_invisible',
                'action' => '',
                'status' => 1,
                'sort' => 52,
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
