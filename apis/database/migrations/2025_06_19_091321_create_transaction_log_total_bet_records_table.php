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
        SchemaMonitor::create('transaction_log_total_bet_records', function (Blueprint $table) {
            $table->increments('id');
            $table->bigInteger('transaction_log_id')->default(0)->index()->comment('transaction_logs.id');
            $table->bigInteger('total_bet_record_id')->default(0)->comment('total_bet_records.id');
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->comment('更新日期');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('transaction_log_total_bet_records');
    }
};
