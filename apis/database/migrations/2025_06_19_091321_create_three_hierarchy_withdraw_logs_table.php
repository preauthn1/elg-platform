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
        SchemaMonitor::create('three_hierarchy_withdraw_logs', function (Blueprint $table) {
            $table->increments('id');
            $table->string('order_no', 10)->comment('訂單單號');
            $table->integer('type')->default(1);
            $table->integer('member_id')->index()->comment('玩家編號');
            $table->decimal('withdraw_point', 18)->default(0)->comment('提線金額');
            $table->decimal('remaining_point', 18)->default(0)->comment('剩餘金額');
            $table->tinyInteger('status')->default(0)->comment('狀態:0失敗,1成功');
            $table->timestamp('created_at')->useCurrent()->index()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->comment('更新日期');
            $table->integer('admin')->comment('更新者ID');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('three_hierarchy_withdraw_logs');
    }
};
