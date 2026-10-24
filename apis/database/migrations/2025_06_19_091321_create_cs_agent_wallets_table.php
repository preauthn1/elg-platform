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
        SchemaMonitor::create('cs_agent_wallets', function (Blueprint $table) {
            $table->increments('id')->comment('流水號');
            $table->integer('agent_id')->default(0)->index()->comment('agent_id');
            $table->string('username', 50)->nullable();
            $table->decimal('limit_of_credit', 18, 4)->nullable()->default(0)->comment('開分/洗分額度');
            $table->decimal('used_credit', 18, 4)->nullable()->default(0)->comment('已使用額度');
            $table->tinyInteger('transaction_type')->default(1)->comment('開洗分模式1:一般2:紅包');
            $table->tinyInteger('withdraw_confirm')->nullable()->default(1)->comment('洗分是否需要同意，0:不需要、1:需要');
            $table->boolean('balance_limit')->default(true)->comment('金額限制; 0:無限,1:有限');
            $table->decimal('balance', 18, 4)->default(0)->comment('金額');
            $table->boolean('status')->default(false)->index()->comment('狀態; 0:禁用,1:使用中');
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->comment('更新日期');
            $table->integer('admin')->default(51)->comment('更新者ID');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('cs_agent_wallets');
    }
};
