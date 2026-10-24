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
        SchemaMonitor::create('telegram_broadcast_messages', function (Blueprint $table) {
            $table->increments('id')->comment('流水號');
            $table->integer('brand_id')->default(0)->comment('brand id');
            $table->enum('type', ['text', 'image', 'animation', 'audio', 'voice'])->comment('類別');
            $table->text('message')->comment('訊息內文(多國語系JSON)');
            $table->integer('status')->default(0)->comment('狀態: 0 發送中, 1 暫停發送, 2 發送完畢');
            $table->string('remark', 191)->comment('備註');
            $table->dateTime('created_at')->useCurrent()->index()->comment('建立日期');
            $table->dateTime('updated_at')->useCurrentOnUpdate()->useCurrent()->comment('更新日期');
            $table->integer('admin')->comment('更新者ID');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('telegram_broadcast_messages');
    }
};
