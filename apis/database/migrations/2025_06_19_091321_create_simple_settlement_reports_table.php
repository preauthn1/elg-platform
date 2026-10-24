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
        SchemaMonitor::create('simple_settlement_reports', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('simple_settlement_policy_id')->default(0)->comment('simple_settlement_policies.id');
            $table->dateTime('period_start')->comment('結算期間開始時間');
            $table->integer('agent_id')->comment('代理商系統編號');
            $table->decimal('percentage', 10)->comment('佔成百分比');
            $table->decimal('bet', 18, 4)->comment('投注金額');
            $table->decimal('gross_pnl', 18, 4)->comment('毛利損益');
            $table->decimal('bonus', 18, 4)->comment('獎勵金額');
            $table->decimal('net_pnl', 18, 4)->comment('淨利損益');
            $table->decimal('total_commission', 18, 4)->comment('所有佣金');
            $table->decimal('team_commission', 18, 4)->comment('下線佣金');
            $table->decimal('commission', 18, 4)->comment('自己佣金');
            $table->tinyInteger('status')->comment('狀態: -1 禁止派佣, 0 未派佣, 1 已派佣');
            $table->dateTime('pay_time')->comment('派佣時間');
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->comment('更新日期');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('simple_settlement_reports');
    }
};
