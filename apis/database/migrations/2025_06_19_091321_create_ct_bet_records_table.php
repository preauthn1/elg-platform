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
        SchemaMonitor::create('ct_bet_records', function (Blueprint $table) {
            $table->increments('id');
            $table->bigInteger('betId')->default(0)->unique()->comment('注单唯一Id');
            $table->string('userName', 191)->index()->comment('玩家帳號');
            $table->integer('GameId')->default(0)->index()->comment('游戏Id');
            $table->timestamp('betTimeUtc')->nullable()->index()->comment('押注時間 UTC');
            $table->timestamp('calTimeUtc')->nullable()->index()->comment('结算时间 UTC');
            $table->decimal('winOrLoss', 18, 4)->comment('派彩金额 (输赢应扣除下注金额)');
            $table->decimal('betPoints', 18, 4)->comment('下注金额');
            $table->decimal('availableBet', 18, 4)->comment('有效下注金额');
            $table->integer('isRevocation')->index()->comment('是否结算：0:未结算, 1:已结算, 2:已撤销(该注单为对冲注单)');
            $table->text('info')->comment('response data');
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->index()->comment('更新日期');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('ct_bet_records');
    }
};
