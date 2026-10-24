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
        SchemaMonitor::create('templates', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name', 191)->comment('模版名稱');
            $table->string('thumbnail', 191)->comment('模版縮圖');
            $table->string('memo', 191)->nullable()->comment('紀錄是甚麼顏色的皮');
            $table->tinyInteger('status')->default(0)->comment('狀態:0停用,1正常');
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
        SchemaMonitor::dropIfExists('templates');
    }
};
