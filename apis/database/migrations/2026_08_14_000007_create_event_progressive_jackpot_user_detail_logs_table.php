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
        SchemaMonitor::create('event_progressive_jackpot_user_detail_logs', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('member_id')->index()->comment('會員系統編號');
            $table->integer('order_id')->index()->comment('儲值訂單編號');
            $table->integer('level')->default(0)->comment('彩金等級，對應 event_progressive_jackpot_settings.level');
            $table->decimal('before_deposit_point', 18, 4)->default(0)->comment('異動前累積儲值金額');
            $table->decimal('after_deposit_point', 18, 4)->default(0)->comment('異動後累積儲值金額');
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
        SchemaMonitor::dropIfExists('event_progressive_jackpot_user_detail_logs');
    }
};
