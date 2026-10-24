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
        SchemaMonitor::create('advertisement_paths', function (Blueprint $table) {
            $table->increments('id')->comment('系統編號');
            $table->string('name', 191)->comment('類別名稱');
            $table->integer('column')->comment('一列幾欄');
            $table->integer('width')->comment('圖片寬度');
            $table->integer('height')->comment('圖片高度');
            $table->tinyInteger('status')->default(0)->comment('狀態 0:停用,1:啟用');
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
        SchemaMonitor::dropIfExists('advertisement_paths');
    }
};
