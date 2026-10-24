<?php

declare(strict_types=1);

use App\Facades\SchemaMonitor;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

return new class extends Migration
{
    public function up(): void
    {
        SchemaMonitor::create('bank_dedup_logs', function (Blueprint $table) {
            $table->comment('bank:dedup-active 清理審計（active 重複銀行合併紀錄）');
            $table->increments('id');
            $table->string('connection', 50)->nullable()->comment('執行當下的 DB connection（白標）');
            $table->integer('agent_group_id')->comment('代理群組編號');
            $table->string('code', 16)->comment('銀行代碼');
            $table->integer('survivor_bank_id')->comment('保留的 banks.id');
            $table->integer('loser_bank_id')->comment('淘汰的 banks.id');
            $table->integer('member_id')->nullable()->comment('受影響會員（banks 停用列為 NULL）');
            $table->integer('member_bank_account_id')->nullable()->comment('受影響 member_bank_accounts.id');
            $table->string('action', 30)->comment('repoint / deactivate_binding / deactivate_bank');
            $table->string('detail', 255)->nullable()->comment('補充說明（如同步出款訂單筆數）');
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
        });
    }

    public function down(): void
    {
        SchemaMonitor::dropIfExists('bank_dedup_logs');
    }
};
