<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateSummaryBetRecordsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('summary_bet_records', function (Blueprint $table) {
            $table->increments('id')->comment('流水號');
            $table->dateTime('summary_time')->comment('時間');
            $table->integer('member_id')->comment('會員系統編號');
            $table->integer('vendor_id')->comment('遊戲商系統編號');
            $table->string('game_id')->comment('遊戲系統編號');
            $table->decimal('bet')->comment('投注金額');
            $table->decimal('win_lose')->comment('贏得金額');
            $table->decimal('percentage')->comment('分成百分比');
            $table->decimal('share_amount')->comment('分成金額');
            $table->string('share_range')->comment('分成區間');
            $table->timestamp('created_at')
                ->useCurrent()
                ->comment('建立日期');
            $table->timestamp('updated_at')
                ->default(DB::raw('CURRENT_TIMESTAMP on update CURRENT_TIMESTAMP'))
                ->comment('更新日期');
            $table->integer('admin')
                ->default(0)
                ->comment('更新者ID');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('summary_bet_records');
    }
}