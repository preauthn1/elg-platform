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
        SchemaMonitor::create('member_hour_summaries', function (Blueprint $table) {
            $table->bigIncrements('id')->comment('流水號');
            $table->integer('member_id')->index()->comment('會員系統編號');
            $table->dateTime('summary_hour')->index()->comment('統計整點（該小時起點）');
            $table->integer('deposit_count')->default(0)->comment('該小時代收成功次數');
            $table->decimal('deposit_amount', 18, 4)->default(0)->comment('該小時代收成功總額（orders.point 加總）');
            $table->integer('withdrawal_count')->default(0)->comment('該小時代付成功次數');
            $table->decimal('withdrawal_amount', 18, 4)->default(0)->comment('該小時代付成功總額（withdrawal_orders.point 加總）');
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->comment('更新日期');

            $table->unique(['member_id', 'summary_hour'], 'unique_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('member_hour_summaries');
    }
};
