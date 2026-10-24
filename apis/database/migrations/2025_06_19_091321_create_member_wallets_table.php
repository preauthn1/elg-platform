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
        SchemaMonitor::create('member_wallets', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('member_id')->index()->comment('玩家帳號');
            $table->integer('wallet_type_id')->index()->comment('錢包類型; 1:主錢包, 1002:禮碼錢包, 1003:免費幣排行榜活動');
            $table->decimal('balance', 30, 10)->default(0)->comment('錢包實際金額');
            $table->dateTime('first_deposit')->nullable()->comment('首次儲值時間');
            $table->integer('is_bet')->default(0)->comment('當天是否投注過');
            $table->tinyInteger('status')->default(0)->comment('帳號狀態 0:未使用,1:使用中,2:鎖定(異常, 無法使用)');
            $table->string('memo', 191)->nullable()->comment('方便技術記錄用');
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->comment('更新日期');
            $table->integer('admin')->default(0)->comment('更新者');

            $table->unique(['member_id', 'wallet_type_id'], 'unique_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('member_wallets');
    }
};
