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
        SchemaMonitor::create('language_texts', function (Blueprint $table) {
            $table->comment('綜合性的多語系文字定義');
            $table->increments('id');
            $table->string('language_text_code', 191)->comment('多語系對應代碼');
            $table->integer('language_id')->comment('語言代碼');
            $table->string('text', 1000)->comment('多語系文字');
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->comment('更新日期');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('language_texts');
    }
};
