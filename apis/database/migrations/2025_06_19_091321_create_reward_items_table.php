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
        SchemaMonitor::create('reward_items', function (Blueprint $table) {
            $table->comment('奖励项目');
            $table->increments('id');
            $table->integer('prize_content_id')->default(0);
            $table->string('name', 50);
            $table->tinyInteger('level')->default(0);
            $table->boolean('type')->default(true)->comment('獎項類型; 1: 獎金, 2:獎品');
            $table->decimal('bonus', 18, 4)->default(0);
            $table->string('prize_name', 50)->default('')->comment('獎品名稱');
            $table->string('memo', 191)->nullable();
            $table->boolean('status')->nullable()->default(false)->comment('狀態; 1:啟用, 0:停用');
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->comment('更新日期');
            $table->integer('admin')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('reward_items');
    }
};
