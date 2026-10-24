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
        SchemaMonitor::create('w_jackpot_histories', function (Blueprint $table) {
            $table->comment('W-Jackpot活動明細');
            $table->increments('id');
            $table->integer('w_jackpot_id')->default(0)->index()->comment('agents.id');
            $table->integer('agent_id')->default(0)->index()->comment('agents.id');
            $table->decimal('total_pool_amount', 18, 4)->default(0)->comment('總彩池數量');
            $table->integer('total_ticket')->nullable()->comment('發出獎券數');
            $table->decimal('total_bonus', 18, 4)->default(0)->comment('發出獎金');
            $table->string('prizes', 200)->nullable();
            $table->string('remark', 100)->nullable();
            $table->integer('status')->default(0);
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->comment('更新日期');
            $table->integer('admin')->default(0);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('w_jackpot_histories');
    }
};
