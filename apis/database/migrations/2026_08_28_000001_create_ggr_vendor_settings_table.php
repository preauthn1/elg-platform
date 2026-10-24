<?php

declare(strict_types=1);

use App\Facades\SchemaMonitor;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

/**
 * 推廣代理 GGR 遊戲商設置主表 工單4123
 *
 * 一筆＝一份「遊戲商設置」，明細（vendor_info × game_type × 階層）在
 * ggr_vendor_setting_details。4121 方案的成員下線設置／特殊用戶設定
 * 以本表 id 為引用契約，因此本表**不做硬刪**（僅 status 停用），
 * 明細採全量替換也不影響此 id。
 *
 * 本功能僅總代使用，且各總代的設置彼此不互見：general_agent_id 為歸屬軸，
 * 列表／檢視／更新一律以操作者的總代 id 過濾。
 */
return new class extends Migration
{
    public function up(): void
    {
        SchemaMonitor::create('ggr_vendor_settings', function (Blueprint $table) {
            $table->comment('推廣代理GGR遊戲商設置主表 工單4123');
            $table->increments('id')->comment('PK流水號(4121引用契約,不硬刪)');
            $table->unsignedInteger('general_agent_id')->comment('所屬總代agents.id(各總代設置不互見,可見性軸)');
            $table->string('name', 100)->comment('設置名稱');
            $table->tinyInteger('status')->default(1)->comment('狀態 0:停用 1:啟用(停用後4121不可新選,既有引用不受影響)');
            $table->integer('created_by')->default(0)->comment('建立人員ID');
            $table->integer('admin')->default(0)->comment('更新者ID');
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->comment('更新日期');
            $table->index('general_agent_id', 'idx_ggr_vendor_setting_general_agent');
        });
    }

    public function down(): void
    {
        SchemaMonitor::dropIfExists('ggr_vendor_settings');
    }
};
