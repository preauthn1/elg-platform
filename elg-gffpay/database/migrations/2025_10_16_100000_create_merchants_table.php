<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateMerchantsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        if (Schema::hasTable('merchants')) {
            return;
        }
        Schema::create('merchants', function (Blueprint $table) {
            $table->increments('id');                 // 主鍵
            $table->string('name')->comment('商家名稱');
            $table->string('code')->comment('商家代碼');
            $table->string('secret_key')->comment('Secret Key');
            $table->tinyInteger('status')->default(1)->comment('狀態 0:停用,1:啟用');
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->comment('更新日期');

            // 索引
            $table->unique('code');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('merchants');
    }
}
