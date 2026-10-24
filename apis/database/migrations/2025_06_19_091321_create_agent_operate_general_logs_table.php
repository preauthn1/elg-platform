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
        SchemaMonitor::create('agent_operate_general_logs', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('operator_id')->index()->comment('操作员ID');
            $table->string('project_name', 191)->default('WEB_Agent')->index()->comment('專案名稱');
            $table->string('page_path', 191)->index()->comment('操作页面路徑');
            $table->string('request_method', 191)->comment('請求方法, HTTP Method');
            $table->string('ip', 191)->comment('IP');
            $table->json('content')->comment('操作內容');
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->comment('更新日期');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('agent_operate_general_logs');
    }
};
