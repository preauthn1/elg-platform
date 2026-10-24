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
        SchemaMonitor::create('advertisements', function (Blueprint $table) {
            $table->increments('id')->comment('系統編號');
            $table->integer('path_id')->comment('版位系統編號');
            $table->integer('language_id')->comment('語系系統編號');
            $table->string('country_id', 191)->nullable()->default('0')->comment('國家系統編號(Json格式)');
            $table->string('agent_id', 191)->nullable()->default('0')->comment('代理系統編號(Json格式)');
            $table->string('image', 191)->nullable()->comment('圖片, 如果為空則取遊戲圖片 ');
            $table->string('image_pc', 191)->nullable()->comment('手機版圖片');
            $table->string('url', 191)->nullable()->default('0')->comment('連結網址');
            $table->integer('game_id')->nullable()->default(0)->comment('遊戲編號');
            $table->integer('game_type_id')->default(0)->comment('遊戲類型編號');
            $table->integer('vendor_id')->nullable()->comment('遊戲商編號');
            $table->string('external_url', 191)->nullable()->comment('外部連結');
            $table->integer('event_id')->default(0)->comment('活動編號');
            $table->integer('sort')->comment('排序');
            $table->dateTime('start_time')->comment('開始日期');
            $table->dateTime('end_time')->comment('結束日期');
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
        SchemaMonitor::dropIfExists('advertisements');
    }
};
