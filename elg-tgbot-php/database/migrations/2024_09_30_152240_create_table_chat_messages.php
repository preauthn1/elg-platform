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
        Schema::create('chat_messages', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('ref_id')->nullable()->default(null)->comment('reference id');
            $table->string('bot_id');
            $table->string('chat_id');
            $table->string('message_id');
            $table->string('sender')->comment('Who send this message, server or user');
            $table->string('type')->comment('message type, text or image or animation or ...');
            $table->string('action')->nullable()->comment('message action, callback action or text or reply');
            $table->string('status')->comment('message status, done or pending or failed or dropped');
            $table->text('content')->nullable()->default(null)->comment('message content');
            $table->json('info');
            $table->json('extra_info')->nullable()->default(null)->comment('telegram bot api request data');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('chat_messages');
    }
};
