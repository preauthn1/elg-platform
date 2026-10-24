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
        SchemaMonitor::create('fffsw_bet_records', function (Blueprint $table) {
            $table->increments('id');
            $table->string('bet_id', 200)->nullable()->unique()->comment('注單號碼');
            $table->string('period_number', 200)->nullable()->index()->comment('期號');
            $table->string('member_id', 30)->index()->comment('我方會員ID');
            $table->string('account_id', 30)->index()->comment('三方帳號 ID');
            $table->string('username', 30)->index()->comment('三方玩家帳號');
            $table->string('game_code', 30)->comment('遊戲代碼');
            $table->decimal('bet', 18, 4)->default(0)->comment('下注金額');
            $table->decimal('valid_bet', 18, 4)->default(0)->comment('有效下注金額');
            $table->decimal('win_lose', 18, 4)->default(0)->comment('輸贏金額');
            $table->decimal('rolling_chips', 18, 4)->default(0)->comment('使用禮碼');
            $table->timestamp('bet_time')->nullable()->comment('下注時間');
            $table->timestamp('payout_time')->nullable()->comment('派彩時間');
            $table->tinyInteger('status')->comment('處理狀態: -2=回滾, -1=餘額不足, 0=未完成, 1=已扣款, 2=已派彩');
            $table->text('raw')->nullable()->comment('三方原始數據');
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('fffsw_bet_records');
    }
};
