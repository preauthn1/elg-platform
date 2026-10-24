<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateTableUserOperateLogs extends Migration
{  
    
    protected $table_name = "agent_operate_logs";

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        $table_name = $this->table_name;
        if (!Schema::hasTable($table_name)) {
            
            Schema::create($table_name, function (Blueprint $table) {
                $table->increments('id')->commit('PK流水號');
                $table->integer('agent_id')->commit('代理號');
                $table->string('type' , 50)->commit('操作類型');
                $table->string('reference_id', 50)->commit('目標資料ID(可能是代理號...etc)');
                $table->string('debug_info')->commit('其他紀錄資訊(e.g. json)');
                $table->string('ip' , 100)->commit('用戶ip');
                $table->timestamp('created_at')
                    ->useCurrent()
                    ->comment('建立日期');
                $table->timestamp('updated_at')
                    ->default(DB::raw('CURRENT_TIMESTAMP on update CURRENT_TIMESTAMP'))
                    ->comment('更新日期');
            });

            DB::statement("ALTER TABLE `$this->table_name` comment '代理後台各種操作紀錄(e.g. 改密碼、修改帳號...)'");
        }

    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        //
    }
}