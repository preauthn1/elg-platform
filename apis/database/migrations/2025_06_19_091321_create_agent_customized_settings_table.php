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
        SchemaMonitor::create('agent_customized_settings', function (Blueprint $table) {
            $table->comment('代理個性化設置');
            $table->increments('id')->comment('PK流水號');
            $table->integer('agent_group_id')->index()->comment('設置分組ID');
            $table->integer('setting_key')->index()->comment('主要分類鍵值 (請參考 AgentCustomizedSettingKeyEnum)');
            $table->string('sub_category', 50)->index()->comment('子分類 (同一組key、可能有多種設置 e.g. 語系)');
            $table->text('value')->comment('設值數值');
            $table->string('memo', 191)->comment('備註');
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->comment('更新日期');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('agent_customized_settings');
    }
};
