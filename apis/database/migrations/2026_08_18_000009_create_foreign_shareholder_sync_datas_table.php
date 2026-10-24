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
        if (Schema::hasTable('foreign_shareholder_sync_datas')) {
            return;
        }

        SchemaMonitor::create('foreign_shareholder_sync_datas', function (Blueprint $table) {
            $table->comment('模擬外部子站儲存資料使用');
            $table->id();
            $table->date('summary_date')->comment('業績日期');
            $table->unsignedBigInteger('site_id')->comment('來源站 shareholder_api_infos.id');
            $table->string('site', 50)->default('')->comment('分站代碼');
            $table->string('currency', 20)->default('')->comment('幣種');
            $table->unsignedInteger('category_id')->default(0)->comment('分桶類別 ID');
            $table->decimal('bet', 16, 4)->default(0)->comment('投注額');
            $table->decimal('payout', 16, 4)->default(0)->comment('派彩額（負值為輸）');
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent();

            $table->unique(['site_id', 'summary_date', 'site', 'category_id'], 'uq_site_date_category');
            $table->index('summary_date', 'idx_summary_date');
        });
    }

    public function down(): void
    {
        SchemaMonitor::dropIfExists('foreign_shareholder_sync_datas');
    }
};
