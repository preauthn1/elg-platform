<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateOrdersTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        if (Schema::hasTable('orders')) {
            return;
        }
        Schema::create('orders', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->tinyInteger('type')->default(1)->comment('類型 1:充值, 2:提現, 3:歸集');
            $table->tinyInteger('status')->default(1)->comment('狀態 1:等待付款, 2:成功, 3:失敗');
            $table->unsignedInteger('merchant_id')->comment('商家ID');
            $table->unsignedInteger('currency_id')->comment('幣別ID');
            $table->string('order_no', 50)->comment('我方訂單編號');
            $table->string('merchant_order_no', 50)->comment('商家訂單編號');

            $table->unsignedInteger('wallet_id')->comment('錢包ID');
            $table->decimal('wallet_balance', 20, 9)->nullable()->comment('到帳時錢包餘額(參考用)');
            $table->bigInteger('lt')->nullable()->comment('錢包交易紀錄的 logical_time, 查詢鏈上交易紀錄時使用');
            $table->string('tx_hash', 191)->nullable()->comment('錢包交易紀錄的 block hash, 查詢鏈上交易紀錄時使用');
            $table->decimal('amount', 20, 9)->comment('金額');
            $table->decimal('fee', 20, 9)->nullable()->comment('手續費');

            $table->string('from_address', 191)->default('')->comment('來源錢包地址');
            $table->string('to_address', 191)->default('')->comment('目的錢包地址');

            $table->string('callback_url', 191)->default('')->comment('callback url');
            $table->string('return_url', 191)->default('')->comment('return url');
            $table->tinyInteger('is_callback_success')->default(0)->comment('是否已經callback成功');
            $table->tinyInteger('callback_count')->default(0)->comment('callback次數');
            $table->tinyInteger('query_count')->default(0)->comment('查詢交易紀錄次數');
            $table->tinyInteger('is_transfer_api_called')->default(0)->comment('是否已呼叫轉帳 api');
            $table->string('remark', 191)->default('')->comment('備註');
            $table->string('fail_reason', 191)->default('')->comment('失敗原因');

            $table->timestamp('transfer_api_called_at')->nullable()->comment('呼叫轉帳 api 時間');
            $table->timestamp('last_query_at')->nullable()->comment('最後查詢時間');
            $table->timestamp('last_callback_at')->nullable()->comment('最後callback時間');
            $table->timestamp('confirmed_at')->nullable()->comment('上鏈時間');
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->comment('更新日期');

            // 索引
            $table->unique(['merchant_id', 'merchant_order_no', 'type'], 'merchant_order_no_unique');
            $table->unique(['currency_id', 'tx_hash', 'lt'], 'tx_hash_unique');
            $table->unique('order_no', 'order_no_unique');
            $table->index('merchant_id');
            $table->index('currency_id');
            $table->index('wallet_id');
            $table->index('from_address');
            $table->index('to_address');
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('orders');
    }
}
