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
        SchemaMonitor::create('customer_proportions', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('agent_id')->comment('代理編號');
            $table->integer('type')->comment('類別 1: 總公司 2: 分公司');
            $table->decimal('proportions')->comment('公司占成');
            $table->string('vendor_fee', 500)->comment('公司盤費');
            $table->decimal('bonus')->comment('Bonus');
            $table->string('remark', 500)->comment('備註');
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->comment('更新日期');
            $table->integer('admin')->comment('更新者ID');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('customer_proportions');
    }
};
