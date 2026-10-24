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
        SchemaMonitor::create('events', function (Blueprint $table) {
            $table->increments('id')->comment('系統編號');
            $table->string('title', 191)->comment('預設顯示活動標題');
            $table->longText('content')->comment('預設顯示活動內容');
            $table->integer('type_id')->comment('類型編號; params.type=event_type');
            $table->string('category', 45)->nullable()->comment('活動類別組別');
            $table->dateTime('start_time')->nullable()->comment('開始時間');
            $table->dateTime('end_time')->nullable()->comment('結束時間');
            $table->integer('agent_id')->nullable()->index()->comment('營運商系統編號');
            $table->tinyInteger('status')->default(0)->index()->comment('狀態 0:停用,1:啟用');
            $table->string('auto_increment', 45)->default('0')->comment('活動到期自動遞增; call ADMIN_event_automatic_increase');
            $table->integer('auto_payout')->default(0)->comment('自動派發獎勵');
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
        SchemaMonitor::dropIfExists('events');
    }
};
