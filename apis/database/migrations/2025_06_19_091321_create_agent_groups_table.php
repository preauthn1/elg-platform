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
        SchemaMonitor::create('agent_groups', function (Blueprint $table) {
            $table->comment('代理設置分組組別');
            $table->increments('id')->comment('PK流水號');
            $table->integer('agent_group_type')->index()->comment('1:基本個性化設置組別、2:財務組別  3:簡訊設置組別 4.聊天chatGroup相關');
            $table->integer('organization_id')->default(1)->index()->comment('所屬組織');
            $table->string('name', 191)->comment('備註顯示名稱(需要多語系嗎)');
            $table->string('memo', 191)->comment('備註');
            $table->tinyInteger('is_default')->comment('是否為預設組別');
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->comment('更新日期');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('agent_groups');
    }
};
