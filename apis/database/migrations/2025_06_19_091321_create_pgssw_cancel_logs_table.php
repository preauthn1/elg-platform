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
        SchemaMonitor::create('pgssw_cancel_logs', function (Blueprint $table) {
            $table->increments('id');
            $table->string('username', 50)->index()->comment('pgssw_accounts.username');
            $table->string('transaction_no', 50)->unique()->comment('transactionNo');
            $table->integer('game_id')->default(0)->comment('投注游戏');
            $table->tinyInteger('status')->default(0)->comment('處理狀態 0:待處理,1:成功,2:失敗');
            $table->text('response_data')->nullable()->comment('response data');
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->comment('更新日期');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('pgssw_cancel_logs');
    }
};
