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
        SchemaMonitor::create('xe88_daily_wins', function (Blueprint $table) {
            $table->increments('id')->comment('流水號');
            $table->date('date')->nullable()->index()->comment('日期');
            $table->string('username', 191)->nullable()->index()->comment('玩家帳號');
            $table->decimal('bets', 13, 7)->comment('總投注金額');
            $table->decimal('wins', 13, 7)->comment('總贏得金額');
            $table->decimal('turnover', 13, 7)->comment('進出金額');
            $table->decimal('houseearnings', 13, 7)->comment('總輸贏');
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->comment('更新日期');
            $table->integer('admin')->default(0)->comment('更新者ID');

            $table->unique(['date', 'username'], 'unique_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('xe88_daily_wins');
    }
};
