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
        SchemaMonitor::create('cash_flow_banks', function (Blueprint $table) {
            $table->bigIncrements('id');

            // 關聯三方金流商
            $table->unsignedBigInteger('cash_flow_id')
                ->comment('對應 cash_flows.id');

            // 幣別（可對應 currencies.id）
            $table->unsignedBigInteger('currency_id')
                ->comment('幣別 ID');

            // 平台標準銀行
            $table->unsignedBigInteger('bank_id')
                ->comment('對應 banks.id（平台銀行主檔）');

            $table->string('bank_code', 64)
                ->comment('我方銀行代碼，對應 banks.code');

            // 三方內部銀行代碼
            $table->string('provider_bank_code', 64)
                ->comment('第三方金流提供的銀行代碼');

            // 啟用狀態
            $table->tinyInteger('status')
                ->default(1)
                ->comment('狀態: 1=啟用, 0=停用');

            // 額外配置
            $table->text('info')
                ->nullable()
                ->comment('額外設定，如支行資訊等');

            $table->timestamps();

            // 避免同一三方+幣別+銀行重複建立
            $table->unique(
                ['cash_flow_id', 'currency_id', 'bank_id'],
            );

            // 索引
            $table->index(['cash_flow_id', 'currency_id']);
            $table->index('bank_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('cash_flow_banks');
    }
};
