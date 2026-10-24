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
        SchemaMonitor::create('unlimit_withdrawlog', function (Blueprint $table) {
            $table->increments('id');
            $table->string('order_no', 10)->comment('訂單單號');
            $table->unsignedInteger('member_id')->comment('玩家編號');
            $table->decimal('reward_point', 18)->default(0)->comment('申請前結算金額');
            $table->decimal('withdrawn_point', 18)->default(0)->comment('已提領金額');
            $table->decimal('amount', 12)->default(0)->comment('提領金額');
            $table->decimal('itself_reward_point', 18)->default(0)->comment('申請前自身回水結算金額');
            $table->decimal('itself_withdrawn_point', 18)->default(0)->comment('已提領自身回水金額');
            $table->decimal('itself_amount', 12)->default(0)->comment('提領自身回水金額');
            $table->timestamps();
            $table->tinyInteger('type')->default(0)->comment('支付類型。 0:未發放, 1:已發放、2:拒絕');
            $table->string('remark', 191)->nullable()->comment('備註');
            $table->integer('admin')->nullable()->comment('操作人員');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('unlimit_withdrawlog');
    }
};
