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
        SchemaMonitor::create('event_invite_exclude_agents', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('event_invite_setting_id')->index()->comment('event_invite_settings.id');
            $table->unsignedInteger('agent_id')->index()->comment('agents.id');
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->comment('更新日期');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('event_invite_exclude_agents');
    }
};
