<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateEsportBetRecordsTable extends Migration
{
    protected $table_name = 'esport_bet_records';

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
                $table->string('bet_id', 50)->default(0)->comment('注单唯一Id, ThemeId')->unique();
                $table->string('username', 30)->comment('玩家帳號');
                $table->string('game_code')->comment('游戏ID');
                $table->datetime('bet_time')->comment('派彩時間(utc)');
                $table->datetime('created_time')->comment('押注時間(utc)');
                $table->integer('bet_status')->coment('投注狀態');

                $table->decimal('bet', 18, 4)->default(0)->comment('下注金额; BetCoin');
                $table->decimal('win_lose', 18, 4)->default(0)->comment('輸贏金额; WinAmount');

                $table->string('info',500)->comment('response data');

                $table->timestamp('created_at')
                    ->useCurrent()
                    ->comment('建立日期');
                $table->timestamp('updated_at')
                    ->default(DB::raw('CURRENT_TIMESTAMP on update CURRENT_TIMESTAMP'))
                    ->comment('更新日期')
                    ->index();
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
