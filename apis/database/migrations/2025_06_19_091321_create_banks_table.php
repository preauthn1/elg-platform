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
        SchemaMonitor::create('banks', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('agent_group_id')->default(0)->comment('代理群組編號');
            $table->tinyInteger('type')->default(1)->comment('類型; 1:銀行號碼, 2:手機號碼, 3:加密貨幣, 4:外幣銀行, 5:未知, 6:澳洲銀行, 7:澳洲PayId');
            $table->string('name', 50)->comment('銀行名稱');
            $table->string('code', 16)->comment('銀行代碼');
            $table->string('third_party_code', 20)->nullable()->comment('三方銀行代碼');
            $table->integer('currency_id')->nullable();
            $table->text('info')->nullable()->comment('相關資訊(json)');
            $table->decimal('rate', 14, 9)->default(1)->comment('匯率(2025/05/05提升至9位)');
            $table->tinyInteger('tax_type')->default(0)->comment('手續費種類');
            $table->decimal('tax', 11, 5)->default(0)->comment('手續費');
            $table->decimal('min_amount', 18, 4)->default(0);
            $table->decimal('max_amount', 18, 4)->default(0);
            $table->integer('sort')->default(0)->comment('排序');
            $table->string('image_url', 500)->nullable()->comment('銀行icon');
            $table->tinyInteger('status')->comment('狀態');
            $table->text('remark')->nullable();
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
        SchemaMonitor::dropIfExists('banks');
    }
};
