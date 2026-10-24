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
        SchemaMonitor::create('newbie_challenge_config_exclude_agents', function (Blueprint $table) {
            $table->unsignedBigInteger('config_id');
            $table->unsignedInteger('agent_id');

            $table->primary(['config_id', 'agent_id']);
            $table->foreign('config_id')->references('id')->on('newbie_challenge_configs')->onDelete('cascade');
            $table->foreign('agent_id')->references('id')->on('agents')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('newbie_challenge_config_exclude_agents');
    }
};
