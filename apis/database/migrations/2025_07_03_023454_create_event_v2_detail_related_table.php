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
        SchemaMonitor::create('event_v2_detail_game_types', function (Blueprint $table) {
            $table->unsignedInteger('event_v2_detail_id');
            $table->unsignedInteger('game_type_id');

            $table->primary(['event_v2_detail_id', 'game_type_id']);
            $table->foreign('event_v2_detail_id')->references('id')->on('event_v2_details')->onDelete('cascade');
            $table->foreign('game_type_id')->references('id')->on('game_types')->onDelete('cascade');
        });

        SchemaMonitor::create('event_v2_detail_vendors', function (Blueprint $table) {
            $table->unsignedInteger('event_v2_detail_id');
            $table->unsignedInteger('vendor_id');

            $table->primary(['event_v2_detail_id', 'vendor_id']);
            $table->foreign('event_v2_detail_id')->references('id')->on('event_v2_details')->onDelete('cascade');
            $table->foreign('vendor_id')->references('id')->on('vendors')->onDelete('cascade');
        });

        SchemaMonitor::create('event_v2_detail_exclude_agents', function (Blueprint $table) {
            $table->unsignedInteger('event_v2_detail_id');
            $table->unsignedInteger('agent_id');

            $table->primary(['event_v2_detail_id', 'agent_id']);
            $table->foreign('event_v2_detail_id')->references('id')->on('event_v2_details')->onDelete('cascade');
            $table->foreign('agent_id')->references('id')->on('agents')->onDelete('cascade');
        });

        SchemaMonitor::table('event_v2_detail_langs', function (Blueprint $table) {
            $table->unsignedInteger('event_v2_detail_id')->comment('活動詳細ID')->change();
            $table->unsignedInteger('language_id')->comment('語系iD')->change();

            $table->foreign('event_v2_detail_id')->references('id')->on('event_v2_details')->onDelete('cascade');
            $table->foreign('language_id')->references('id')->on('languages')->onDelete('cascade');
        });

        SchemaMonitor::table('event_v2', function (Blueprint $table) {
            $table->dropColumn('game_type_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('event_v2_detail_game_types');
        SchemaMonitor::dropIfExists('event_v2_detail_vendors');
        SchemaMonitor::dropIfExists('event_v2_detail_exclude_agents');
        SchemaMonitor::table('event_v2_detail_langs', function (Blueprint $table) {
            $table->dropForeign(['event_v2_detail_id']);
            $table->dropForeign(['language_id']);
        });
        SchemaMonitor::table('event_v2', function (Blueprint $table) {
            $table->integer('game_type_id')->nullable()->default(null)->after('schedule')->comment('活動遊戲類型ID');
        });
    }
};
