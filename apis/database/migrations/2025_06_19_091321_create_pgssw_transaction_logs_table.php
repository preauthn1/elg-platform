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
        SchemaMonitor::create('pgssw_transaction_logs', function (Blueprint $table) {
            $table->increments('id');
            $table->string('username', 50)->index()->comment('pgssw_accounts.username');
            $table->string('transaction_no', 50)->unique()->comment('transactionNo');
            $table->string('session_token', 50)->comment('session_token');
            $table->tinyInteger('type')->default(0)->comment('狀態 1:depoist,2:withdraw');
            $table->decimal('amount', 18, 4)->default(0)->comment('轉帳金额');
            $table->integer('transaction_log_id')->default(0)->index()->comment('transaction_log.id');
            $table->decimal('before_amount', 18, 4)->default(0)->comment('啟始金额');
            $table->decimal('after_amount', 18, 4)->default(0)->comment('結束金额');
            $table->tinyInteger('status')->default(0)->comment('狀態 0:待確認,1:成功');
            $table->text('info')->nullable()->comment('request data');
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->comment('更新日期');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('pgssw_transaction_logs');
    }
};
