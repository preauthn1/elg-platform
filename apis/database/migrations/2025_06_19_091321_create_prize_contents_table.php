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
        SchemaMonitor::create('prize_contents', function (Blueprint $table) {
            $table->increments('id')->comment('流水號');
            $table->integer('prize_id')->index()->comment('獎勵系統編號');
            $table->tinyInteger('type')->default(0)->index()->comment('點數類型; 0:固定金額, 1:%數');
            $table->string('name', 100)->comment('獎項名稱');
            $table->integer('number')->comment('獎項數量');
            $table->tinyInteger('level')->default(0);
            $table->integer('distribute')->default(0)->comment('派發數量');
            $table->integer('sent')->nullable()->default(0)->comment('已送出');
            $table->decimal('achieve_point', 18, 4)->default(0)->comment('多獎勵達標金額');
            $table->integer('percentage')->comment('中獎機率');
            $table->integer('point_type')->default(1)->index()->comment('1:平台分數 2:禮碼');
            $table->integer('point')->default(0)->comment('平台點數');
            $table->integer('random_point_max')->nullable()->comment('隨機獎金上限');
            $table->decimal('point_percentage', 4)->default(0)->comment('%數');
            $table->bigInteger('flow_limit_multiple')->default(0)->comment('稽核流水倍數');
            $table->boolean('status')->default(false)->index()->comment('狀態0:停用1:使用');
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
        SchemaMonitor::dropIfExists('prize_contents');
    }
};
