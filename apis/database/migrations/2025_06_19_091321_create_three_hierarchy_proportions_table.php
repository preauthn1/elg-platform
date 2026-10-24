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
        SchemaMonitor::create('three_hierarchy_proportions', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('agent_group_id')->index()->comment('代理組別ID');
            $table->integer('type')->default(1)->comment('類別 1. 投注額 2. 輸分');
            $table->integer('vendor_id')->index()->comment('遊戲商ID');
            $table->integer('game_type_id')->index()->comment('遊戲類別');
            $table->decimal('proportion_1', 8, 4)->comment('自身返利%數');
            $table->decimal('proportion_2', 8, 4)->comment('上層返利%數');
            $table->decimal('proportion_3', 8, 4)->comment('上上層返利%數');
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->comment('更新日期');
            $table->integer('admin')->default(0)->comment('更新者ID');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('three_hierarchy_proportions');
    }
};
