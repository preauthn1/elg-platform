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
        SchemaMonitor::create('tthsw_deal_records', function (Blueprint $table) {
            $table->increments('id');
            $table->string('game_code')->nullable()->index()->comment('遊戲代碼');
            $table->string('game_round_id')->index()->comment('Round Id');
            $table->string('transaction_id')->comment('Transaction Id');
            $table->dateTime('transaction_date')->comment('TransactionDate');
            $table->decimal('amount', 18, 4)->default(0)->comment('金額');
            $table->tinyInteger('type')->default(0)->comment('Type');
            $table->string('action')->comment('請求動作');
            $table->text('info')->nullable()->comment('Response data');
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->index()->comment('更新日期');

            $table->unique(['game_round_id', 'transaction_id', 'type'], 'uniq_game_round_transaction_type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('tthsw_deal_records');
    }
};
