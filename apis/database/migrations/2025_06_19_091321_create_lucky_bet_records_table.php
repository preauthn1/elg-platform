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
        SchemaMonitor::create('lucky_bet_records', function (Blueprint $table) {
            $table->increments('id')->comment('流水號');
            $table->bigInteger('round_id')->nullable()->comment('注單ID');
            $table->integer('userId')->nullable()->index()->comment('玩家ID');
            $table->string('account', 50)->nullable()->index()->comment('玩家在網站的唯一標識');
            $table->string('cur', 5)->nullable()->comment('玩家貨幣符號');
            $table->integer('mid')->nullable()->index()->comment('遊戲ID');
            $table->string('gid', 50)->nullable()->comment('一局SPIN的ID，免費遊戲GID相同');
            $table->string('tid', 50)->nullable()->unique()->comment('每局遊戲的交易ID');
            $table->decimal('bet', 18, 4)->comment('投注金額');
            $table->decimal('win', 18, 4)->comment('贏得金額');
            $table->decimal('left', 18, 4)->comment('下注後餘額');
            $table->integer('isGamble')->nullable()->comment('是否為Gamble，1:是0:否');
            $table->integer('isFreeSpin')->nullable()->comment('是否為免費遊戲，1:是0:否');
            $table->integer('freeSpinNum')->nullable()->comment('免費遊戲序號');
            $table->integer('time')->nullable()->comment('該局結算的UTC時間戳記，單位秒');
            $table->dateTime('bet_time')->nullable()->index()->comment('該局結算的UTC時間，單位秒');
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->index()->comment('更新日期');
            $table->integer('admin')->default(0)->comment('更新者ID');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('lucky_bet_records');
    }
};
