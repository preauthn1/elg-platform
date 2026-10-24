<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateSmsSmsGetTable extends Migration
{

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('sms_sms_gets', function (Blueprint $table) {
            $table->increments('id')->comment('流水號');
            $table->integer('member_id')->comment('會員系統編號');
            $table->string('mobile')->comment('手機號碼');
            $table->string('text')->comment('簡訊內容');
            $table->string('stats')->comment('發送成功或失敗');
            $table->string('error_code')->comment('訊息代碼');
            $table->string('error_msg')->comment('成功:訊息ID|使用點數|剩餘點數；錯誤:錯誤說明');
            $table->timestamp('created_at')
                ->useCurrent()
                ->comment('建立日期');
            $table->timestamp('updated_at')
                ->default(DB::raw('CURRENT_TIMESTAMP on update CURRENT_TIMESTAMP'))
                ->comment('更新日期');
        });
    }
}