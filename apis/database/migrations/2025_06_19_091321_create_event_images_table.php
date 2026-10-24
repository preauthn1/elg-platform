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
        SchemaMonitor::create('event_images', function (Blueprint $table) {
            $table->increments('id')->comment('流水號');
            $table->integer('language_id')->comment('語系系統編號');
            $table->integer('event_id')->comment('活動編號');
            $table->string('image', 191)->comment('圖片');
            $table->string('image_pc', 191)->comment('電腦版圖片');
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->comment('更新日期');
            $table->integer('admin')->default(0);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('event_images');
    }
};
