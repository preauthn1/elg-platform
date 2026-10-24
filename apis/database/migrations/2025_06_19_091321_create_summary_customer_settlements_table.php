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
        SchemaMonitor::create('summary_customer_settlements', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('agent_id')->comment('代理編號');
            $table->date('settlement_date')->index()->comment('結算日期');
            $table->integer('period')->default(0)->comment('期數 : summary_settlement_periods.period');
            $table->decimal('sales', 14, 4)->comment('銷售額');
            $table->decimal('bonus', 14, 4)->comment('Bonus');
            $table->decimal('commission', 14, 4)->comment('傭金');
            $table->decimal('vendor_fee', 14, 4)->comment('公司盤費');
            $table->decimal('rebate', 14, 4)->comment('返水');
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->comment('更新日期');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('summary_customer_settlements');
    }
};
