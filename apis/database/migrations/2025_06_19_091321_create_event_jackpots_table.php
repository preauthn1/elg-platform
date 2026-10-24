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
        SchemaMonitor::create('event_jackpots', function (Blueprint $table) {
            $table->increments('id')->comment('流水號');
            $table->integer('event_id')->default(0)->index()->comment('活動編號');
            $table->integer('type')->default(0)->comment('1:流水,2:輸分金額');
            $table->dateTime('start_time')->index()->comment('開始日期');
            $table->dateTime('end_time')->index()->comment('結束日期');
            $table->dateTime('expire_date')->comment('獎勵遊戲次數到期日');
            $table->integer('expire_hour')->default(0)->comment('獎勵領取期限(小時)');
            $table->decimal('achieve_point', 10)->default(0)->comment('達成分數');
            $table->integer('member_number')->default(0)->comment('最低符合資格玩家數');
            $table->integer('agent_number')->default(0)->comment('最低符合資格代理數');
            $table->integer('pay_number')->default(0)->comment('派發獎勵人數');
            $table->integer('number_of_member_in_agent')->default(0)->comment('每個代理可中獎人數');
            $table->decimal('point', 10)->default(0)->comment('獎勵金額');
            $table->decimal('flow_limit_multiple', 10)->default(0)->comment('稽核流水倍數');
            $table->string('image', 191)->nullable()->comment('圖片');
            $table->integer('sort')->default(0)->comment('排序');
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
        SchemaMonitor::dropIfExists('event_jackpots');
    }
};
