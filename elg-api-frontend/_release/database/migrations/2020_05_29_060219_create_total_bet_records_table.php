<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateTotalBetRecordsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
    	$table_name = 'total_bet_records';
        Schema::create($table_name, function (Blueprint $table) {
            $table->increments('id')->comment('流水號');
            $table->datetime('summary_time')->comment('時間');
            $table->integer('member_id')->comment('會員系統編號');
            $table->integer('vendor_id')->comment('遊戲商系統編號');
            $table->integer('game_id')->comment('遊戲系統編號');
            $table->string('reference_no', 100)->comment('來源單號');
            $table->decimal('bet', 18, 4)->default(0.0000)->comment('投注金額');
            $table->decimal('valid_bet', 18, 4)->default(0.0000)->comment('有效投注金額');
            $table->decimal('win_lose', 18, 4)->default(0.0000)->comment('輸贏金額');
            $table->datetime('bet_time')->comment('投注時間(UTC)');
            $table->datetime('payout_time')->comment('派彩、結算時間(UTC)');
            $table->datetime('record_time')->comment('xxx_bet_record.created_at');
            $table->datetime('created_at')->default(DB::raw('CURRENT_TIMESTAMP'))->comment('建立日期');
            $table->datetime('updated_at')->default(DB::raw('CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP'))->comment('更新日期');
            $table->integer('admin')->comment('更新者ID');
            
            //index
            $table->index('member_id');
            $table->index('summary_time');
            $table->index('vendor_id');
            $table->index('game_id');
        });
        DB::statement("ALTER TABLE `$table_name` comment '存放所有遊戲商的投注記錄'");
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('total_bet_records');
    }
}
