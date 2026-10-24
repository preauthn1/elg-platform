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
        SchemaMonitor::create('event_v2_recycles', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('event_v2_user_join_id')->index()->comment('event_v2_user_join_id');
            $table->decimal('final_balance', 18, 4)->nullable()->default(null)->comment('最終餘額');
            $table->decimal('withdraw', 18, 4)->nullable()->default(null)->comment('活動派獎金額');
            $table->decimal('recycle_amount', 18, 4)->nullable()->default(null)->comment('回收金額');
            $table->unsignedTinyInteger('status')->comment('1:系統排程回收2:完成活動回收3:放棄活動回收4:系統重置回收');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('event_v2_recycles');
    }
};
