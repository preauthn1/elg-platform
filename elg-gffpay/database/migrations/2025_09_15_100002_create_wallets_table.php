<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateWalletsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        if (Schema::hasTable('wallets')) {
            return;
        }
        Schema::create('wallets', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('sub_wallet_id')->comment('子錢包ID');
            $table->string('address', 191)->comment('錢包地址');
            $table->decimal('balance', 20, 9)->default(0)->comment('錢包餘額 (參考用)');

            $table->tinyInteger('deployment_status')->default(0)->comment('部屬狀態 0:尚未部屬, 1:部屬中, 2:已部屬');
            $table->tinyInteger('is_enabled')->default(1)->comment('啟用狀態 0:停用, 1:啟用');
            $table->tinyInteger('status')->default(1)->comment('流程狀態 1:閒置, 2:等待付款, 3:冷卻中');

            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->comment('更新日期');

            // 索引
            $table->unique('sub_wallet_id');
            $table->unique('address');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('wallets');
    }
}
