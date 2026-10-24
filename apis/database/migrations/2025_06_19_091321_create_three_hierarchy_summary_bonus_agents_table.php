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
        SchemaMonitor::create('three_hierarchy_summary_bonus_agents', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('agent_id')->default(0)->index()->comment('會員系統編號');
            $table->decimal('rebate_amount', 18, 4)->default(0)->comment('自身返利');
            $table->decimal('down_rebate_amount', 18, 4)->default(0)->comment('下層返利');
            $table->decimal('down2_rebate_amount', 18, 4)->default(0)->comment('下下層返利');
            $table->decimal('bonus_amount', 18, 4)->default(0)->comment('總獎金');
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->comment('更新日期');
            $table->integer('admin')->default(0)->comment('更新者ID');

            $table->unique(['agent_id'], 'unique_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('three_hierarchy_summary_bonus_agents');
    }
};
