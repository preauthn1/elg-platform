<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateSboBetRecordsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('sbo_bet_records', function (Blueprint $table) {
            $table->increments('id')->comment('系統編號');
            $table->string('player_name')->comment('玩家名稱');
            $table->string('vendor_code')->comment('產品代碼');
            $table->string('game_code')->comment('遊戲代碼');
            $table->string('parent_bet_id')->comment('父注编號');
            $table->string('bet_id')->comment('下注編號');
            $table->string('trans_type')->comment('Stake(下注或取消本注)');
            $table->string('currency')->comment('幣別');
            $table->string('wallet_code')->comment('錢包代碼');
            $table->decimal('bet_amount', 13, 7)->comment('下注金額');
            $table->decimal('win_amount', 13, 7)->nullable()->comment('派彩或退回金額');
            $table->string('traceId')->nullable()->comment('交易編號');
            $table->string('create_time')->nullable()->comment('投注時間');
            $table->timestamp('created_at')
                ->useCurrent()
                ->comment('建立日期');
            $table->timestamp('updated_at')
                ->default(DB::raw('CURRENT_TIMESTAMP on update CURRENT_TIMESTAMP'))
                ->comment('更新日期');
        });
    }
}
