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
        if (Schema::hasTable('summary_shareholder_foreign_records')) {
            return;
        }

        SchemaMonitor::create('summary_shareholder_foreign_records', function (Blueprint $table) {
            $table->increments('id');
            $table->dateTime('summary_time')->comment('結算時間 (UTC)');
            $table->unsignedInteger('site_id')->comment('shareholder_api_infos.id');
            $table->integer('currency_id')->default(1)->comment('幣種');
            $table->unsignedInteger('shareholder_category_id')->comment('shareholder_categories.id');
            $table->decimal('turnover', 18, 4)->default(0)->comment('總投注(本幣)');
            $table->decimal('win', 18, 4)->default(0)->comment('總支付(本幣)');
            $table->decimal('win_lose', 18, 4)->default(0)->comment('總輸贏(本幣)');
            $table->string('remark', 500)->default('');
            $table->unsignedInteger('admin')->default(0)->comment('操作者 agent_id');
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();

            $table->unique(['summary_time', 'site_id', 'shareholder_category_id'], 'uq_summary_site_category');
            $table->index('summary_time', 'idx_summary_time');
            $table->index('site_id', 'idx_site_id');
        });
    }

    public function down(): void
    {
        SchemaMonitor::dropIfExists('summary_shareholder_foreign_records');
    }
};
