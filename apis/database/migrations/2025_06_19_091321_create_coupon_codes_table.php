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
        SchemaMonitor::create('coupon_codes', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('batch_id')->index()->comment('批次ID');
            $table->string('code', 32)->unique()->comment('優惠碼');
            $table->dateTime('used_at')->nullable()->comment('使用時間');
            $table->integer('member_id')->default(0)->index()->comment('會員系統編號');
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->comment('更新日期');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('coupon_codes');
    }
};
