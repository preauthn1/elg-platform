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
        SchemaMonitor::create('summary_agent_settlements', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('agent_id')->comment('代理編號');
            $table->date('settlement_date')->index()->comment('結算日期');
            $table->integer('period')->default(0)->comment('期數 : summary_settlement_periods.period');
            $table->decimal('slot', 14, 4)->comment('slot銷售額');
            $table->decimal('score', 14, 4)->comment('score銷售額');
            $table->decimal('live', 14, 4)->comment('live銷售額');
            $table->decimal('sport', 14, 4)->comment('sport銷售額');
            $table->decimal('other', 14, 4)->comment('other銷售額');
            $table->decimal('bonus', 14, 4)->comment('Bonus');
            $table->decimal('profit_1', 14, 4)->comment('直屬盈利');
            $table->decimal('profit_2', 14, 4)->comment('下層盈利');
            $table->decimal('profit_3', 14, 4)->comment('下下層盈利');
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->comment('更新日期');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('summary_agent_settlements');
    }
};
