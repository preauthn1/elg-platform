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
        if (Schema::hasTable('shareholder_foreign_operation_logs')) {
            return;
        }

        SchemaMonitor::create('shareholder_foreign_operation_logs', function (Blueprint $table) {
            $table->comment('子站操作歷史紀錄');
            $table->id();
            $table->string('action', 50)->comment('sync/exclude/cancel_exclude/push');
            $table->integer('site_id')->default(0);
            $table->string('url', 500)->nullable();
            $table->smallInteger('http_status')->nullable()->comment('HTTP 狀態碼');
            $table->json('request')->nullable();
            $table->json('response')->nullable();
            $table->integer('admin')->default(0);
            $table->dateTime('created_at')->useCurrent();

            $table->index('action', 'idx_action');
            $table->index('site_id', 'idx_site_summary');
        });
    }

    public function down(): void
    {
        SchemaMonitor::dropIfExists('shareholder_foreign_operation_logs');
    }
};
