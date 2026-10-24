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
        SchemaMonitor::create('summary_shareholder_temps', function (Blueprint $table) {
            $table->increments('id')->comment('流水號');
            $table->dateTime('summary_time')->index()->comment('彙總時間(UTC)');
            $table->integer('agent_group_id')->default(0)->index()->comment('代理群別ID');
            $table->integer('shareholder_category_id')->default(0)->index()->comment('shareholder_category.id');
            $table->integer('share_point')->default(0)->comment('分桶分成比例');
            $table->decimal('turnover', 18, 4)->default(0)->comment('總有效流水');
            $table->decimal('original_turnover', 18, 4)->default(0)->comment('原始總有效流水');
            $table->decimal('nett_profit', 18, 4)->default(0)->comment('總淨利潤');
            $table->decimal('original_nett_profit', 18, 4)->default(0)->comment('原始總淨利潤');
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->comment('更新日期');
            $table->integer('admin')->default(0)->comment('操作者');

            $table->unique(['summary_time', 'agent_group_id', 'shareholder_category_id'], 'unique_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('summary_shareholder_temps');
    }
};
