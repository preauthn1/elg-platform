<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateTransactionAgentTable extends Migration
{

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('transaction_agents', function (Blueprint $table) {
            $table->increments('id')->comment('流水號');
            $table->integer('from')->comment('發送者User系統編號');
            $table->integer('to')->comment('接收者User系統編號');
            $table->integer('type')->comment('類型1:開分，2:洗分');
            $table->integer('amount')->comment('金額');
            $table->text('remark')
                ->nullable()
                ->comment('備註');
            $table->boolean('status')
                ->default(0)
                ->comment('狀態0:失敗,1:成功');
            $table->timestamp('created_at')
                ->useCurrent()
                ->comment('建立日期');
            $table->timestamp('updated_at')
                ->default(DB::raw('CURRENT_TIMESTAMP on update CURRENT_TIMESTAMP'))
                ->comment('更新日期');
        });
    }
}