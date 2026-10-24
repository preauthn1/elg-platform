<?php

declare(strict_types=1);

use App\Facades\SchemaMonitor;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

return new class extends Migration
{
    public function up(): void
    {
        SchemaMonitor::create('aigamingsw_bet_records', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('vendor_info_id')->default(0)->comment('遊戲商設定編號');
            $table->integer('member_id')->default(0)->comment('會員系統編號');
            $table->string('username', 50)->comment('玩家帳號');
            // bet_id = 廠商 transactionId（deduct / settle / bonus 共用同一 transactionId，
            // cashBonus 自帶獨立 transactionId），unique 為冪等鍵；本 vendor 無 jackpot 欄位需求
            $table->string('bet_id', 100)->unique()->comment('注單號（transactionId），冪等鍵');
            $table->string('game_code', 50)->comment('遊戲代碼');
            $table->decimal('bet', 18, 4)->default(0)->comment('下注金額');
            $table->decimal('valid_bet', 18, 4)->default(0)->comment('有效投注');
            $table->decimal('win', 18, 4)->default(0)->comment('派彩金額');
            $table->decimal('win_lose', 18, 4)->default(0)->comment('輸贏金額');
            $table->decimal('bonus', 18, 4)->default(0)->comment('活動獎金（cashBonus 純派彩）');
            $table->decimal('rolling_chip', 18, 4)->default(0)->comment('禮碼金額');
            $table->timestamp('bet_time')->comment('投注時間');
            $table->timestamp('payout_time')->nullable()->comment('派彩時間');
            $table->tinyInteger('status')->default(0)->comment('注單狀態 0:建立中 1:已結算 2:取消/回滾');
            $table->text('info')->nullable()->comment('原始請求資料');
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->comment('更新日期');

            // 對帳三表 SUM 對齊以 payout_time 切窗（time_field=payout_time）
            $table->index('payout_time', 'idx_aigamingsw_br_payout_time');
            // 特殊情況以投注時間切窗查單（如未結算單排查）
            $table->index('bet_time', 'idx_aigamingsw_br_bet_time');
        });
    }

    public function down(): void
    {
        SchemaMonitor::dropIfExists('aigamingsw_bet_records');
    }
};
