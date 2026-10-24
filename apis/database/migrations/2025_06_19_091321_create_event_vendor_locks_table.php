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
        SchemaMonitor::create('event_vendor_locks', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('event_id')->default(0)->index()->comment('events.id');
            $table->integer('vendor_id')->default(0)->comment('vendors.id');
            $table->integer('game_type_id')->default(0)->comment('game_types.id');
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->comment('更新日期');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('event_vendor_locks');
    }
};
