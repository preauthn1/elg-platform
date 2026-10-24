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
        SchemaMonitor::create('event_progressive_jackpot_user_details', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('member_id')->unique()->comment('會員系統編號');
            $table->decimal('total_deposit_point', 18, 4)->default(0)->comment('累積儲值金額');
            $table->integer('block_ip_member_id')->default(0)->comment('IP重複的會員系統編號>0則狀態改為鎖定中');
            $table->integer('block_mobile_member_id')->default(0)->comment('手機號重複的會員系統編號>0則狀態改為鎖定中');
            $table->integer('block_realname_member_id')->default(0)->comment('真實姓名重複的會員系統編號>0則狀態改為鎖定中');
            $table->boolean('is_receive')->default(false)->comment('是否已領取過獎金，用於判斷是否為第一次領取');
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
        SchemaMonitor::dropIfExists('event_progressive_jackpot_user_details');
    }
};
