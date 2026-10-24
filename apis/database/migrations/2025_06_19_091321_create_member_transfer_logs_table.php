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
        SchemaMonitor::create('member_transfer_logs', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('member_id')->default(0)->index()->comment('members.id');
            $table->integer('transaction_log_id')->default(0)->comment('transaction_logs.id(轉入)');
            $table->decimal('amount', 18, 4)->default(0)->comment('金額');
            $table->decimal('tax', 18, 4)->default(0)->comment('稅收');
            $table->integer('operator')->default(0)->comment('members.id(操作人)');
            $table->integer('operator_transaction_log_id')->default(0)->comment('transaction_logs.id(轉出)');
            $table->string('remark', 300)->nullable()->comment('備註');
            $table->integer('status')->default(0)->comment('狀態; 0: 失敗, 1000: 成功(對齊舊系統與SR07T判定值)');
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->comment('更新日期');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('member_transfer_logs');
    }
};
