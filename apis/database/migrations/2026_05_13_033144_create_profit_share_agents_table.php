<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $table_name = 'profit_share_agents';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasTable($this->table_name)) {
            return;
        }
        Schema::create($this->table_name, function (Blueprint $table) {
            $table->comment('代理佔成設定');
            $table->increments('id')->comment('系統編號');
            $table->integer('shareholder_category_id')->index('idx_profit_sharing_agents_shareholder_category_id')->comment('份桶類別ID');
            $table->integer('agent_id')->nullable()->index('idx_profit_sharing_agents_agent_id')->comment('代理系統編號(agents)');
            $table->integer('direct_percentage')->nullable()->default(0)->comment('直屬百分比');
            $table->integer('team_percentage')->nullable()->default(0)->comment('下級百分比');
            $table->tinyInteger('status')->default(0)->index('idx_profit_sharing_agents_status')->comment('狀態 0:待啟用,1:啟用,2:停用');
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->comment('更新日期');
            $table->integer('admin')->default(51)->comment('更新者ID');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists($this->table_name);
    }
};
