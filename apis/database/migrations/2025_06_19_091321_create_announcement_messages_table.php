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
        SchemaMonitor::create('announcement_messages', function (Blueprint $table) {
            $table->comment('群發公告訊息');
            $table->increments('id');
            $table->string('title', 50)->comment('標題');
            $table->string('message', 1000)->comment('訊息內容');
            $table->string('memo', 100)->comment('備註');
            $table->integer('status')->default(1)->comment('狀態，1:開啟  0:刪除關閉');
            $table->integer('organization_id')->comment('所屬組織');
            $table->dateTime('start_time')->nullable()->comment('顯示起始時間');
            $table->dateTime('end_time')->nullable()->comment('顯示結束時間');
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->integer('created_by')->comment('建立人');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->comment('更新日期');
            $table->integer('updated_by')->comment('修改人');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('announcement_messages');
    }
};
