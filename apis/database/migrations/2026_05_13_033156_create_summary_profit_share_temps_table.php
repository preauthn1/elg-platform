<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $table_name = 'summary_profit_share_temps';

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
            $table->dateTime('summary_time')->index('idx_summary_profit_share_temp_summary_time')->comment('彙總時間(UTC)');
            $table->integer('agent_group_id')->default(0)->index('idx_summary_profit_share_temp_agent_group_id');
            $table->integer('shareholder_category_id')->default(0)->index('idx_summary_profit_share_temp_shareholder_category_id');
            $table->text('setting')->nullable()->comment('佔成設定(8201)');
            $table->decimal('turnover', 18, 4)->default(0)->comment('總有效流水');
            $table->decimal('original_turnover', 18, 4)->default(0)->comment('原總有效流水');
            $table->decimal('nett_profit', 18, 4)->default(0)->comment('總淨利潤');
            $table->decimal('original_nett_profit', 18, 4)->default(0)->comment('原總淨利潤');
            $table->decimal('net_win', 18, 4)->default(0)->comment('正收益總業績');
            $table->decimal('original_net_win', 18, 4)->default(0)->comment('原正收益總業績');
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->comment('更新日期');
            $table->integer('admin')->default(0);

            $table->unique(['summary_time', 'agent_group_id', 'shareholder_category_id'], 'uni_summary_profit_share_temps');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('summary_profit_share_temps');
    }
};
