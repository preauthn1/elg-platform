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
        SchemaMonitor::create('great_wall_transactions', function (Blueprint $table) {
            $table->increments('id')->comment('流水號');
            $table->string('externalTransactionId', 191)->nullable()->comment('GW99系統編號');
            $table->tinyInteger('operationMethod')->default(0)->index()->comment('執行動作，1: open score, 2: shuffle score, 3: Red envelope (Lucky bag, New Year gift)');
            $table->string('playerAccount', 191)->nullable()->index()->comment('玩家帳號');
            $table->decimal('transAmount', 18, 4)->comment('金額');
            $table->string('remark', 191)->nullable()->comment('備註');
            $table->tinyInteger('result')->default(0)->comment('狀態，0 : unresolved, 1 : success, 2 : failure');
            $table->string('createTime', 191)->nullable()->comment('建立時間(+8時區)');
            $table->dateTime('utc_created_time')->nullable()->index()->comment('建立時間(+0時區)');
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->comment('更新日期');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('great_wall_transactions');
    }
};
