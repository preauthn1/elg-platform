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
        SchemaMonitor::create('banners', function (Blueprint $table) {
            $table->increments('id')->comment('系統編號');
            $table->integer('type_id')->comment('類型');
            $table->string('photo', 191)->comment('圖片');
            $table->string('url', 191)->comment('連結位置');
            $table->dateTime('start_time')->comment('開始日期');
            $table->dateTime('end_time')->comment('結束日期');
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->comment('更新日期');
            $table->integer('admin')->comment('更新者ID');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('banners');
    }
};
