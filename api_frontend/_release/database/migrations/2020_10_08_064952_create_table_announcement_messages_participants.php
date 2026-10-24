<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateTableAnnouncementMessagesParticipants extends Migration
{ 
    
    protected $table_name = 'announcement_messages_participants';      


    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {        
        Schema::create($this->table_name, function (Blueprint $table) {            
            $table->increments('id');
            $table->integer('announcement_message_id')->comment('FK對應 announcement_message');
            $table->integer('type')->comment('類型 1: 針對chat_group  2. 針對代理樹狀  3. 針對單一代理  4. 針對特定Member  99. 針對組織 ');
            $table->integer('chat_group_id')->nullable()->comment('發送給特定的 chat_group');
            $table->integer('hierarchy_agent_id')->nullable()->comment('發送給特定的代理樹狀所有人');
            $table->integer('single_agent_id')->nullable()->comment('發送給特定的代理');
            $table->integer('member_id')->nullable()->comment('發送給特定的會員');
            $table->integer('organization_id')->nullable()->comment('發送給特定的組織');            
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->timestamp('updated_at')->default(DB::raw('CURRENT_TIMESTAMP on update CURRENT_TIMESTAMP'))->comment('更新日期');
        });

        DB::statement("ALTER TABLE `$this->table_name` comment '群發公告訊息哪些人可以看到'");
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