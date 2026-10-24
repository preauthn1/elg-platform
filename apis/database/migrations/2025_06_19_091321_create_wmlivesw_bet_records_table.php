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
        SchemaMonitor::create('wmlivesw_bet_records', function (Blueprint $table) {
            $table->comment('完美真人注單紀錄');
            $table->increments('id');
            $table->string('bet_id', 200)->nullable()->unique()->comment('注单号');
            $table->string('member_id', 30)->index()->comment('會員帳號 ID');
            $table->string('account_id', 30)->index()->comment('遊戲帳號 ID');
            $table->string('username', 30)->index()->comment('遊戲帳號');
            $table->string('game_code', 30)->comment('gid 遊戲類別編號');
            $table->decimal('bet_amount', 18, 4)->default(0)->comment('bet 下注金額');
            $table->decimal('valid_bet', 18, 4)->default(0)->comment('validbet 有效下注金額');
            $table->decimal('win_lose', 18, 4)->default(0)->comment('winLoss 輸贏金額');
            $table->decimal('rolling_chips', 18, 4)->default(0)->comment('使用禮碼');
            $table->string('bet_deal_id', 191)->comment('下注 deal_id (多筆)');
            $table->string('result_deal_id', 191)->comment('派彩 deal_id (輸錢無派彩返回 -1)');
            $table->timestamp('bet_time')->nullable()->comment('betTime 下注時間');
            $table->timestamp('settlement_time')->nullable()->comment('settime 結算時間');
            $table->unsignedTinyInteger('status')->default(0)->index()->comment('我方處理狀態, 0:未處理, 1:完成, 2:失敗');
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
        SchemaMonitor::dropIfExists('wmlivesw_bet_records');
    }
};
