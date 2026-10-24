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
        SchemaMonitor::create('member_vip_prizes', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('member_id')->default(0)->index()->comment('會員系統編號');
            $table->integer('vip_content_reward_id')->default(0)->index()->comment('VIP獎勵系統編號');
            $table->integer('point_type')->default(1)->comment('1:真金 2:禮碼');
            $table->decimal('base_amount', 18, 4)->default(0)->comment('基數(用來乘上獎勵%數)');
            $table->decimal('point', 18, 4)->default(0)->comment('獎勵金額');
            $table->dateTime('expired_time')->nullable()->comment('過期時間');
            $table->tinyInteger('status')->default(0)->comment('狀態 0:未領取 1:已領取');
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->comment('更新日期');
            $table->integer('admin')->default(0)->comment('更新者ID');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('member_vip_prizes');
    }
};
