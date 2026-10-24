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
        SchemaMonitor::create('igs_bet_records', function (Blueprint $table) {
            $table->increments('id');
            $table->bigInteger('bet_id')->default(0)->unique()->comment('注單pk');
            $table->string('username', 20)->index()->comment('玩家帳號');
            $table->string('user_id', 10)->comment('玩家ID');
            $table->decimal('bet_amount', 18, 4)->comment('投注金額');
            $table->decimal('win_amount', 18, 4)->comment('贏得金額');
            $table->string('trade_no', 191)->comment('局次ID');
            $table->decimal('amount_after', 18, 4)->comment('投注前金额');
            $table->string('game_type', 5)->index()->comment('遊戲代碼');
            $table->dateTime('bet_at')->comment('投注時間');
            $table->dateTime('utc_bet_at')->index()->comment('投注時間 UTC');
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->index()->comment('更新日期');
            $table->integer('type')->default(0)->comment('交易类型');
            $table->string('remark', 191)->comment('备注说明');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('igs_bet_records');
    }
};
