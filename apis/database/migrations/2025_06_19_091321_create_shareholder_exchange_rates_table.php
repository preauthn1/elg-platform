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
        SchemaMonitor::create('shareholder_exchange_rates', function (Blueprint $table) {
            $table->comment('份桶匯率, 將平台幣轉為USD(共通貨幣)的匯率');
            $table->increments('id');
            $table->unsignedInteger('organization_id')->default(0)->unique()->comment('組織ID');
            $table->decimal('exchange_rate_to_USD', 18, 6)->unsigned()->default(0)->comment('平台幣轉為USD(共通貨幣)的匯率');
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->comment('更新日期');
            $table->unsignedInteger('admin')->comment('更新者ID');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('shareholder_exchange_rates');
    }
};
