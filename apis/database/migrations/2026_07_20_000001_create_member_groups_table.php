<?php

declare(strict_types=1);

use App\Facades\SchemaMonitor;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

return new class extends Migration
{
    public function up(): void
    {
        SchemaMonitor::create('member_groups', function (Blueprint $table) {
            $table->comment('會員群組');
            $table->increments('id');
            $table->integer('agent_id')->index()->comment('代理系統編號');
            $table->string('name', 50)->comment('群組名稱');
            $table->string('remark', 191)->nullable()->comment('內部備註');
            $table->integer('sequence')->default(0)->comment('排序');
            $table->tinyInteger('status')->default(1)->comment('1:啟用 0:停用');
            $table->tinyInteger('default_setting')->default(0)->comment('預設群組, 每代理至多一筆');
            $table->tinyInteger('bank_whitelist_enabled')->default(0)->comment('自助存款帳號白名單開關, 0:不限制(忽略白名單) 1:僅允許白名單帳號(可為空清單=完全不可用)');
            $table->decimal('deposit_min', 18, 4)->default(-1)->comment('存款單筆下限, -1:不限制');
            $table->decimal('deposit_max', 18, 4)->default(-1)->comment('存款單筆上限, -1:不限制');
            $table->integer('deposit_daily_count')->default(-1)->comment('存款每日次數, -1:不限制');
            $table->decimal('deposit_daily_amount', 18, 4)->default(-1)->comment('存款每日金額, -1:不限制');
            $table->decimal('withdraw_min', 18, 4)->default(-1)->comment('提款單筆下限, -1:不限制');
            $table->decimal('withdraw_max', 18, 4)->default(-1)->comment('提款單筆上限, -1:不限制');
            $table->integer('withdraw_daily_count')->default(-1)->comment('提款每日次數, -1:不限制');
            $table->decimal('withdraw_daily_amount', 18, 4)->default(-1)->comment('提款每日金額, -1:不限制');
            $table->decimal('transfer_min', 18, 4)->default(-1)->comment('轉帳單筆下限, -1:不限制');
            $table->decimal('transfer_max', 18, 4)->default(-1)->comment('轉帳單筆上限, -1:不限制');
            $table->integer('transfer_daily_count')->default(-1)->comment('轉帳每日次數, -1:不限制');
            $table->decimal('transfer_daily_amount', 18, 4)->default(-1)->comment('轉帳每日金額, -1:不限制');
            $table->integer('admin')->default(0)->comment('更新者ID');
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate()->comment('更新日期');
        });
    }

    public function down(): void
    {
        SchemaMonitor::dropIfExists('member_groups');
    }
};
