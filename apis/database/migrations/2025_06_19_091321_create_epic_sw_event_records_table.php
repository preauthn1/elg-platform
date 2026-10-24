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
        SchemaMonitor::create('epic_sw_event_records', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('operator_id', 20)->default('')->comment('OperatorId');
            $table->string('type', 20)->default('')->comment('注单類型');
            $table->string('tran_id', 25)->default('')->index()->comment('TranId');
            $table->string('event_id', 50)->default('')->index()->comment('BonusId / JackpotId');
            $table->string('event_name', 50)->default('')->comment('BonusName');
            $table->string('username', 50)->default('')->index()->comment('PlayerId');
            $table->decimal('payout_amount', 18, 4)->default(0)->comment('Payout');
            $table->dateTime('tran_date_time');
            $table->string('session_token', 500)->index()->comment('AuthToken');
            $table->string('result', 100)->comment('Result');
            $table->tinyInteger('status')->default(0)->index()->comment('狀態 0:已處理,1:未處理');
            $table->decimal('exchange_rate', 6, 4)->default(0)->comment('ExchangeRate');
            $table->string('currency', 5)->default('')->comment('currency');
            $table->text('info')->comment('response data');
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->index()->comment('更新日期');

            $table->unique(['type', 'tran_id'], 'unique_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('epic_sw_event_records');
    }
};
