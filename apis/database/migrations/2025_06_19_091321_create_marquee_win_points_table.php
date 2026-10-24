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
        SchemaMonitor::create('marquee_win_points', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('agent_id')->comment('代理id');
            $table->integer('exclude_agent_id')->comment('排除代理id');
            $table->integer('vendor_id')->comment('遊戲商');
            $table->decimal('trigger_amount', 18, 4)->default(0)->comment('觸發金額');
            $table->tinyInteger('status')->default(0);
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->comment('更新日期');
            $table->integer('admin')->comment('更新者ID');

            $table->unique(['agent_id', 'vendor_id'], 'unique_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('marquee_win_points');
    }
};
