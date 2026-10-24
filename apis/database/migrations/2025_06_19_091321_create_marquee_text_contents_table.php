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
        SchemaMonitor::create('marquee_text_contents', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('marquee_text_id')->index()->comment('文字跑馬燈id');
            $table->integer('language_id')->index()->comment('語系ID');
            $table->string('content', 191)->comment('跑馬燈內容');
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
        SchemaMonitor::dropIfExists('marquee_text_contents');
    }
};
