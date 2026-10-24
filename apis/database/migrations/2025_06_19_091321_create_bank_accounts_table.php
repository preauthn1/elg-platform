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
        SchemaMonitor::create('bank_accounts', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('order_type_id')->index()->comment('銀行id');
            $table->string('account_name', 50)->comment('帳戶名稱');
            $table->string('account_nickname', 50)->nullable()->comment('帳戶簡稱');
            $table->string('account_no', 191)->default('')->comment('銀行帳號');
            $table->tinyInteger('is_deposit')->default(0)->index()->comment('是否可以開分');
            $table->tinyInteger('is_withdrawal')->default(0)->index()->comment('是否可以洗分');
            $table->decimal('rate', 10, 4)->default(1)->comment('匯率');
            $table->decimal('principal', 18, 4)->default(0)->comment('銀行本金');
            $table->decimal('amount', 18, 4)->default(0)->comment('銀行金額');
            $table->decimal('min_amount', 18, 4)->comment('帳戶名稱');
            $table->decimal('max_amount', 18, 4)->comment('帳戶名稱');
            $table->integer('use_times')->comment('使用次數');
            $table->tinyInteger('status')->default(1)->index()->comment('狀態');
            $table->decimal('amount_threshold', 18, 4)->default(0)->comment('通知金額門檻');
            $table->integer('count_threshold')->default(0)->comment('通知次數門檻');
            $table->timestamp('last_activated_at')->nullable()->comment('最後啟用時間');
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->comment('更新日期');
            $table->integer('admin')->default(0);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('bank_accounts');
    }
};
