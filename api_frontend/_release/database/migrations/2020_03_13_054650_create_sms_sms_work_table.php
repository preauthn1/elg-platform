<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateSmsSmsWorkTable extends Migration
{

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('sms_sms_works', function (Blueprint $table) {
            $table->increments('id')->comment('流水號');
            $table->integer('member_id')->comment('會員系統編號');
            $table->string('mobile')->comment('手機號碼');
            $table->string('text')->comment('簡訊內容');
            $table->string('status')->comment('發送成功或失敗,1:成功');
            $table->string('transation_id')->comment('交易編號');
            $table->string('response_message')->comment('成功:狀態|交易編號；錯誤:錯誤說明');
            $table->timestamp('created_at')
                ->useCurrent()
                ->comment('建立日期');
            $table->timestamp('updated_at')
                ->default(DB::raw('CURRENT_TIMESTAMP on update CURRENT_TIMESTAMP'))
                ->comment('更新日期');
        });
    }
}