<?php

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
        Schema::create('chat_language_settings', function (Blueprint $table) {
            $table->id();
            $table->string('bot_id', 100);
            $table->string('chat_id', 100);
            $table->string('language', 10)->default('en-us');
            $table->timestamps();

            $table->unique(['bot_id', 'chat_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('chat_language_settings');
    }
};
