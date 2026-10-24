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
        SchemaMonitor::create('brands', function (Blueprint $table) {
            $table->comment('TGBOT 商家');
            $table->increments('id');
            $table->string('name', 30)->comment('brand name');
            $table->string('code', 100)->unique()->comment('brand code');
            $table->string('bot_id', 100)->comment('tg bot id');
            $table->string('bot_token', 100)->comment('tg bot token');
            $table->string('remark', 500)->comment('備註');
            $table->tinyInteger('status')->default(0)->comment('狀態; 0:停用, 1:啟用');
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->comment('更新日期');
            $table->integer('admin')->default(0)->comment('更新者ID');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('brands');
    }
};
