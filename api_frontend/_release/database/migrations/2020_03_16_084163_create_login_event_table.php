<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateLoginEventTable extends Migration
{

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('login_events', function (Blueprint $table) {
            $table->increments('id')->comment('流水號');
            $table->integer('agent_id')->default(0)->comment('代理商編號');
            $table->integer('type')->comment('可參與活動的條件，1:活動開始後創立之新帳號、2:舊帳號, 且幾日內無開洗分紀錄');
            $table->integer('how_days')->comment('幾日內無開洗分紀錄');
            $table->integer('point')->comment('參與活動贈送的點數');
            $table->integer('times')->comment('解鎖條件：達到贈送點數的幾倍');
            $table->integer('number_of_people')->comment('可參與的人數(0代表無上限)');
            $table->integer('number_of_participants')->comment('已參與的人數');
            $table->dateTime('start_time')->comment('開始時間');
            $table->dateTime('end_time')->comment('結束時間');
            $table->integer('repeat_hour')->comment('重複參與時間間隔(最少1小時，0代表不可重複參加)');
            $table->boolean('status')->comment('狀態，0:停用、1:正常');
            $table->timestamp('created_at')
                ->useCurrent()
                ->comment('建立日期');
            $table->timestamp('updated_at')
                ->default(DB::raw('CURRENT_TIMESTAMP on update CURRENT_TIMESTAMP'))
                ->comment('更新日期');
        });
    }
}