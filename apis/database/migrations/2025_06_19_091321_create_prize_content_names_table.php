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
        SchemaMonitor::create('prize_content_names', function (Blueprint $table) {
            $table->increments('id')->comment('流水號');
            $table->integer('language_id')->default(0)->comment('語言系統編號');
            $table->integer('prize_content_id')->comment('獎項編號');
            $table->string('name', 100)->comment('獎項名稱');
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
        SchemaMonitor::dropIfExists('prize_content_names');
    }
};
