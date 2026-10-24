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
        SchemaMonitor::create('member_vip_exp_logs', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('member_id')->index()->comment('會員系統編號');
            $table->integer('vip_flow_limit_id')->comment('vip_flow_limits.id');
            $table->decimal('exp', 18, 4)->default(0)->comment('經驗值變動量');
            $table->decimal('before_deposit_amount', 18, 4)->default(0)->comment('變動前已充值金額');
            $table->decimal('after_deposit_amount', 18, 4)->default(0)->comment('變動後已充值金額');
            $table->decimal('before_keep_amount', 18, 4)->default(0)->comment('變動前累積保級金額');
            $table->decimal('after_keep_amount', 18, 4)->default(0)->comment('變動後累積保級金額');
            $table->timestamp('created_at')->useCurrent()->index()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->comment('更新日期');
            $table->integer('admin')->default(0)->comment('操作者ID');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('member_vip_exp_logs');
    }
};
