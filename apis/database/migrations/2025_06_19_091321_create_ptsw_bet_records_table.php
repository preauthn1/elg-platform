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
        SchemaMonitor::create('ptsw_bet_records', function (Blueprint $table) {
            $table->increments('id');
            $table->string('member_id', 30)->index()->comment('我方會員ID');
            $table->string('username', 30)->index()->comment('三方玩家帳號');
            $table->string('token')->nullable()->comment('外部令牌，用於標識玩家的遊戲週期。必須包含kiosk 前綴。');
            $table->string('bet_id', 200)->nullable()->unique()->comment('注單號碼');
            $table->string('transaction_code', 20)->nullable()->unique()->comment('三方交易代號');
            $table->string('game_code', 30)->comment('遊戲代碼');
            $table->decimal('bet', 18, 4)->default(0)->comment('下注金額');
            $table->decimal('valid_bet', 18, 4)->default(0)->comment('有效下注金額');
            $table->decimal('win_lose', 18, 4)->default(0)->comment('輸贏金額');
            $table->decimal('rolling_chips', 18, 4)->default(0)->comment('使用禮碼');
            $table->decimal('jackpot', 18, 4)->default(0)->comment('獎池彩金');
            $table->timestamp('bet_time')->nullable()->comment('下注時間');
            $table->timestamp('payout_time')->nullable()->comment('派彩時間');
            $table->tinyInteger('status')->default(0)->comment('處理狀態: -2=回滾, -1=餘額不足, 0=待處理, 1=遊戲中, 2=遊戲回合結束');
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent();

            $table->foreign(['username'])->references(['username'])->on('ptsw_accounts')->onUpdate('no action')->onDelete('no action');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('ptsw_bet_records');
    }
};
