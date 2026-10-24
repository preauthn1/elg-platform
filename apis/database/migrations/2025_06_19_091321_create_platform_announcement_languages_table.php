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
        SchemaMonitor::create('platform_announcement_languages', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('platform_announcement_id')->default(0)->index()->comment('平台ID');
            $table->integer('language_id')->default(0)->comment('語言ID, 0:全語系, 1~N:參考languages.id');
            $table->text('title')->nullable()->comment('標題');
            $table->text('content')->nullable()->comment('內容');
            $table->integer('platform_announcement_image_id')->default(0)->comment('圖片ID');
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->comment('更新日期');
            $table->integer('admin')->default(0)->comment('操作人員');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('platform_announcement_languages');
    }
};
