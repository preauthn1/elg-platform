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
        SchemaMonitor::create('event_invite_user_joins', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('member_id')->unique()->comment('會員系統編號');
            $table->integer('invite_member_id')->index()->comment('邀請人會員系統編號');
            $table->bigInteger('register_ip')->nullable()->comment('註冊當下的IP');
            $table->string('register_mobile', 32)->nullable()->comment('註冊當下的手機號');
            $table->string('register_realname', 100)->nullable()->comment('註冊當下的真實姓名，邀請人領取獎勵時會更改成當下的真實姓名');
            $table->integer('block_ip_member_id')->default(0)->comment('IP重複的會員系統編號>0則不派發，後台解鎖時另寫一筆並改為0');
            $table->integer('block_mobile_member_id')->default(0)->comment('手機號重複的會員系統編號>0則不派發，後台解鎖時另寫一筆並改為0');
            $table->integer('block_realname_member_id')->default(0)->comment('真實姓名重複的會員系統編號>0則不派發，後台解鎖時另寫一筆並改為0');
            $table->integer('realname_status')->default(0)->comment('真實姓名認證獎勵是否領取 0:未領取, 1:已領取');
            $table->integer('deposit_status')->default(0)->comment('儲值獎勵是否領取 0:未領取, 1:已領取');
            $table->decimal('bet', 18, 4)->default(0)->comment('累積流水');
            $table->decimal('point', 18, 4)->default(0)->comment('可領取金額');
            $table->decimal('receive', 18, 4)->default(0)->comment('已領取金額');
            $table->integer('status')->default(0)->comment('狀態 0:進行中, 1:結束, 2:有重複玩家不計算');
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->comment('更新日期');
            $table->integer('admin')->default(0)->comment('操作者ID');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('event_invite_user_joins');
    }
};
