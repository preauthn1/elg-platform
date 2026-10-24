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
        SchemaMonitor::create('w_jackpot_tickets', function (Blueprint $table) {
            $table->comment('wjackpot抽獎券');
            $table->increments('id');
            $table->integer('w_jackpot_id')->index()->comment('w_jackpots.id');
            $table->integer('member_id')->index()->comment('玩家ID');
            $table->string('ticket_number', 20)->default('')->comment('抽獎券號碼');
            $table->integer('reward_item_id')->default(0)->index()->comment('獎項ID');
            $table->string('reward_item_ids', 50)->nullable()->comment('獎項ID');
            $table->json('info')->nullable();
            $table->decimal('point', 18, 4)->nullable()->comment('獎勵金額');
            $table->integer('type')->default(0)->comment('中獎結果; 0:未中 , 1:中獎, 2:派發中(因wjackpot可重複獲獎, 所以增加這狀態)');
            $table->integer('status')->default(0)->comment('提領結果; 0:未領 , 1:已領取');
            $table->dateTime('time')->nullable()->comment('領取時間');
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
        SchemaMonitor::dropIfExists('w_jackpot_tickets');
    }
};
