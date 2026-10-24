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
        SchemaMonitor::create('agent_wallets', function (Blueprint $table) {
            $table->comment('代理支援錢包種類');
            $table->increments('id')->comment('系統編號');
            $table->integer('agent_id')->default(0)->index()->comment('agents.id');
            $table->integer('wallet_type_id')->index()->comment('錢包幣型系統編號(wallet_types)');
            $table->decimal('balance', 18, 4)->default(0)->comment('錢包金額');
            $table->boolean('is_unlimited')->default(false)->comment('錢包金額是否為無線(0:否,1:是)');
            $table->tinyInteger('status')->default(0)->comment('狀態 0:關閉,1:啟用');
            $table->string('memo', 300)->nullable()->comment('備註');
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->comment('更新日期');
            $table->integer('admin')->comment('更新者ID');

            $table->unique(['agent_id', 'wallet_type_id'], 'unique_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('agent_wallets');
    }
};
