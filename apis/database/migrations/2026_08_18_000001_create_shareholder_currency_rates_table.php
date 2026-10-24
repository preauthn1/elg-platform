<?php

declare(strict_types=1);

use App\Facades\SchemaMonitor;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('shareholder_currency_rates')) {
            return;
        }

        SchemaMonitor::create('shareholder_currency_rates', function (Blueprint $table) {
            $table->comment('稱桶幣別匯率');
            $table->increments('id');
            $table->unsignedInteger('currency_id')->comment('currencies.id');
            $table->decimal('exchange_rate_to_usd', 18, 8)->comment('1本幣對1USD的匯率');
            $table->unsignedInteger('admin')->default(0);
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent();

            $table->unique('currency_id', 'uq_currency_id');
        });
    }

    public function down(): void
    {
        SchemaMonitor::dropIfExists('shareholder_currency_rates');
    }
};
