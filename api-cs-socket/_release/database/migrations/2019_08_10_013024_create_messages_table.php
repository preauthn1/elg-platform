<?php
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateMessagesTable extends Migration
{

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('messages', function (Blueprint $table) {
            $table->increments('id')->comment('流水號');
            $table->integer('room_id')->comment('聊天室系統編號');
            $table->integer('user_id')
                ->nullable()
                ->default(0)
                ->comment('客服系統編號');
            $table->integer('member_id')->comment('會員系統編號');
            $table->longText('type')->comment('類型1:文字，2:圖片，3:紅包，4:聲音，5:影片');
            $table->longText('content')->comment('聊天內容');
            $table->tinyInteger('status')
                ->default(0)
                ->comment('狀態0:準備傳送,1:已傳送,2:傳送失敗');
            $table->timestamp('created_at')
                ->useCurrent()
                ->comment('建立日期');
            $table->timestamp('updated_at')
                ->default(DB::raw('CURRENT_TIMESTAMP on update CURRENT_TIMESTAMP'))
                ->comment('更新日期');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('messages');
    }
}
