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
        SchemaMonitor::create('sbo_bet_records', function (Blueprint $table) {
            $table->increments('id')->comment('系統編號');
            $table->string('player_name', 20)->index()->comment('玩家名稱');
            $table->string('vendor_code', 5)->comment('產品代碼');
            $table->string('game_code', 10)->index()->comment('遊戲代碼');
            $table->integer('parent_bet_id')->comment('父注编號');
            $table->integer('bet_id')->index()->comment('下注編號');
            $table->string('trans_type', 20)->index()->comment('Stake(下注或取消本注)');
            $table->string('currency', 5)->comment('幣別');
            $table->string('wallet_code', 20)->comment('錢包代碼');
            $table->decimal('bet_amount', 18, 4)->comment('下注金額');
            $table->decimal('win_amount', 18, 4)->nullable()->comment('派彩或退回金額');
            $table->string('traceId', 191)->nullable()->comment('交易編號');
            $table->dateTime('create_time')->nullable()->comment('投注時間');
            $table->timestamp('created_at')->useCurrent()->index()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->index()->comment('更新日期');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('sbo_bet_records');
    }
};
