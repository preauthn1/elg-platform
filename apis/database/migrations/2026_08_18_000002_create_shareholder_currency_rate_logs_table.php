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
        if (Schema::hasTable('shareholder_currency_rate_logs')) {
            return;
        }

        SchemaMonitor::create('shareholder_currency_rate_logs', function (Blueprint $table) {
            $table->comment('稱桶幣別匯率異動紀錄');
            $table->increments('id');
            $table->unsignedInteger('currency_id')->comment('currencies.id');
            $table->decimal('rate_before', 18, 8)->nullable()->comment('異動前匯率（首次新增為 null）');
            $table->decimal('rate_after', 18, 8)->comment('異動後匯率');
            $table->unsignedInteger('admin')->default(0);
            $table->timestamp('created_at')->useCurrent()->index();
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent();

            $table->index('currency_id', 'idx_currency_id');
        });
    }

    public function down(): void
    {
        SchemaMonitor::dropIfExists('shareholder_currency_rate_logs');
    }
};
