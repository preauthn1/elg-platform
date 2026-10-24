<?php

declare(strict_types=1);

use App\Facades\SchemaMonitor;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

return new class extends Migration
{
    public function up(): void
    {
        SchemaMonitor::create('available_banks_align_logs', function (Blueprint $table) {
            $table->comment('cash-flow:align-available-banks 校正審計（available_banks BankCode_ELG 對齊 canonical）');
            $table->increments('id');
            $table->string('connection', 50)->nullable()->comment('執行當下的 DB connection（白標）');
            $table->integer('agent_cash_flow_id')->comment('受影響 agent_cash_flows.id');
            $table->integer('cash_flow_id')->comment('金流商系統編號');
            $table->integer('agent_group_id')->comment('代理群組編號');
            $table->integer('currency_id')->nullable()->comment('幣別（null 代表 ACF currency 缺失）');
            $table->string('old_code', 64)->comment('原 BankCode_ELG（drift 值，取自 JSON 原值）');
            $table->string('new_code', 16)->nullable()->comment('對齊後 canonical code（unmatched/conflict 為 NULL）');
            $table->string('action', 30)->comment('align / unmatched / conflict');
            // text 而非 string(255)：conflict 會 implode 多個候選 canonical code，超過 255 會讓 insert 失敗、整筆對齊交易 rollback
            $table->text('detail')->nullable()->comment('補充（unmatched 原因 / conflict 候選碼，可多筆）');
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
        });
    }

    public function down(): void
    {
        SchemaMonitor::dropIfExists('available_banks_align_logs');
    }
};
