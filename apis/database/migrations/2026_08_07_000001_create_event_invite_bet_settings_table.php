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
        SchemaMonitor::create('event_invite_bet_settings', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('condition_type')->comment('設定類別 1:vendor_id, 2:exclude_game_type_id');
            $table->integer('condition_value')->comment('對應的值');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('event_invite_bet_settings');
    }
};
