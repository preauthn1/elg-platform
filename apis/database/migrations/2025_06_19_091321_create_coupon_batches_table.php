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
        SchemaMonitor::create('coupon_batches', function (Blueprint $table) {
            $table->increments('id');
            $table->string('batch_code', 32)->unique()->comment('批次序號');
            $table->integer('agent_id')->index()->comment('代理編號');
            $table->tinyInteger('type')->comment('1:點數, 2:禮碼');
            $table->integer('quantity')->comment('數量');
            $table->decimal('amount', 18, 4)->default(0)->comment('金額');
            $table->integer('receive_limit')->default(1)->comment('會員可領取次數 0:無限制');
            $table->tinyInteger('status')->default(0)->comment('狀態; 0:關閉, 1:開啟');
            $table->dateTime('start_time')->comment('開始時間');
            $table->dateTime('end_time')->comment('結束時間');
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->comment('更新日期');
            $table->integer('admin')->default(0)->comment('操作者');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('coupon_batches');
    }
};
