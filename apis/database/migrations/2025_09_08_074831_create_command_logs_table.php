<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('command_logs', function (Blueprint $table) {
            $table->id();
            $table->date('day')->index();
            $table->string('command', 50)->index();
            $table->string('input', 100)->index();
            $table->tinyInteger('status')->default(0)->index();
            $table->dateTime('start_time');
            $table->dateTime('end_time')->nullable();
            $table->decimal('usages', 8, 5)->nullable();
            $table->string('cronjob', 20)->nullable()->index();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('command_logs');
    }
};
