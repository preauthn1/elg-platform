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
        SchemaMonitor::create('shareholder_api_infos', function (Blueprint $table) {
            $table->comment('分桶分站API資訊');
            $table->increments('id');
            $table->string('agent_id', 191)->default('')->comment('本站的總代ID');
            $table->string('site_name', 191)->default('')->comment('分站名(使用英數_符號,不要有空格與-號)');
            $table->unsignedInteger('foreign_agent_id')->comment('分站的總代ID');
            $table->text('api_url');
            $table->boolean('status')->default(true)->comment('1:啟用 0:停用');
            $table->text('memo')->nullable()->comment('備註');
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->comment('更新日期');
            $table->unsignedInteger('admin')->comment('更新者ID');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('shareholder_api_infos');
    }
};
