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
        SchemaMonitor::create('sms_texts', function (Blueprint $table) {
            $table->increments('id')->comment('流水號');
            $table->integer('agent_id')->index()->comment('營運商系統編號');
            $table->integer('sms_gateway_group_id')->default(1)->index()->comment('簡訊群組ID(sms_gateway_groups.id)');
            $table->integer('type_id')->index()->comment('類型編號; params.type=sms_text_type');
            $table->integer('country_id')->index()->comment('國家系統編號');
            $table->string('text', 191)->comment('訊息內容');
            $table->boolean('status')->default(false)->comment('狀態0:禁用1:使用中');
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
        SchemaMonitor::dropIfExists('sms_texts');
    }
};
