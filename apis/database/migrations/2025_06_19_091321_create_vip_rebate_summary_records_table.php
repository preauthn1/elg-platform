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
        SchemaMonitor::create('vip_rebate_summary_records', function (Blueprint $table) {
            $table->increments('id');
            $table->timestamp('summary_start_time')->useCurrent()->comment('計算時間區間-開始');
            $table->timestamp('summary_end_time')->useCurrent()->comment('計算時間區間-結束');
            $table->integer('member_id')->default(0)->index()->comment('members.id');
            $table->integer('game_type_id')->default(0)->comment('game_types.id');
            $table->decimal('bet', 18, 4)->default(0)->comment('投注金額');
            $table->decimal('proportion', 8, 4)->default(0)->comment('返水比例');
            $table->decimal('rebate_amount', 18, 4)->default(0)->comment('返水金額');
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('vip_rebate_summary_records');
    }
};
