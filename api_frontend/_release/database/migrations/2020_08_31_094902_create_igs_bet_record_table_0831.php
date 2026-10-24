<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateIgsBetRecordTable0831 extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        if (!Schema::hasTable('igs_bet_records')){
            Schema::create('igs_bet_records', function (Blueprint $table) {
                $table->increments('id');
                $table->string('username', 20)->comment('玩家帳號')->index();
                $table->string('user_id', 10)->comment('玩家ID');
                $table->decimal('bet_amount', 18, 4)->comment('投注金額');
                $table->decimal('win_amount', 18, 4)->comment('贏得金額');
                $table->string('game_id')->unique()->comment('局次ID');
                $table->decimal('amount_before', 18, 4)->comment('投注前金额');
                $table->decimal('amount_after', 18, 4)->comment('投注前金额');
                $table->string('game_type', 5)->comment('遊戲代碼');
                $table->dateTime('bet_at')->comment('投注時間');
                $table->dateTime('utc_bet_at')->comment('投注時間 UTC');
                $table->timestamp('created_at')
                    ->useCurrent()
                    ->comment('建立日期');
                $table->timestamp('updated_at')
                    ->default(DB::raw('CURRENT_TIMESTAMP on update CURRENT_TIMESTAMP'))
                    ->comment('更新日期');
            });
        }
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('igs_bet_records');
    }
}
