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
        SchemaMonitor::create('vip_contents', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('agent_id')->default(0)->index()->comment('代理系統編號');
            $table->integer('level')->default(0)->comment('代理系統編號');
            $table->string('name', 50)->nullable()->comment('VIP名稱');
            $table->decimal('target_amount', 18, 4)->default(0)->comment('所需充值金額');
            $table->decimal('keep_amount', 18, 4)->default(0)->comment('保級金額');
            $table->integer('withdrawal_times_limit')->default(0)->comment('提現次數');
            $table->string('image', 191)->nullable()->comment('圖片');
            $table->decimal('withdrawal_amount_limit', 18, 4)->default(0)->comment('當日提款上限');
            $table->decimal('tax', 18, 4)->default(0)->comment('稅收');
            $table->tinyInteger('vip_channel')->default(0)->comment('支持VIP通道');
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
        SchemaMonitor::dropIfExists('vip_contents');
    }
};
