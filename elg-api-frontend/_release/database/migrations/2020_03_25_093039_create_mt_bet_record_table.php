<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateMtBetRecordTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('mt_bet_records', function (Blueprint $table) {
            $table->increments('id')->comment('系統編號');
            $table->string('rowID')->comment('遊戲紀錄詳情ID');
            $table->string('playerName')->comment('商戶用戶名稱');
            $table->string('gameDate')->comment('遊戲時間');
            $table->string('gameCode')->comment('遊戲代碼');
            $table->string('gameType')->comment('遊戲類型');         
            $table->decimal('betAmount', 13, 7)->comment('下注金額');
            $table->decimal('winAmount', 13, 7)->nullable()->comment('贏得金額');
            $table->decimal('commissionable', 13, 7)->comment('有效投注量');
            $table->decimal('roomFee', 13, 7)->comment('房費');
            $table->decimal('income', 13, 7)->comment('赢得金額-下注金額-房費');
            $table->decimal('progressive_wins', 13, 7)->nullable()->comment('貢獻彩金');
            $table->decimal('progressive_share', 13, 7)->nullable()->comment('獲得彩金');
            $table->integer('merchantId')->default(0)->comment('商戶ID');
            $table->string('utc_gameDate')->nullable()->comment('遊戲時間(UTC+0)');
            $table->timestamp('created_at')
                ->useCurrent()
                ->comment('建立日期');
            $table->timestamp('updated_at')
                ->default(DB::raw('CURRENT_TIMESTAMP on update CURRENT_TIMESTAMP'))
                ->comment('更新日期');
        });
    }
}
