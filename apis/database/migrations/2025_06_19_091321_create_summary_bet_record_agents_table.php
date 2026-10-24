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
        SchemaMonitor::create('summary_bet_record_agents', function (Blueprint $table) {
            $table->increments('id');
            $table->dateTime('summary_time');
            $table->integer('agent_id');
            $table->integer('vendor_id')->comment('廠商ID; 大於0為vendor_id, -1:獎勵, -2:v-reward, -3:簡訊, -4:, -5:三層代獎勵');
            $table->integer('game_type_id')->default(0);
            $table->integer('game_id')->nullable();
            $table->decimal('total_bet', 18, 4)->default(0);
            $table->decimal('total_win_lose', 18, 4)->default(0);
            $table->decimal('total_jackpot', 18, 4)->default(0);
            $table->decimal('total_share_amount', 18, 4)->default(0);
            $table->decimal('total_commission', 18, 4)->default(0)->comment('佣金');
            $table->decimal('total_v_jackpot', 18, 4)->default(0);
            $table->decimal('total_cost', 18, 4)->nullable()->default(0);
            $table->decimal('total_score', 18, 4)->default(0)->comment('總咬分');
            $table->decimal('proportion', 18, 4)->default(0)->comment('占成');
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent();
            $table->integer('admin')->default(0);

            $table->unique(['summary_time', 'agent_id', 'vendor_id', 'game_type_id'], 'unique_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('summary_bet_record_agents');
    }
};
