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
        SchemaMonitor::create('member_vips', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('member_id')->default(0)->index()->comment('會員系統編號');
            $table->integer('vip_content_id')->default(0)->comment('VIP系統編號');
            $table->decimal('deposit_amount', 18, 4)->default(0)->comment('已充值金額');
            $table->decimal('keep_amount', 18, 4)->default(0)->comment('累積保級金額');
            $table->date('birthday')->nullable()->comment('生日');
            $table->date('target_date')->nullable()->comment('達標日期');
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
        SchemaMonitor::dropIfExists('member_vips');
    }
};
