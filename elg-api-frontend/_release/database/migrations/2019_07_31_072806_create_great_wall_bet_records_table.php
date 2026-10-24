<?php
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateGreatWallBetRecordsTable extends Migration
{

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('great_wall_bet_records', function (Blueprint $table) {
            $table->increments('id')->comment('流水號');
            $table->integer('themeId')->comment('前四碼遊戲ID，後四碼大廳ID');
            $table->string('gameNo', 32)
                ->nullable()
                ->comment('遊戲編號');
            $table->string('gameSerialId', 32)
                ->nullable()
                ->comment('遊戲流水號');
            $table->string('accountId', 32)
                ->nullable()
                ->comment('玩家ID');
            $table->string('userName', 32)
                ->nullable()
                ->comment('玩家帳號');
            $table->decimal('valueBefore', 10, 2)
                ->nullable()
                ->comment('投注前金額');
            $table->decimal('valueAfter', 10, 2)
                ->nullable()
                ->comment('投注後金額');
            $table->decimal('betCoin', 10, 2)
                ->nullable()
                ->comment('投注金額');
            $table->decimal('winAmount', 10, 2)
                ->nullable()
                ->comment('贏得金額');
            $table->decimal('availTotalBet', 10, 2)
                ->nullable()
                ->comment('有效投注金額');
            $table->decimal('availTotalWin', 10, 2)
                ->nullable()
                ->comment('有效贏得金額');
            $table->string('remoteIP', 16)
                ->nullable()
                ->comment('玩家IP');
            $table->integer('deviceType')
                ->nullable()
                ->comment('設備編號');
            $table->string('historyCreatedTime', 32)
                ->nullable()
                ->comment('遊戲記錄建立時間');
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
        Schema::dropIfExists('great_wall_bet_records');
    }
}
