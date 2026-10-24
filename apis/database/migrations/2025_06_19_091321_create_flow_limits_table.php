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
        SchemaMonitor::create('flow_limits', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('member_id')->index()->comment('玩家ID');
            $table->integer('type')->comment('參照類型: 1:transaction_logs');
            $table->integer('event_id')->nullable()->default(0);
            $table->integer('reference_id')->comment('參照ID; 依照type');
            $table->integer('wallet_transaction_log_id')->default(0)->comment('wallet_transaction_logs.id');
            $table->decimal('flow_limit', 18, 4)->comment('流水限制');
            $table->decimal('reach', 18, 4)->default(0)->comment('達成');
            $table->string('remark', 100)->nullable()->comment('備註');
            $table->integer('status')->default(0)->index()->comment('狀態; 0:未通過, 1:通過, 9:取消稽核');
            $table->dateTime('created_at')->useCurrent()->comment('建立日期');
            $table->dateTime('updated_at')->useCurrentOnUpdate()->useCurrent()->comment('更新日期');
            $table->integer('admin')->comment('更新者ID');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('flow_limits');
    }
};
