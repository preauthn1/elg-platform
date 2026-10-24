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
        SchemaMonitor::create('withdrawal_payout_records', function (Blueprint $table) {
            $table->increments('id')->comment('流水號');
            $table->integer('cash_flow_id')->default(0)->comment('cash_flow_id_支付廠商ID(每家會有固定編號)');
            $table->integer('agent_cash_flow_id')->default(0)->comment('agent_cash_flows.id');
            $table->integer('withdrawal_order_id')->index()->comment('提款單ID');
            $table->string('transaction_no', 200)->comment('我方系統單號');
            $table->string('third_party_transaction_no', 200)->index()->comment('三方系統單號');
            $table->decimal('result_amount', 18, 4)->comment('三方回應的金額');
            $table->string('result_code', 50)->comment('三方回應的交易狀態');
            $table->integer('is_success_payout')->default(0)->index()->comment('是否定義為成功單');
            $table->string('transaction_time', 50)->comment('三方回應的交易狀態');
            $table->string('sign', 200)->comment('加密簽名');
            $table->text('payout_order_request_body')->comment('代付請求_request資訊');
            $table->text('payout_order_response')->comment('代付請求_response資訊');
            $table->text('callback_request_body')->comment('原始request');
            $table->string('info_1', 200)->comment('額外資訊1');
            $table->string('info_2', 200)->comment('額外資訊2');
            $table->string('info_3', 200)->comment('額外資訊3');
            $table->dateTime('callback_time')->nullable()->index()->comment('回調時間');
            $table->timestamp('created_at')->useCurrent()->index()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->comment('更新日期');
            $table->integer('admin')->default(0);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('withdrawal_payout_records');
    }
};
