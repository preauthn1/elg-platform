<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateAgentAnnouncementsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('agent_announcements', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('agent_id')->comment('代理id');
            $table->integer('announcement_id')->comment('公告id');
            $table->tinyinteger('status')->default(0)->commit('狀態');
            $table->timestamps();
            $table->integer('admin')->comment('更新者ID');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('aget_announcements');
    }
}
