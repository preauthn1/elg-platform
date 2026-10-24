<?php

declare(strict_types=1);

use App\Facades\SchemaMonitor;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasTable('coupon_batch_prizes') === false) {
            SchemaMonitor::create('coupon_batch_prizes', function (Blueprint $table) {
                $table->increments('id');
                $table->integer('batch_id')->index()->comment('批次ID');
                $table->tinyInteger('point_type')->comment('獎勵類型; 1:平台點數, 2:禮碼');
                $table->decimal('amount', 18, 4)->default(0)->comment('獎勵金額');
                $table->decimal('weight', 18, 4)->default(0)->comment('中獎權重');
                $table->integer('quantity')->default(0)->comment('獎品數量; 0:無限制');
                $table->integer('awarded')->default(0)->comment('已派發數量');
                $table->integer('flow_limit_multiple')->default(0)->comment('稽核流水倍數');
                $table->integer('sort')->default(0)->comment('排序');
                $table->timestamp('created_at')->useCurrent()->comment('建立日期');
                $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->comment('更新日期');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('coupon_batch_prizes');
    }
};
