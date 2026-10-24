<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateTransactionLogAgent extends Migration
{

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('transaction_logs', function (Blueprint $table) {
            $table->integer('agent_id')
                ->after('transaction_no')
                ->nullable()
                ->default(0)
                ->comment('代理系統編號(同一個玩家可能由不同代理開洗分)');
        });

        Schema::create('transaction_log_agents', function (Blueprint $table) {
            $table->increments('id')->comment('流水號');
            $table->integer('from_agent_id')->comment('發送者的代理系統編號');
            $table->integer('to_agent_id')->comment('接收者的代理系統編號');
            $table->integer('type')->comment('類型1:開分，2:洗分');
            $table->decimal('amount')->comment('金額');
            $table->decimal('from_limit_of_credit')->comment('發送者剩餘額度');
            $table->decimal('from_used_credit')->comment('發送者已使用額度');
            $table->decimal('to_limit_of_credit')->comment('接收者剩餘額度');
            $table->decimal('to_used_credit')->comment('接收者已使用額度');
            $table->string('operator')->nullable()->comment('實際操作者(有可能是子帳號)');
            $table->string('remark')->nullable()->comment('備註');
            $table->tinyInteger('status')->default(0)->comment('狀態0:失敗，1:成功');
            $table->timestamp('created_at')
                ->useCurrent()
                ->comment('建立日期');
            $table->timestamp('updated_at')
                ->default(DB::raw('CURRENT_TIMESTAMP on update CURRENT_TIMESTAMP'))
                ->comment('更新日期');
        });
    }
}