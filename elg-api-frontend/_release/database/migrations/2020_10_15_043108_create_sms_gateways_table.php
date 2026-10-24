<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateSmsGatewaysTable extends Migration
{
    protected $table_name = 'sms_gateways';

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        if (!Schema::hasTable($this->table_name)){
            Schema::create($this->table_name, function (Blueprint $table) {
                $table->increments('id')->comment('流水號');
                $table->string('name', 45)->comment('簡訊商名稱');
                $table->string('code', 10)->comment('簡訊商代碼');
                $table->string('info', 200)->default('{}')->comment('簡訊商輸入資訊');
                $table->string('url', 200)->comment('簡訊商 API URL');
                $table->string('memo', 200)->comment('備註');
                $table->integer('status')->default(0)->comment('狀態; 0:停用, 1:啟用');
                $table->timestamp('created_at')
                    ->useCurrent()
                    ->comment('建立日期');
                $table->timestamp('updated_at')
                    ->default(DB::raw('CURRENT_TIMESTAMP on update CURRENT_TIMESTAMP'))
                    ->comment('更新日期');
                $table->integer('admin')->default(0)->commit('更新者ID');
            });
        }
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists($this->table_name);
    }
}
