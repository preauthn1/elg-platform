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
        SchemaMonitor::create('announcements', function (Blueprint $table) {
            $table->increments('id');
            $table->tinyInteger('type')->comment('公告類別，1:一般 2:遊戲商 3:緊急 4:重要');
            $table->text('content')->comment('公告內容');
            $table->tinyInteger('is_top')->default(0)->comment('是否置頂, 0:否, 1:是');
            $table->tinyInteger('status')->default(0);
            $table->timestamps();
            $table->integer('admin')->comment('更新者ID');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('announcements');
    }
};
