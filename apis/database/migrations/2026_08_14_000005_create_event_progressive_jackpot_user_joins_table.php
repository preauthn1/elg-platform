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
        SchemaMonitor::create('event_progressive_jackpot_user_joins', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('member_id')->comment('會員系統編號');
            $table->integer('level')->default(0)->comment('等級');
            $table->bigInteger('register_ip')->nullable()->comment('當下的IP');
            $table->string('mobile', 32)->nullable()->comment('當下的手機號');
            $table->string('realname', 100)->nullable()->comment('當下的真實姓名');
            $table->timestamp('expire_time')->nullable()->comment('過期時間');
            $table->decimal('reward', 18, 4)->default(0)->comment('彩金金額');
            $table->decimal('last_point', 18, 4)->default(0)->comment('上次已解鎖金額(前台通知用)');
            $table->decimal('point', 18, 4)->default(0)->comment('已解鎖金額');
            $table->decimal('receive', 18, 4)->default(0)->comment('已領取金額');
            $table->integer('status')->default(0)->comment('狀態 0:進行中, 1:等待解鎖, 2:已完成, 3:已過期, 4:已鎖定(IP、手機號、真實姓名重複), 5:已關閉(已完成且領取完畢，不再變動)');
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->comment('更新日期');
            $table->integer('admin')->default(0)->comment('操作者ID');

            $table->unique(['member_id', 'level'], 'unique_member_level');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('event_progressive_jackpot_user_joins');
    }
};
