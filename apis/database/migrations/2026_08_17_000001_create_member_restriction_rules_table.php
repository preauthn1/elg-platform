<?php

declare(strict_types=1);

use App\Facades\SchemaMonitor;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

return new class extends Migration
{
    public function up(): void
    {
        SchemaMonitor::create('member_restriction_rules', function (Blueprint $table) {
            $table->comment('風控會員限制規則（4118；與舊風險標記表 member_restrictions 無關）');
            $table->increments('id');
            $table->integer('agent_id')->index()->comment('目標所屬代理系統編號, 建立時導出後不可變');
            $table->string('target_type', 20)->comment('限制目標型別 morph alias, member:單一會員 member_group:會員群組');
            $table->unsignedInteger('target_id')->comment('限制目標編號 (members.id 或 member_groups.id)');
            $table->string('remark', 191)->nullable()->comment('內部備註');
            $table->tinyInteger('status')->default(1)->comment('規則總開關 1:啟用 0:關閉');
            $table->tinyInteger('is_disabled')->default(0)->comment('是否停用 1:禁止登入 0:否');
            $table->tinyInteger('withdrawal_locked')->default(0)->comment('提款鎖定 1:禁止申請提現 0:否');
            $table->integer('admin')->default(0)->comment('更新者ID');
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate()->comment('更新日期');

            // 每個目標最多一筆規則；同時是登入/提款 enforcement 的 point lookup 索引
            $table->unique(['target_type', 'target_id']);
        });
    }

    public function down(): void
    {
        SchemaMonitor::dropIfExists('member_restriction_rules');
    }
};
