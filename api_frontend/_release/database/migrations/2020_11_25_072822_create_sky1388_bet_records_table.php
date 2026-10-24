<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateSky1388BetRecordsTable extends Migration
{
    protected $table_name = 'sky1388_bet_records';

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        if (!Schema::hasTable($this->table_name)) {
            Schema::create($this->table_name, function (Blueprint $table) {
                $table->increments('id');
                $table->bigInteger('bet_id')->default(0)->comment('注单唯一Id')->unique();
                $table->string('username')->comment('玩家帳號')->index();
                $table->string('game_code')->comment('游戏桌号');
                $table->datetime('bet_time')->comment('押注時間(utc)');

                $table->decimal('bet', 18, 4)->comment('下注金额');
                $table->decimal('win', 18, 4)->comment('贏得金额');
                $table->decimal('refund', 18, 4)->comment('退還金额');
                $table->decimal('win_lose', 18, 4)->comment('輸贏金额');
                $table->decimal('jackpot', 18, 4)->comment('jackpot');

                $table->string('info',500)->comment('response data');

                $table->timestamp('created_at')
                    ->useCurrent()
                    ->comment('建立日期');
                $table->timestamp('updated_at')
                    ->default(DB::raw('CURRENT_TIMESTAMP on update CURRENT_TIMESTAMP'))
                    ->comment('更新日期')->index();
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
        Schema::dropIfExists($this->table_name);
    }
}
