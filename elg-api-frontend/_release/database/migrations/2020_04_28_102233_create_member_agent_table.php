<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateMemberAgentTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('member_agent', function (Blueprint $table) {
            $table->increments('id')->comment('流水號');
            $table->integer('member_id')
                ->unsigned()
                ->nullable()
                ->comment('玩家編號');
            $table->integer('parent_id')
                ->unsigned()
                ->nullable()
                ->comment('上層代理系統編號(User)');
            $table->integer('topper_member_id')
                ->unsigned()
                ->nullable()
                ->comment('上層玩家編號。紀錄上層玩家編號，如果該欄位為null代表為最上層');

            $table->tinyInteger('agent_mode')
                ->nullable()
                ->comment('代理模式類型。 1:無限代、2:淨利代....');
            $table->tinyInteger('agent_level')
                ->unsigned()
                ->nullable()
                ->comment('會員層級');
            $table->string('agent_relation', 140)
                ->nullable()
                ->comment('代理鏈結，格式:id_id_id(包含自己)');
        });
        
        Schema::table('member_agent', function (Blueprint $table) {
            //index
            $table->index('member_id');
            $table->index('parent_id');
            $table->index('topper_member_id');

            //外鍵約束
            //$table->foreign('member_id')
            //    ->references('id')
            //    ->on('members');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('member_agent', function (Blueprint $table) {
            //drop index
            $table->dropIndex('member_id');
            $table->dropIndex('parent_id');
            $table->dropIndex('topper_member_id');

            //移除外鍵
            //$table->dropForeign('member_agent_member_id_foreign');
        });
        Schema::dropIfExists('member_agent');
    }
}
