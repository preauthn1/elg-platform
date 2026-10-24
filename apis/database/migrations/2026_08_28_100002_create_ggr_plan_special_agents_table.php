<?php

declare(strict_types=1);

use App\Facades\SchemaMonitor;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

/**
 * 推廣代理 GGR 方案的特殊用戶 override 工單4121
 *
 * 一列＝一個被 override 的代理：結算時該代理整組改吃本列綁定的
 * （遊戲商設定＋條件設定），優先於方案主表的成員下線設置。
 * 更新採 transaction 內全量替換（本表 id 不對外引用）。
 */
return new class extends Migration
{
    public function up(): void
    {
        SchemaMonitor::create('ggr_plan_special_agents', function (Blueprint $table) {
            $table->comment('推廣代理GGR方案特殊用戶override 工單4121');
            $table->increments('id')->comment('PK流水號(不對外引用,update全量替換)');
            $table->unsignedInteger('plan_id')->comment('ggr_plans.id');
            $table->unsignedInteger('agent_id')->comment('被override的代理agents.id(須在總代線內)');
            $table->unsignedInteger('vendor_setting_id')->comment('override的ggr_vendor_settings.id');
            $table->unsignedInteger('condition_id')->comment('override的ggr_conditions.id');
            $table->integer('admin')->default(0)->comment('更新者ID');
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->comment('更新日期');
            $table->unique(['plan_id', 'agent_id'], 'uk_ggr_plan_special_agent');
        });
    }

    public function down(): void
    {
        SchemaMonitor::dropIfExists('ggr_plan_special_agents');
    }
};
