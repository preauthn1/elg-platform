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
        SchemaMonitor::create('ptsw_deal_records', function (Blueprint $table) {
            $table->increments('id');
            $table->string('request_id', 200)->nullable()->unique()->comment('三方請求 ID');
            $table->string('username', 200)->nullable()->index()->comment('三方玩家帳號');
            $table->string('token')->nullable()->comment('token');
            $table->string('bet_id', 200)->nullable()->index()->comment('注單號碼');
            $table->string('transaction_code', 20)->nullable()->unique()->comment('三方交易代號');
            $table->string('type', 200)->nullable()->index()->comment('三方交易行為');
            $table->decimal('bet_amount', 18, 4)->default(0)->comment('投注金額');
            $table->decimal('win_amount', 18, 4)->default(0)->comment('派彩金額');
            $table->decimal('jackpot', 18, 4)->default(0)->comment('彩池中獎金額');
            $table->string('external_transaction_code', 50)->nullable()->comment('我司交易代號');
            $table->string('external_transaction_date', 50)->nullable()->comment('我司交易時間');
            $table->text('raw')->nullable()->comment('三方原始數據');
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('ptsw_deal_records');
    }
};
