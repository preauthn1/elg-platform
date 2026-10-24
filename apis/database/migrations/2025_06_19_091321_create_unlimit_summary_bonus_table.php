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
        SchemaMonitor::create('unlimit_summary_bonus', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('member_id')->default(0)->unique()->comment('玩家編號');
            $table->decimal('performance_amount', 18, 4)->default(0)->comment('業績金額');
            $table->decimal('bonus_amount', 18, 4)->default(0)->comment('未提領獎金');
            $table->decimal('withdraw_amount', 18, 4)->default(0)->comment('已提領獎金');
            $table->decimal('flow_amount', 18, 4)->default(0)->comment('總流水');
            $table->decimal('itself_bonus_amount', 18, 4)->default(0)->comment('自身回水獎金');
            $table->decimal('itself_withdraw_amount', 18, 4)->default(0)->comment('已提領自身回水獎金');
            $table->timestamp('created_at')->nullable()->useCurrent();
            $table->timestamp('updated_at')->useCurrentOnUpdate()->nullable()->useCurrent();
            $table->integer('admin')->default(0)->comment('更新者ID');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('unlimit_summary_bonus');
    }
};
