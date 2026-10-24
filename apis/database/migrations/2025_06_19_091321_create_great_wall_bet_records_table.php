<?php

declare(strict_types=1);

use App\Facades\SchemaMonitor;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        SchemaMonitor::create('great_wall_bet_records', function (Blueprint $table) {
            $table->bigIncrements('id')->comment('流水號');
            $table->integer('themeId')->comment('前四碼遊戲ID，後四碼大廳ID');
            $table->string('gameId', 4)->nullable()->virtualAs('left(`themeId`,4)')->index()->comment('遊戲ID, 參照themeId');
            $table->string('gameNo', 32)->nullable()->comment('遊戲編號');
            $table->string('gameSerialId', 32)->nullable()->unique()->comment('遊戲流水號');
            $table->string('accountId', 32)->nullable()->comment('玩家ID');
            $table->string('userName', 32)->nullable()->index()->comment('玩家帳號');
            $table->decimal('valueBefore', 20, 7)->nullable()->default(0)->comment('投注前金額');
            $table->decimal('valueAfter', 20, 7)->nullable()->default(0)->comment('投注後金額');
            $table->decimal('betCoin', 20, 7)->nullable()->default(0)->comment('投注金額');
            $table->decimal('winAmount', 20, 7)->nullable()->default(0)->comment('贏得金額');
            $table->decimal('availTotalBet', 20, 7)->nullable()->default(0)->comment('有效投注金額');
            $table->decimal('availTotalWin', 20, 7)->nullable()->default(0)->comment('有效贏得金額');
            $table->string('remoteIP', 16)->nullable()->comment('玩家IP');
            $table->integer('deviceType')->nullable()->comment('設備編號');
            $table->string('historyCreatedTime', 32)->nullable()->comment('遊戲記錄建立時間');
            $table->dateTime('utc_created_time')->nullable()->index()->comment('遊戲記錄建立UTC時間');
            $table->text('info')->nullable();
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->index()->comment('更新日期');
            $table->integer('admin')->comment('更新者ID');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('great_wall_bet_records');
    }
};
