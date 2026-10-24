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
        SchemaMonitor::create('unlimit_bonusreport_agents', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('agent_id')->default(0)->index()->comment('代理編號');
            $table->dateTime('summary_date')->index()->comment('時間(日)');
            $table->decimal('performance_direct', 18, 4)->default(0)->comment('業績-直屬');
            $table->decimal('performance_team', 18, 4)->default(0)->comment('業績-團隊');
            $table->decimal('bonus_direct', 18, 4)->default(0)->comment('獎金-直屬');
            $table->decimal('bonus_team', 18, 4)->default(0)->comment('獎金-團隊');
            $table->decimal('bonus_amount', 10)->default(0)->comment('該級距獎勵金額-參考unlimit_systemrate');
            $table->integer('status')->default(0)->comment('狀態: 1000:success');
            $table->dateTime('payout_time')->nullable()->comment('發放時間');
            $table->string('remark')->default('')->comment('備註');
            $table->tinyInteger('correction')->default(0)->comment('0:否, 1:是');
            $table->string('memo', 191)->nullable()->comment('備註');
            $table->dateTime('created_at')->useCurrent()->index()->comment('建立日期');
            $table->dateTime('updated_at')->useCurrentOnUpdate()->useCurrent()->comment('更新日期');
            $table->integer('admin')->comment('更新者ID');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('unlimit_bonusreport_agents');
    }
};
