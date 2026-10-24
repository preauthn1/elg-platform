<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $table_name = 'profit_share_settings';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasTable($this->table_name)) {
            return;
        }
        Schema::create($this->table_name, function (Blueprint $table) {
            $table->increments('id')->comment('流水號');
            $table->dateTime('summary_time')->index('idx_profit_share_setting_summary_time')->comment('彙總時間(UTC)');
            $table->integer('agent_group_id')->index('idx_profit_share_setting_agent_group_id')->comment('代理組別ID');
            $table->integer('max_share_point')->index('idx_profit_share_setting_max_share_point')->comment('最大股份');
            $table->integer('min_share_point')->index('idx_profit_share_setting_min_share_point')->comment('最小股份');
            $table->integer('direct_percentage')->comment('直屬佔成');
            $table->integer('sub_percentage')->comment('下級佔成');
            $table->integer('sub_sub_percentage')->comment('下下級佔成');
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->comment('更新日期');
            $table->integer('admin')->default(0);
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
