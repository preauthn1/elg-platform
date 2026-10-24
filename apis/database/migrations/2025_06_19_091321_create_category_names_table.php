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
        SchemaMonitor::create('category_names', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('category_id')->index()->comment('categories.id');
            $table->integer('language_id')->index()->comment('languages.id');
            $table->string('name', 50)->comment('name');
            $table->tinyInteger('image_type')->default(0)->comment('圖片類型; 0:url, 1:base64');
            $table->text('image')->nullable()->comment('圖片');
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->comment('更新日期');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('category_names');
    }
};
