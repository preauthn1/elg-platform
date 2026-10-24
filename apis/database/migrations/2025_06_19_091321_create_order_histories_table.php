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
        SchemaMonitor::create('order_histories', function (Blueprint $table) {
            $table->increments('id')->comment('系統編號');
            $table->string('order_no', 32)->unique()->comment('訂單編號');
            $table->string('third_party_order_no', 191)->index()->comment('三方金流訂單編號');
            $table->integer('agent_cash_flow_id')->default(0)->index()->comment('金流商戶資料編號');
            $table->integer('cash_flow_info_id')->default(0)->comment('cash_flow_infos.id');
            $table->integer('cash_flow_id')->nullable()->comment('支付商ID; cash_flows.id');
            $table->string('third_party_id', 100)->default('')->comment('支付商訂單號');
            $table->integer('order_type_id')->comment('訂單類別(FK_OrderType)');
            $table->integer('currency_id')->comment('貨幣類別(FK_Currency)');
            $table->integer('agent_id')->default(0)->index()->comment('withdrawal_order_agents.id');
            $table->integer('member_id')->index()->comment('帳號系統編號(User)');
            $table->decimal('amount', 18, 4)->comment('金額');
            $table->decimal('rate', 12, 6)->default(1)->comment('當下匯率轉換');
            $table->decimal('point', 18, 4)->comment('點數');
            $table->decimal('balance', 18, 4)->comment('儲值後餘額');
            $table->string('ip', 50)->default('')->comment('ip address');
            $table->string('callback', 191)->nullable()->comment('回調網址');
            $table->smallInteger('status')->default(0)->index()->comment('狀態 0:建立,1:成功,2:失敗,3:己上分,4:待手動確認上分');
            $table->text('extra_content')->nullable()->comment('訂單額外內容');
            $table->text('remark')->comment('備註');
            $table->dateTime('pay_time')->nullable()->index()->comment('支付時間');
            $table->timestamp('created_at')->useCurrent()->index()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->comment('更新日期');
            $table->integer('admin')->comment('更新者ID');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('order_histories');
    }
};
