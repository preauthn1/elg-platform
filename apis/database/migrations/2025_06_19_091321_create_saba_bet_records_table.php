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
        SchemaMonitor::create('saba_bet_records', function (Blueprint $table) {
            $table->increments('id');
            $table->string('vendor_id', 45)->nullable()->index();
            $table->integer('vendor_info_id')->default(0)->comment('遊戲商資訊ID');
            $table->string('bet_id', 30)->default('0')->index()->comment('注单唯一Id, ThemeId');
            $table->string('username', 30)->index()->comment('玩家帳號');
            $table->string('game_code', 20)->index()->comment('游戏ID');
            $table->dateTime('winlost_datetime')->index()->comment('算帳時間');
            $table->dateTime('bet_time')->comment('派彩時間(utc)');
            $table->dateTime('created_time')->comment('押注時間(utc)');
            $table->string('ticket_status', 20)->nullable()->index();
            $table->integer('version_key')->nullable();
            $table->integer('last_version_key')->nullable();
            $table->decimal('bet', 18, 4)->default(0)->comment('下注金额');
            $table->decimal('win_lose', 18, 4)->default(0)->comment('輸贏金额');
            $table->text('info')->comment('response data');
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->index()->comment('更新日期');

            $table->unique(['vendor_id', 'bet_id'], 'unique_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('saba_bet_records');
    }
};
