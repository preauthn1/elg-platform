<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateObet33BetRecordsTable extends Migration
{
    protected $table_name = 'obet33_bet_records';
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
                $table->string('bet_id')->default(0)->comment('注单唯一Id, ThemeId')->unique();
                $table->string('username', 30)->comment('玩家帳號');
                $table->string('game_code')->comment('游戏ID');
                $table->datetime('SDate')->comment('算帳時間');
                $table->datetime('bet_time')->comment('派彩時間(utc)');
                $table->datetime('created_time')->comment('押注時間(utc)');

                $table->integer('bet_status')
                    ->coment('投注狀態 -1.未接受, 0.接受, 1.拒絕, 4.取消, 5.退款');
                $table->integer('ticket_result')
                    ->coment('注單結果(未派彩,取消,退款時會是NULL) 0.贏, 1.輸, 2.和, 3.贏半, 4.輸半')
                    ->nullable();

                $table->decimal('bet', 18, 4)->default(0)->comment('下注金额');
                $table->decimal('win_lose', 18, 4)->default(0)->comment('輸贏金额');

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
