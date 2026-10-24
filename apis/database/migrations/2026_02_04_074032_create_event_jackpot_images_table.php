<?php

declare(strict_types=1);

use App\Facades\SchemaMonitor;
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
        SchemaMonitor::create('event_jackpot_images', function (Blueprint $table) {
            $table->id();
            $table->integer('language_id')->default(0)->comment('languages.id');
            $table->integer('event_jackpot_id')->default(0)->comment('event_jackpots.id');
            $table->string('image', 191)->comment('圖片');
            $table->timestamp('created_at')->default(DB::raw('CURRENT_TIMESTAMP'));
            $table->timestamp('updated_at')->default(DB::raw('CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP'));

            $table->index('language_id');
            $table->index('event_jackpot_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('event_jackpot_images');
    }
};
