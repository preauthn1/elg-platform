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
        SchemaMonitor::create('advertise_languages', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('advertise_id')->default(0)->comment('廣告ID(advertises.id)');
            $table->integer('language_id')->default(0)->comment('語言ID, 0:全語系, 1~N:參考languages.id');
            $table->integer('advertise_image_id')->default(0)->comment('圖片ID(advertise_images.id)');
            $table->integer('advertise_image_pc_id')->default(0)->comment('PC圖片ID(advertise_images.id)');
            $table->string('title', 200)->nullable()->comment('廣告標題');
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
        SchemaMonitor::dropIfExists('advertise_languages');
    }
};
