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
        SchemaMonitor::create('three_hierarchy_bonus_report', function (Blueprint $table) {
            $table->increments('id');
            $table->dateTime('summary_time')->index()->comment('時間(日)');
            $table->integer('rebate_type')->default(1)->comment('類別 1. 投注額 2. 輸分');
            $table->integer('reward_type')->default(2)->index()->comment('1:真金 2:禮碼');
            $table->integer('agent_id')->default(0)->index()->comment('代理系統編號');
            $table->integer('member_id')->default(0)->index()->comment('會員系統編號');
            $table->integer('from_member_id')->default(0)->index()->comment('獎勵來源');
            $table->integer('vendor_id')->default(0)->index()->comment('遊戲系統編號');
            $table->integer('game_type_id')->default(0)->index()->comment('遊戲類別系統編號');
            $table->decimal('bet', 18, 4)->default(0)->comment('投注金額-自己');
            $table->decimal('down_bet', 18, 4)->default(0)->comment('投注金額-下層');
            $table->decimal('down2_bet', 18, 4)->default(0)->comment('投注金額-下下層');
            $table->decimal('rebate', 18, 4)->default(0)->comment('投注反水-自己');
            $table->decimal('down_rebate', 18, 4)->default(0)->comment('投注反水-下層');
            $table->decimal('down2_rebate', 18, 4)->default(0)->comment('投注反水-下下層');
            $table->timestamp('created_at')->useCurrent()->index()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->comment('更新日期');
            $table->integer('admin')->default(0)->comment('更新者ID');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('three_hierarchy_bonus_report');
    }
};
