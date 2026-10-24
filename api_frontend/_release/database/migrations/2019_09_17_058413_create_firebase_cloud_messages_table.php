<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateFirebaseCloudMessagesTable extends Migration
{

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('firebase_cloud_messages', function (Blueprint $table) {
            $table->increments('id')->comment('流水號');
            $table->integer('member_id')->comment('會員系統編號');
            $table->string('title')->comment('標題');
            $table->string('content')->comment('內容');
            $table->string('url')->comment('click action網址');
            $table->string('multicast_id')->nullable()->comment('multicast_id');
            $table->string('message_id')->nullable()->comment('message_id');
            $table->string('error')->nullable()->comment('錯誤訊息');
            $table->boolean('status')->default(0)->comment('狀態0:未發送,1:發送成功,2:發送失敗');
            $table->timestamp('created_at')
                ->useCurrent()
                ->comment('建立日期');
            $table->timestamp('updated_at')
                ->default(DB::raw('CURRENT_TIMESTAMP on update CURRENT_TIMESTAMP'))
                ->comment('更新日期');
        });
    }
}