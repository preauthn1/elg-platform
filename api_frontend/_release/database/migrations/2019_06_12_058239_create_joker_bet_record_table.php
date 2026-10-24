<?php
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateJokerBetRecordTable extends Migration
{

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('joker_bet_records', function (Blueprint $table) {
            $table->increments('id')->comment('流水號');
            $table->string('key')
                ->nullable()
                ->comment('遊戲類型');
            $table->string('code')
                ->nullable()
                ->comment('投注編號唯一值');
            $table->string('username')
                ->nullable()
                ->comment('玩家帳號');
            $table->string('game_code')
                ->nullable()
                ->comment('遊戲代碼');
            $table->string('description')
                ->nullable()
                ->comment('詳情');
            $table->string('type')
                ->nullable()
                ->comment('類型');
            $table->decimal('amount', 10, 2)
                ->nullable()
                ->comment('投注金額');
            $table->decimal('result', 10, 2)
                ->nullable()
                ->comment('輸贏金額');
            $table->datetime('bet_time')
                ->nullable()
                ->comment('投注時間');
            $table->string('app_id')
                ->nullable()
                ->comment('APP編號');
            $table->timestamp('created_at')
                ->useCurrent()
                ->comment('建立日期');
            $table->timestamp('updated_at')
                ->default(DB::raw('CURRENT_TIMESTAMP on update CURRENT_TIMESTAMP'))
                ->comment('更新日期');
            $table->integer('admin')->comment('更新者ID');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('haolong_daily_win_losses');
    }
}
