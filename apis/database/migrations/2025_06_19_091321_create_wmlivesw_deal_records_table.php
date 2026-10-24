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
        SchemaMonitor::create('wmlivesw_deal_records', function (Blueprint $table) {
            $table->comment('完美真人交易紀錄');
            $table->increments('id');
            $table->string('deal_id', 200)->nullable()->unique()->comment('dealid 交易單號');
            $table->string('deal_code', 200)->nullable()->comment('code 加扣点类型 0:电子游戏结算 1:加点 2:扣点 3:重对加点 4:重对扣点 5:重新派彩');
            $table->string('member_id', 30)->index()->comment('會員帳號 ID');
            $table->string('account_id', 30)->index()->comment('遊戲帳號 ID');
            $table->string('username', 30)->index()->comment('遊戲帳號');
            $table->string('amount', 30)->comment('money 加(扣)该会员的点数');
            $table->string('game_code', 30)->comment('gtype 遊戲類別編號');
            $table->string('bet_id', 200)->nullable()->index()->comment('注單編號');
            $table->timestamp('request_time')->useCurrentOnUpdate()->useCurrent()->comment('requestDate 三方請求時間');
            $table->unsignedTinyInteger('status')->default(0)->index()->comment('我方處理狀態, 0:未處理, 1:交易成功, 2:交易失敗(餘額不足)');
            $table->string('transaction_log_id', 200)->nullable()->comment('交易紀錄編號');
            $table->unsignedTinyInteger('is_settlement')->default(0)->index()->comment('0:未結算, 1:結算');
            $table->unsignedTinyInteger('is_rollback')->default(0)->index()->comment('0:未回滾, 1:已回滾');
            $table->decimal('rolling_chips', 18, 4)->default(0)->comment('使用禮碼');
            $table->json('raw_data')->nullable()->comment('三方原始數據');
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent();

            $table->foreign(['username'])->references(['username'])->on('wmlivesw_accounts')->onUpdate('restrict')->onDelete('restrict');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('wmlivesw_deal_records');
    }
};
