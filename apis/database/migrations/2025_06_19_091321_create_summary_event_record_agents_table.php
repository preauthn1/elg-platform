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
        SchemaMonitor::create('summary_event_record_agents', function (Blueprint $table) {
            $table->dateTime('summary_time')->comment('數據時間');
            $table->integer('agent_id')->comment('代理編號');
            $table->tinyInteger('event_id')->comment('事件編號; 1:活動獎勵(含登入活動), 2:無限代獎勵,3:pay fee, 4:sms fee');
            $table->decimal('point', 20, 6)->nullable()->default(0)->comment('點數(不含代理本身)');
            $table->decimal('self_point', 18, 4)->nullable()->default(0)->comment('代理本身所獲得點數');
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->comment('更新日期');

            $table->primary(['summary_time', 'agent_id', 'event_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('summary_event_record_agents');
    }
};
