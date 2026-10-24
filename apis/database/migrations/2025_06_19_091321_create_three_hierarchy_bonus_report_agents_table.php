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
        SchemaMonitor::create('three_hierarchy_bonus_report_agents', function (Blueprint $table) {
            $table->increments('id');
            $table->dateTime('summary_time')->index()->comment('時間(日)');
            $table->integer('agent_id')->default(0)->index()->comment('代理系統編號');
            $table->decimal('performance', 18, 4)->default(0)->comment('業績');
            $table->decimal('rebate_amount', 18, 4)->default(0)->comment('回水金額');
            $table->integer('status')->default(0)->comment('狀態:0未發送,1000 已發送');
            $table->dateTime('payout_time')->nullable()->comment('發送時間');
            $table->string('remark', 191)->nullable()->comment('備註');
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->comment('更新日期');
            $table->integer('admin')->default(0)->comment('更新者ID');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('three_hierarchy_bonus_report_agents');
    }
};
