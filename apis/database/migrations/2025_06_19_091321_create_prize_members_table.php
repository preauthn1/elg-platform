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
        SchemaMonitor::create('prize_members', function (Blueprint $table) {
            $table->increments('id')->comment('流水號');
            $table->integer('member_id')->index()->comment('玩家系統編號');
            $table->string('prize_id', 191)->index()->comment('獎勵遊戲系統編號');
            $table->string('content_id', 191)->nullable()->comment('獲得獎項系統編號');
            $table->integer('event_detail_id')->nullable()->default(0)->index()->comment('event_details.id');
            $table->dateTime('expired_time')->nullable()->comment('領獎期限');
            $table->string('lottery_number', 191)->nullable()->comment('摸彩卷號碼');
            $table->decimal('lottery_point', 18, 4)->nullable()->comment('獎勵金額');
            $table->string('ip', 50)->default('0')->comment('領獎時的ip位置');
            $table->integer('status')->index()->comment('狀態;
投注活動; 0:未使用,1:已使用,3:取消
V-jackpot; 0:未開始,1:中獎,2:未中獎,3:取消
W-jackpot; 0:未開始,1:中獎,2:未中獎,3:取消,9:派發中');
            $table->timestamp('created_at')->useCurrent()->index()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->index()->comment('更新日期');
            $table->integer('admin')->comment('更新者ID');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('prize_members');
    }
};
