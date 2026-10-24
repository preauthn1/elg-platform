<?php

declare(strict_types=1);

use App\Facades\SchemaMonitor;
use App\Models\PlatformAnnouncement;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        SchemaMonitor::create('platform_announcement_include_agents', function (Blueprint $table) {
            $table->unsignedInteger('platform_announcement_id');
            $table->unsignedInteger('agent_id');

            $table->primary(['platform_announcement_id', 'agent_id']);
            $table->foreign('platform_announcement_id', 'pa_include_announcement_id_foreign')->references('id')->on('platform_announcements')->onDelete('cascade');
            $table->foreign('agent_id')->references('id')->on('agents')->onDelete('cascade');
        });

        $data = PlatformAnnouncement::select('platform_announcements.id as platform_announcement_id', 'platform_announcements.agent_id')
            ->join('agents', 'platform_announcements.agent_id', '=', 'agents.id')
            ->get()
            ->toArray();

        DB::table('platform_announcement_include_agents')->insert($data);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('platform_announcement_include_agents');
    }
};
