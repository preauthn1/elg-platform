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
        SchemaMonitor::create('telegram_messages', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('chat_id');
            $table->string('type', 10)->nullable()->default('ERROR')->comment('頻道類型; ERROR, WARN, INFO, SUMMARY, ...');
            $table->string('parse_mode', 10)->nullable()->default('')->comment('模式; MARKDOWN, HTML');
            $table->text('message');
            $table->boolean('status')->default(false);
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->comment('更新日期');
            $table->integer('admin')->nullable()->default(0);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('telegram_messages');
    }
};
