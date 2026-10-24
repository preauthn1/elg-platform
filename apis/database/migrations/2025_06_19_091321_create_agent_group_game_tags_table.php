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
        SchemaMonitor::create('agent_group_game_tags', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('agent_group_id')->index()->comment('遊戲商群組ID(vendor_customize_groups.id)');
            $table->integer('game_id')->index()->comment('遊戲ID(games.id)');
            $table->integer('agent_group_tag_id')->index()->comment('遊戲商群組ID(agent_group_tags.id)');
            $table->integer('sort')->comment('排序');
            $table->tinyInteger('status')->default(0)->comment('狀態 0:停用,1:使用中');
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('agent_group_game_tags');
    }
};
