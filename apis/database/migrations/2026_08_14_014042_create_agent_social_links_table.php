<?php

declare(strict_types=1);

use App\Facades\SchemaMonitor;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

/**
 * 社群入口配置 target/tickets#4106
 *
 * 前台展示的社群 icon（TG／FB／APK 下載等），點擊後跳轉連結或下載檔案。
 *
 * 綁在「總代」身上（agents.role_id=11）：該總代線路下的所有代理共用同一份設定，
 * 下線代理只能檢視自己歸屬總代的配置，不另外設定。每個總代上限 10 筆（後端驗證）。
 */
return new class extends Migration
{
    public function up(): void
    {
        SchemaMonitor::create('agent_social_links', function (Blueprint $table) {
            $table->bigIncrements('id')->comment('流水號');
            $table->unsignedBigInteger('agent_id')->comment('所屬總代 agents.id (role_id=11)');
            $table->string('name', 50)->comment('顯示名稱');
            $table->tinyInteger('link_type')->default(1)->comment('連結類型 1:網址(點擊跳轉) 2:檔案(點擊下載)');
            $table->string('link_url', 500)->nullable()->comment('連結網址，或上傳後的檔案 URL');
            $table->string('icon_url', 500)->nullable()->comment('前台顯示的 icon 圖片 URL');
            $table->integer('sort')->default(0)->comment('顯示順序，小的在前');
            $table->tinyInteger('status')->default(1)->comment('狀態 0:關閉 1:啟用');
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->comment('更新日期');
            $table->integer('admin')->default(0)->comment('更新者ID');

            // 前台依總代取啟用中的項目並排序，後台列表亦同
            $table->index(['agent_id', 'status', 'sort'], 'idx_agent_social_links_agent_status_sort');
        });
    }

    public function down(): void
    {
        SchemaMonitor::dropIfExists('agent_social_links');
    }
};
