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
        SchemaMonitor::create('wallet_transaction_log_agents', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('wallet_type_id')->default(0)->index()->comment('錢包類別ID');
            $table->integer('agent_id')->default(0)->index()->comment('agents.id');
            $table->integer('type')->default(0)->comment('類別');
            $table->decimal('amount', 18, 4)->default(0)->comment('金額');
            $table->decimal('before_balance', 18, 4)->default(0)->comment('變更前金額');
            $table->decimal('balance', 18, 4)->default(0)->comment('變更後金額');
            $table->integer('operator')->default(0)->comment('agents.id(操作人)');
            $table->decimal('operator_before_balance', 18, 4)->default(0)->comment('變更前金額(操作人)');
            $table->decimal('operator_balance', 18, 4)->default(0)->comment('變更後金額(操作人)');
            $table->string('remark', 300)->comment('備註');
            $table->integer('status')->default(0)->comment('狀態; 0: 失敗, 1: 成功');
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->comment('更新日期');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('wallet_transaction_log_agents');
    }
};
