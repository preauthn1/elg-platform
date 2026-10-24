<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateRedEnvelopesTable extends Migration
{

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('red_envelopes', function (Blueprint $table) {
            $table->increments('id')->comment('流水號');
            $table->integer('room_id')->comment('聊天室系統編號');
            $table->integer('member_id')->comment('發送者會員系統編號');
            $table->integer('type')->comment('類型1:個人紅包，2:群組普通紅包，3:隨機紅包');
            $table->integer('amount')->comment('金額');
            $table->integer('number')->comment('數量');
            $table->text('remark')
                ->nullable()
                ->comment('備註');
            $table->boolean('status')
                ->default(0)
                ->comment('狀態0:發送失敗,1:發送成功,2:已領取');
            $table->text('third_party_result')
                ->nullable()
                ->comment('第三方回應結果');
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
        Schema::dropIfExists('red_envelopes');
    }
}