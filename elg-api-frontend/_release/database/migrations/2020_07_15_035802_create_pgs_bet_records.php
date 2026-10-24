<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreatePgsBetRecords extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('pgs_bet_records', function (Blueprint $table) {
            $table->increments('id');
            $table->string('username')->comment('玩家帳號')->index();
            $table->integer('gameID')->default(0)->comment('遊戲代號');
            $table->decimal('beforeAmount', 18, 4)->comment('押注前分數');
            $table->decimal('betAmount', 18, 4)->comment('押注分數');
            $table->decimal('payoutAmount', 18, 4)->comment('派彩分數');
            $table->decimal('winLossAmount', 18, 4)->comment('輸贏分數');
            $table->decimal('validAmount', 18, 4)->comment('有效流水');
            $table->integer('roundID')->comment('進房代號');
            $table->bigInteger('betID')->comment('注單代號')->unique();
            $table->timestamp('betTime')->default(0)->comment('押注時間 UTC')->index();
            $table->timestamp('roundStartTime')->default(0)->comment('進房時間');
            $table->timestamp('roundEndTime')->default(0)->comment('退房時間');
            $table->timestamp('created_at')
                ->useCurrent()
                ->comment('建立日期');
            $table->timestamp('updated_at')
                ->default(DB::raw('CURRENT_TIMESTAMP on update CURRENT_TIMESTAMP'))
                ->comment('更新日期')->index();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('pgs_bet_records');
    }
}
