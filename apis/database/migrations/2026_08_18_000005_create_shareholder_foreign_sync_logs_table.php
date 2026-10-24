<?php

declare(strict_types=1);

use App\Facades\SchemaMonitor;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('shareholder_foreign_sync_logs')) {
            return;
        }

        SchemaMonitor::create('shareholder_foreign_sync_logs', function (Blueprint $table) {
            $table->increments('id');
            $table->dateTime('summary_time')->comment('結算時間 (UTC)');
            $table->unsignedInteger('site_id')->comment('shareholder_api_infos.id');
            $table->dateTime('sync_time')->nullable()->comment('最後同步時間 (UTC)');
            $table->json('data')->nullable()->comment('子站回傳原始內容');
            $table->tinyInteger('status')->default(0)->comment('0未同步 1已同步 2失敗 3排除');
            $table->tinyInteger('push_status')->default(0)->comment('推送狀態 0:未推送 1:成功 2:失敗');
            $table->dateTime('push_time')->nullable()->comment('最後推送時間');
            $table->json('push_data')->nullable()->comment('推送後回應內容');
            $table->decimal('rate_to_usd', 18, 8)->default(1)->comment('本幣對USD匯率');
            $table->string('remark', 500)->default('');
            $table->unsignedInteger('admin')->default(0)->comment('操作者 agent_id');
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent();

            $table->unique(['summary_time', 'site_id'], 'uq_summary_site');
            $table->index('summary_time', 'idx_summary_time');
            $table->index('site_id', 'idx_site_id');
        });
    }

    public function down(): void
    {
        SchemaMonitor::dropIfExists('shareholder_foreign_sync_logs');
    }
};
