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
        SchemaMonitor::create('platform_announcements', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('agent_id')->default(0)->index()->comment('代理ID(agents.id)');
            $table->integer('exclude_agent_id')->default(0)->index()->comment('排除代理ID');
            $table->dateTime('start_time')->index()->comment('開始時間');
            $table->dateTime('end_time')->index()->comment('結束時間');
            $table->tinyInteger('tag')->default(1)->comment('系統標題類別 1:熱門,2:活動,3:更新,4:遊戲,5:維護');
            $table->tinyInteger('type')->default(0)->comment('連結類型; 0:無連結, 1:公告彈窗, 2:站外連結 3:站內連結');
            $table->longText('title')->nullable()->comment('公告標題');
            $table->longText('content')->nullable()->comment('連結類型內容');
            $table->integer('platform_announcement_image_id')->default(0)->comment('圖片ID(platform_announcement_image.id)');
            $table->boolean('open_window')->default(false)->comment('另開視窗; 0:不另開(h5:該頁面, app:webview), 1:另開(h5:分頁, app:browser)');
            $table->tinyInteger('sort')->default(0)->comment('排序');
            $table->tinyInteger('status')->default(0)->index()->comment('狀態; 0:停用, 1:啟用');
            $table->timestamp('created_at')->useCurrent()->index()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->index()->comment('更新日期');
            $table->integer('admin')->default(0)->comment('操作人員');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('platform_announcements');
    }
};
