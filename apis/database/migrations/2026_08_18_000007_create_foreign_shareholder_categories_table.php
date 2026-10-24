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
        if (Schema::hasTable('foreign_shareholder_categories')) {
            return;
        }

        SchemaMonitor::create('foreign_shareholder_categories', function (Blueprint $table) {
            $table->comment('模擬外部子站類別存放使用');
            $table->increments('id');
            $table->integer('shareholder_category_id')->comment('代理個性化設置編號');
            $table->integer('agent_group_id')->comment('代理個性化設置編號');
            $table->string('name', 45);
            $table->integer('bet_ratio')->default(100)->comment('公司投注額分紅比例');
            $table->integer('commission_ratio')->default(0)->comment('公司業績分紅比例');
            $table->tinyInteger('profit_share_visible')->default(1)->comment('股東佔成管理 > 遊戲類別佔成設定 是否顯示');
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent();

            $table->index('shareholder_category_id', 'idx_mars_shareholder_categories_sc_id');
            $table->index('agent_group_id', 'idx_mars_shareholder_categories_agent_group_id');
        });
    }

    public function down(): void
    {
        SchemaMonitor::dropIfExists('foreign_shareholder_categories');
    }
};
