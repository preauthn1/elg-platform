<?php

declare(strict_types=1);

use App\Facades\SchemaMonitor;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

/**
 * 推廣代理 GGR 條件設置主表 工單4124
 *
 * 一筆＝一份「條件設置」，階梯列在 ggr_condition_tiers。
 * 4121 方案以本表 id 為引用契約，不做硬刪（僅 status 停用）。
 *
 * 本功能僅總代使用，且各總代的設置彼此不互見：general_agent_id 為歸屬軸，
 * 列表／檢視／更新一律以操作者的總代 id 過濾。
 */
return new class extends Migration
{
    public function up(): void
    {
        SchemaMonitor::create('ggr_conditions', function (Blueprint $table) {
            $table->comment('推廣代理GGR條件設置主表 工單4124');
            $table->increments('id')->comment('PK流水號(4121引用契約,不硬刪)');
            $table->unsignedInteger('general_agent_id')->comment('所屬總代agents.id(各總代設置不互見,可見性軸)');
            $table->string('name', 100)->comment('GGR條件標題');
            $table->tinyInteger('logic_type')->comment('四項會員門檻的組合方式 1:或者(OR) 2:和(AND),全OR或全AND不混用');
            $table->tinyInteger('active_user_only')->default(0)->comment('僅計算活躍用戶 0:否 1:是(活躍=充值AND有效流水,時間窗=結算週期)');
            $table->tinyInteger('status')->default(1)->comment('狀態 0:停用 1:啟用');
            $table->integer('created_by')->default(0)->comment('建立人員ID');
            $table->integer('admin')->default(0)->comment('更新者ID');
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->comment('更新日期');
            $table->index('general_agent_id', 'idx_ggr_condition_general_agent');
        });
    }

    public function down(): void
    {
        SchemaMonitor::dropIfExists('ggr_conditions');
    }
};
