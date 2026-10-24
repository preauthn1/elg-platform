<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $table_name = 'profit_share_payout_logs';

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
            $table->dateTime('summary_time')->index('idx_profit_share_payout_log_summary_time')->comment('日期');
            $table->integer('agent_group_id')->default(0)->index('idx_profit_share_payout_log_agent_group_id')->comment('代理組別編號');
            $table->tinyInteger('status')->index('idx_profit_share_payout_log_status')->comment('0:未派發, 1:已派發, 2:待派發, 3:已編輯, 4:已結算');
            $table->text('memo')->nullable();
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
