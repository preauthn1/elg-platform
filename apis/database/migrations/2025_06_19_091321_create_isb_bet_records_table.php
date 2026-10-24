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
        SchemaMonitor::create('isb_bet_records', function (Blueprint $table) {
            $table->increments('id')->comment('流水號');
            $table->string('playerid', 64)->index()->comment('玩家編號');
            $table->string('operator', 64)->comment('代理名稱');
            $table->string('currency', 6)->nullable()->comment('幣別');
            $table->string('sessionid', 64)->nullable()->comment('SESSION ID');
            $table->string('gameid', 32)->nullable()->index()->comment('遊戲編號');
            $table->string('roundid', 64)->nullable()->index()->comment('局號');
            $table->string('status', 10)->nullable()->comment('狀態');
            $table->string('channel', 10)->nullable()->comment('Desktop or Mobile');
            $table->string('type', 32)->nullable()->comment('類型：BET、WIN、CANCEL、FREE_ROUND_BET、FREE_ROUND_WIN');
            $table->string('transactionid', 64)->nullable()->unique()->comment('交易編號');
            $table->dateTime('time')->index()->comment('投注時間(GMT)');
            $table->decimal('amount', 18, 4)->nullable()->comment('投注金額');
            $table->decimal('balance', 18, 4)->nullable()->comment('餘額');
            $table->decimal('jpc', 18, 4)->nullable()->comment('Amount of JP contribution, in cents.');
            $table->decimal('jpw', 18, 4)->nullable()->comment('Amount of JP wins, in cents.');
            $table->decimal('jpw_jpc', 18, 4)->nullable()->comment('NGR = bet - win + jpw_from_jpc - jpc.');
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->index()->comment('更新日期');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('isb_bet_records');
    }
};
