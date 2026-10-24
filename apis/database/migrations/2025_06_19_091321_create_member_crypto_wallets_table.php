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
        SchemaMonitor::create('member_crypto_wallets', function (Blueprint $table) {
            $table->increments('id')->comment('流水號');
            $table->integer('agent_cash_flow_order_type_id')->default(0)->index()->comment('遊戲商資訊編號');
            $table->integer('member_id')->default(0)->index()->comment('會員系統編號');
            $table->string('address', 191)->comment('錢包地址');
            $table->tinyInteger('status')->default(0)->index()->comment('狀態; 0:停用, 1:啟用');
            $table->decimal('balance', 10)->default(0)->comment('餘額');
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->comment('更新日期');
            $table->integer('admin')->comment('更新者ID');

            $table->unique(['agent_cash_flow_order_type_id', 'address'], 'unique_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('member_crypto_wallets');
    }
};
