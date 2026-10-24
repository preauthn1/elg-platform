<?php

declare(strict_types=1);

use App\Facades\SchemaMonitor;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        SchemaMonitor::create('suncity_bet_records', function (Blueprint $table) {
            $table->increments('id');
            // 拉單不填 vendor_info_id／member_id（維持 0），歸戶在 TBR 轉檔時以 username → suncity_accounts 解析
            $table->integer('vendor_info_id')->default(0)->comment('遊戲商設定編號');
            $table->integer('member_id')->default(0)->comment('會員系統編號');
            $table->string('username', 50)->comment('玩家帳號');
            // bet_id = 三方 GameSerialID，unique 為拉單去重的冪等鍵；三方注單無 bonus／jackpot 欄位
            $table->string('bet_id', 100)->unique()->comment('注單號（GameSerialID），冪等鍵');
            $table->string('game_code', 50)->comment('遊戲代碼');
            $table->decimal('bet', 18, 4)->default(0)->comment('下注金額');
            $table->decimal('valid_bet', 18, 4)->default(0)->comment('有效投注');
            $table->decimal('win', 18, 4)->default(0)->comment('派彩金額');
            $table->decimal('win_lose', 18, 4)->default(0)->comment('輸贏金額');
            $table->decimal('rolling_chip', 18, 4)->default(0)->comment('禮碼金額');
            $table->timestamp('bet_time')->comment('投注時間');
            $table->timestamp('payout_time')->nullable()->comment('派彩時間');
            $table->tinyInteger('status')->default(0)->comment('注單狀態 0:建立中 1:已結算 2:取消/回滾');
            $table->text('info')->nullable()->comment('三方原始注單');
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->comment('更新日期');

            // 對帳三表 SUM 對齊以 payout_time 切窗（time_field=payout_time）
            $table->index('payout_time', 'idx_suncity_br_payout_time');
            // 特殊情況以投注時間切窗查單
            $table->index('bet_time', 'idx_suncity_br_bet_time');
        });

        // SchemaMonitor 遇表已存在會靜默跳過，補生效斷言避免「migration 已跑、schema 不符」無人察覺；
        // migrate --pretend 時 select 一律回空，跳過斷言以免誤拋
        if (!Schema::getConnection()->pretending() && !Schema::hasColumns('suncity_bet_records', ['bet_id', 'username', 'game_code', 'bet', 'valid_bet', 'win', 'win_lose', 'rolling_chip', 'bet_time', 'payout_time', 'status'])) {
            throw new RuntimeException('suncity_bet_records 建表未生效或既有表結構不符');
        }
    }

    public function down(): void
    {
        SchemaMonitor::dropIfExists('suncity_bet_records');
    }
};
