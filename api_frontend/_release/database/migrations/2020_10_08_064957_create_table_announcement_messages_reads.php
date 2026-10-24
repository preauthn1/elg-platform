<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateTableAnnouncementMessagesReads extends Migration
{
    protected $table_name = 'announcement_messages_reads';      

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
            $table->integer('member_id')->comment('會員id');
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->timestamp('updated_at')->default(DB::raw('CURRENT_TIMESTAMP on update CURRENT_TIMESTAMP'))->comment('更新日期');
        });

        DB::statement("ALTER TABLE `$this->table_name` comment '紀錄某一則訊息是否已讀'");          
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