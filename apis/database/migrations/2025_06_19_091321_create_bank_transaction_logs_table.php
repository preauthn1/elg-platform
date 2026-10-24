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
        SchemaMonitor::create('bank_transaction_logs', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('order_id')->default(0)->index()->comment('注单唯一Id, ThemeId');
            $table->integer('account_id')->index()->comment('bank_accounts.id');
            $table->tinyInteger('source')->default(0)->index()->comment('1.代理開分,2.代理洗分,3.自助上分,4.自助洗分');
            $table->integer('member_id')->index()->comment('會員系統編號');
            $table->decimal('point', 18, 4)->default(0)->comment('金额');
            $table->decimal('before_amount', 18, 4)->comment('改動前金额');
            $table->string('remark', 191)->nullable()->default(' ')->comment('備註');
            $table->string('operator', 191)->comment('實際操作者');
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->index()->comment('更新日期');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('bank_transaction_logs');
    }
};
