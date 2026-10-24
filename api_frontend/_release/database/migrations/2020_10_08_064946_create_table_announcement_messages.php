<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateTableAnnouncementMessages extends Migration
{
    protected $table_name = 'announcement_messages';      


    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {        
        Schema::create($this->table_name, function (Blueprint $table) {            
            $table->increments('id');
            $table->string('title' , 50 )->comment('標題');
            $table->string('message' , 1000 )->comment('訊息內容');
            $table->string('memo' , 100 )->comment('備註');
            $table->integer('organization_id')->comment('所屬組織');
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->integer('created_by')->useCurrent()->comment('建立人');
            $table->timestamp('updated_at')->default(DB::raw('CURRENT_TIMESTAMP on update CURRENT_TIMESTAMP'))->comment('更新日期');
            $table->integer('updated_by')->useCurrent()->comment('修改人');
        });

        DB::statement("ALTER TABLE `$this->table_name` comment '群發公告訊息'");
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